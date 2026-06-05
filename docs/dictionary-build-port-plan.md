# 辞書生成部移植プラン

## 目的

この計画は、Java 版 Igo の `net.reduls.igo.bin.BuildDic` 相当の辞書生成機能を、Igo Modern の PHP 実装へ移植するための方針を記録します。

現在の Igo Modern は、生成済みの Igo 形式辞書を読み込んで解析する実装を `src/` 配下に持っています。一方で、MeCab 互換辞書から Igo 形式辞書を生成する機能は提供していません。辞書生成部を PHP 側へ移植することで、利用者が Java 版 Igo の jar に依存せず、PHP プロジェクト内で辞書準備を完結できる状態を目指します。

この文書は、完了済みの解析器移行方針である `docs/migration-policy.md` とは別に、辞書生成機能追加の実装計画として扱います。

## 移植範囲

初期リリースでは、Java 版 `BuildDic` と同等の入力・出力を持つ辞書生成機能を実装します。

CLI は既存の `bin/igo` と Symfony Console アプリケーションへ、次の形式のコマンドとして追加します。

```bash
bin/igo build-dic -o <output directory> -i <input directory> -e <encoding> [-d delimiter]
```

`delimiter` の既定値は Java 版と同じ `,` とします。入力ディレクトリには MeCab 互換辞書の `*.csv`、`unk.def`、`matrix.def`、`char.def` が存在することを前提にします。

生成する辞書ファイルは、既存の runtime 実装が読み込む次のファイル群です。

```text
word2id
word.inf
word.dat
word.ary.idx
matrix.bin
char.category
code2category
```

生成順序は Java 版と同じく、まず `word2id` を構築し、その trie ID を利用して単語情報と文字カテゴリを生成します。

1. `word2id`
2. `word.inf`、`word.dat`、`word.ary.idx`
3. `matrix.bin`
4. `char.category`、`code2category`

## 設計方針

実装は `IgoModern\Dictionary\Build` 配下へ追加します。主要な責務は次のクラスへ分割します。

- `DictionaryBuilder`: 全体の生成順序を制御する公開 API。
- `WordDictionaryBuilder`: `unk.def` と `*.csv` から `word2id`、`word.inf`、`word.dat`、`word.ary.idx` を生成する。
- `MatrixBuilder`: `matrix.def` から `matrix.bin` を生成する。
- `CharCategoryBuilder`: `char.def` から `char.category` と `code2category` を生成する。
- trie builder: 表層形キーから既存 `IgoModern\Dictionary\Trie\Searcher` が読める double-array trie を生成する。
- binary writer: 既存 reader の native-endian バイナリ契約に合わせて int、short、char、UTF-16 相当バイト列を書き出す。

設計は DI を前提にし、テストで reader、writer、ファイル列挙、各 builder を差し替えられるようにします。コンストラクタでは依存関係と immutable な設定値の保持だけを行い、I/O、ビルド処理、重い計算は実行しません。

標準構成の組み立てが必要な場合は、`DictionaryBuilder::standard()` のような static factory に閉じ込めます。CLI はこの static factory を使って通常依存を作れますが、テストでは command に builder factory や builder インスタンスを注入して副作用を分離します。

生成バイナリは Java 版との byte-for-byte 一致を必須にしません。成功条件は、PHP で生成した辞書を既存の `WordDic`、`Matrix`、`CharCategory`、`Searcher`、`Igo` が読み込み、期待する解析結果を返すことです。ただし、ファイル名、レコード構造、native-endian の読み書き、UTF-16 code unit の扱いは既存 runtime の契約に合わせます。

## 実装詳細

`WordDictionaryBuilder` は、未知語定義 `unk.def` と通常単語辞書 `*.csv` から表層形キーを集めます。未知語カテゴリキーには Java 版と同じ `"\002"` prefix を付け、`char.def` のカテゴリ名から trie ID を引けるようにします。

単語情報では、各行の表層形、左文脈 ID、右文脈 ID、コスト、素性データを読み取ります。素性データは辞書エンコーディングから native UTF-16 相当のバイト列へ変換して `word.dat` に連結し、`word.inf` には data offset、left id、right id、cost の配列を出力します。

`MatrixBuilder` は `matrix.def` を UTF-8 として読み込みます。先頭行の左文脈数・右文脈数をヘッダとして出力し、コスト表は既存 `Matrix::linkCost()` と同じく `matrix[rightId * leftSize + leftId]` の順序で平坦化します。

`CharCategoryBuilder` は `char.def` のカテゴリ定義とコード範囲定義を読み込みます。`DEFAULT` と `SPACE` は必須カテゴリとし、`0x0020` は `SPACE` に割り当てられている必要があります。UCS2 範囲 `0x0000..0xFFFF` の各 code unit に対して、カテゴリ ID と互換性マスクを出力します。

trie builder は、既存 `Searcher` が読む `word2id` 形式を出力します。内部の double-array 配置は Java 版と完全一致させる必要はありませんが、共通接頭辞検索、tail 圧縮ノード、終端ノード、ID の負数エンコード規則は既存 `Searcher` と互換にします。

## TDD とテスト方針

実装変更は Red、Green、Refactor の順で進めます。最初に小型 MeCab 形式 fixture を作成し、その fixture から辞書生成した結果を既存 runtime で読めることを failing test として固定します。

主なテスト観点は次のとおりです。

- `DictionaryBuilder` が正しい順序で各 builder を呼び出す。
- `BuildDicCommand` が CLI オプションを解釈し、注入された builder へ生成処理を委譲する。
- 各 builder のコンストラクタでは副作用が起きず、`build()` 呼び出し時だけ I/O が発生する。
- 小型 fixture から生成された辞書ファイル群を `WordDic`、`Matrix`、`CharCategory`、`Searcher` が読める。
- 小型 fixture から生成された辞書を使い、`Igo` が通常語と未知語を期待どおり解析できる。
- `matrix.def` の文脈 ID 順序不一致、`char.def` の必須カテゴリ欠落、単語 CSV の不正な区切りなどを parse error として扱う。

ドキュメント追加のみの変更では、TDD の Red/Green は適用しません。実装フェーズに入る場合は、この節の方針に従って必ず failing test から開始します。

## 検証

実装完了後は、通常の検証ループを実行します。

```bash
composer test
composer analyze
composer lint
composer format
```

Composer の autoload 設定を変更した場合は、次のコマンドも実行します。

```bash
composer dump-autoload
```

CI では小型 fixture を中心に検証します。IPA 辞書や NEologd のような大規模辞書は、必要に応じて手動確認手順を README または別ドキュメントに追記します。

## コメント規約

追加するすべてのテスト、関数、クラスメソッドには、その処理の目的を説明する簡潔なコメントを付けます。

コメントは意図、不変条件、分かりにくい挙動の説明に集中させます。コードをそのまま言い換えるだけのコメントは避けます。

## 参考元

- Java 版 Igo: https://github.com/sile/igo
- `igo/src/net/reduls/igo/bin/BuildDic.java`
- `igo/src/net/reduls/igo/dictionary/build/WordDic.java`
- `igo/src/net/reduls/igo/dictionary/build/Matrix.java`
- `igo/src/net/reduls/igo/dictionary/build/CharCategory.java`
- `igo/src/net/reduls/igo/trie/Builder.java`
- `igo/src/net/reduls/igo/trie/Allocator.java`
- `igo/src/net/reduls/igo/trie/ShrinkTail.java`
- `igo/src/net/reduls/igo/util/FileMappedOutputStream.java`
