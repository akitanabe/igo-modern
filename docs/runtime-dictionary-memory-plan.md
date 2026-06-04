# 実行時辞書メモリ削減計画

## 目的

検索処理で Igo Modern を利用する場合、解析器そのものの処理時間は実用圏内に見える一方で、辞書ロード後の PHP プロセスごとのメモリ使用量が集中時の制約になりやすいです。

この文書は、実行時辞書を PHP ヒープへ載せすぎないための実装方針を整理します。実装では、改善につながるものは通常経路へ取り込み、速度とメモリのトレードオフを生むものは `RuntimeOptions` で切り替え可能にします。

## 現状

生成済み IPA 辞書相当の `dist/igo-dic` では、ベンチマーク実行時の peak memory はおよそ 81 MiB でした。

現在の runtime reader は、すべての辞書データを PHP 配列へ展開しているわけではありません。`FileMappedInputStream` は `reduce=true` の場合、`word.inf` や `word2id` 内の大きな数値配列を `IntDynamicArray`、`ShortDynamicArray`、`CharDynamicArray` として構築し、必要な添字だけ `fseek()` と `fread()` で読みます。

一方で、次のデータはまだ常駐メモリへ載せています。

- `word.dat`: `WordDic` が素性データ全体を UTF-16 相当の binary string として保持する。
- `word.ary.idx`: `WordDic` が trie ID から単語 ID 範囲を引く int 配列を PHP array として保持する。
- `word2id` の tail: `Searcher` が tail 圧縮された suffix を UTF-16 code unit の PHP array として保持する。

ファイルサイズが小さく見えるデータでも、PHP の `array<int>` へ展開すると実メモリ使用量は大きくなります。検索リクエストが集中し、PHP-FPM worker ごとに辞書を保持する構成では、この部分が同時実行数の上限に影響します。

## 方針

最初の優先案は、SQLite 化ではなく、既存の辞書バイナリ形式を保ったまま file-backed / stream reader を強化することです。

理由は次のとおりです。

- 既存の `IntDynamicArray` 系と設計上の接続がよい。
- 辞書生成形式を大きく変えずに段階的に導入できる。
- OS のページキャッシュに辞書実体を寄せられ、複数 PHP プロセス間で実ファイルのキャッシュ共有を期待できる。
- SQLite の SQL 実行回数増加による遅延リスクを避けやすい。

ただし、SQLite を完全に除外するわけではありません。辞書を 1 ファイルにまとめたい、メタ情報やバージョンを同梱したい、SQLite の page cache や mmap を使いたい、といった運用目的が明確な場合は候補になります。

実装判断は次の基準で行います。

- メモリ削減になり、`parse()` の mean、median、p95 が現行比 10% 以内の劣化に収まる変更は通常経路に採用する。
- 10% を超える速度劣化がある変更、または利用用途によって速度優先とメモリ優先が分かれる変更は `RuntimeOptions` で切り替える。
- 解析結果、辞書ファイル形式、既存の `parse()` / `wakati()` の戻り値は変更しない。

### RuntimeOptions

トレードオフを持つ runtime reader は、公開設定オブジェクト `RuntimeOptions` で切り替えます。

想定 API は次の形です。

```php
use IgoModern\Igo;
use IgoModern\RuntimeOptions;

$igo = new Igo('dist/igo-dic', 'UTF-8', RuntimeOptions::preferMemory());
```

公開 API の想定変更は次のとおりです。

- `Igo::__construct(string $dataDir, ?string $outputEncoding = null, ?RuntimeOptions $runtimeOptions = null)`
- `Tagger::__construct(string $dataDir, ?string $outputEncoding = null, ?RuntimeOptions $runtimeOptions = null)`
- `RuntimeOptions::defaults()`
- `RuntimeOptions::preferSpeed()`
- `RuntimeOptions::preferMemory()`

既存呼び出しは第 3 引数省略で動くため、利用者コードの変更は不要です。PHP 8.0 対応を維持するため enum は使わず、内部状態は private property と問い合わせメソッドで表します。

## 実装候補

### 1. `word.dat` の stream 化

`WordDic` は現在、`FileMappedInputStream::getStringFromFile($dataDir . '/word.dat')` によって素性データ全体を保持します。

これを `WordDataReader` のような file-backed reader に置き換えます。`wordData($wordId)` では `dataOffsets` から UTF-16 code unit の開始位置と終了位置を取り、必要範囲だけファイルから読みます。

```text
wordId
  -> dataOffsets[wordId], dataOffsets[wordId + 1]
  -> byte offset と byte length に変換
  -> word.dat から該当 slice だけ読む
```

期待効果は、`word.dat` 約 26 MiB の常駐 string を削れることです。検索用途では解析結果から常にすべての素性を使うとは限らないため、将来的には検索用 tokenizer が表層形・原形・品詞など必要な情報だけ読む設計にもつなげられます。

注意点として、`wordData()` は解析結果の `Morpheme` 生成時に呼ばれるため、短い slice 読み込みが増えます。単純な `fseek()` / `fread()` だけで十分か、ページキャッシュ付き reader が必要かをベンチマークで確認します。

実装では、まず `WordDataReader` の単体テストで UTF-16 code unit offset から必要 slice だけ読む挙動を固定します。その後 `WordDic` の `private string $data` を reader に置き換え、`wordData()` は `dataOffsets` から算出した範囲を reader に委譲します。

この変更は探索ループの内側ではなく、`parse()` の最終結果生成時に効くため、最初に実装する候補とします。速度劣化が 10% 以内なら通常経路に採用し、10% を超える場合は `RuntimeOptions` で memory profile は stream、speed profile は従来の full string を使う構成にします。

### 2. ページキャッシュ付き binary reader

現在の `IntDynamicArray` は、1 回の `get()` ごとに目的位置へ seek し、2 または 4 バイトだけ読み、`unpack()` します。メモリ削減には有効ですが、解析中のランダムアクセス回数が多い場合は syscall と unpack のコストが増えます。

共通基盤として、固定サイズのページ単位でファイルを読む `PagedBinaryReader` を検討します。

```text
readInt(byteOffset)
  -> byteOffset が属するページ番号を計算
  -> ページが未キャッシュなら 8 KiB または 64 KiB 程度を読む
  -> ページ内 offset から値を unpack する
```

初期実装では、小さな固定数の LRU キャッシュか、直近ページだけの簡易キャッシュから始めます。解析時のアクセス局所性が低い場合、キャッシュサイズを増やしても効果が薄い可能性があるため、実測で判断します。

### 3. `word.ary.idx` の dynamic array 化

`word.ary.idx` は trie ID から単語 ID の開始・終了範囲を引くために使います。現在は `list<int>` として保持していますが、アクセス形は次の 2 点参照です。

```text
start = indices[trieId]
end = indices[trieId + 1]
```

この形は `IntDynamicArray` またはページキャッシュ付き `IntPagedArray` に置き換えやすいです。

ファイルサイズは `word.dat` より小さいものの、PHP array のメモリ効率を考えると削減効果はあります。`WordDic::callWordRange()` の呼び出し頻度が高いため、速度影響を必ず測定します。

実装では `WordDic::$indices` を `list<int>` から `IntArray` に変更し、`callWordRange()` は `get($trieId)` と `get($trieId + 1)` で範囲を読みます。速度劣化が 10% 以内なら通常経路に採用し、10% を超える場合は `RuntimeOptions` で memory profile は dynamic array、speed profile は従来の PHP array を使います。

### 4. `word2id` tail の stream 化

`Searcher` は tail 圧縮された suffix を `list<int>` として保持し、`KeyStream::startsWith()` で入力の続きと比較します。

ここも file-backed 化できますが、`tail` は文字単位比較の内側で使われるため、`word.dat` や `word.ary.idx` より慎重に進めます。

候補は次のいずれかです。

- tail の binary string は保持し、PHP array 化だけをやめる。
- tail 全体を file-backed reader で持ち、比較対象範囲だけページ単位で読む。
- tail 長が短い語ではまとめ読みし、長い語だけ streaming 比較する。

まずは PHP array 化を避けるだけでもメモリ削減が期待できます。完全 stream 化は、比較ループのコストをベンチマークで確認してから判断します。

tail 比較は trie 探索の内側にあるため、`word.dat` や `word.ary.idx` より慎重に進めます。初期案では、tail を巨大な `list<int>` へ展開せず、UTF-16 binary string または `CharArray` reader として保持します。`KeyStream::startsWith()` は compact tail を比較できるように拡張します。

速度劣化が 10% 以内なら通常経路に採用します。10% を超える場合は、`RuntimeOptions` で memory profile は compact tail、speed profile は従来の PHP array tail を使えるようにします。

### 5. SQLite 辞書コンテナ

SQLite を外部メモリ的に使う案も検討対象です。ただし、double-array trie の 1 遷移ごとに SQL を発行する構造は避けます。

避けたい形:

```sql
SELECT base, chck FROM trie_nodes WHERE node_id = ?;
```

この形では解析中の SQL 呼び出し回数が多くなり、PHP 関数呼び出し、PDO、SQLite VM のオーバーヘッドが積み上がる可能性があります。

SQLite を採用する場合は、次のような用途に寄せます。

- 辞書ファイル群を 1 つの SQLite ファイルへまとめる。
- `word.dat` や tail を BLOB として保持し、必要範囲を読む。
- 辞書バージョン、生成元、エンコーディング、フォーマット互換性などの metadata を持つ。
- SQLite の page cache または mmap を利用して、OS キャッシュと組み合わせる。

SQLite 化は、file-backed reader の効果と限界を確認した後の第 2 段階として扱います。

## 測定方針

変更ごとに、速度とメモリの両方を測ります。片方だけでは判断しません。

最低限、次の条件を比較します。

- 現行実装
- `word.dat` stream 化
- `word.ary.idx` dynamic array 化
- tail の array 回避
- ページキャッシュあり / なし

測定項目は次のとおりです。

- `parse()` の mean、median、p95
- chars/sec、morphemes/sec
- peak memory
- 辞書ロード直後の current memory と peak memory
- 同一プロセス内での連続実行時の安定性
- 可能であれば PHP-FPM worker 相当の複数プロセス起動時の総 RSS

ベンチマークは Xdebug を無効にして実行します。

```bash
php -d xdebug.mode=off bin/bench parse dist/igo-dic --sample=mixed
php -d xdebug.mode=off bin/bench parse dist/igo-dic --file=bench/corpus/search.txt --output=bench/results/search-{datetime}.txt --morpheme-output=bench/results/search-{datetime}.morphemes.txt
```

## 想定される導入順

1. `word.dat` を `WordDataReader` へ置き換え、メモリ削減量と速度低下を測る。
2. `word.ary.idx` を `IntDynamicArray` または `IntPagedArray` へ置き換える。
3. `word2id` tail の PHP array 化を避ける。
4. 必要に応じて `PagedBinaryReader` を導入し、既存 dynamic array の syscall 回数を減らす。
5. 必要に応じて SQLite 辞書コンテナを試作する。

この順序は、変更範囲が小さく効果を測りやすいものから進めるためのものです。実装段階では、各ステップで Red、Green、Refactor の流れを取り、既存の解析結果互換性を壊さないことを先にテストで固定します。

`PagedBinaryReader` は単体のメモリ削減策ではなく、file-backed 化による速度低下が見えた場合の速度対策として扱います。既定ページサイズは初期案として 8 KiB、キャッシュは直近 1 ページから始めます。ページサイズやキャッシュ量が用途依存になる場合は `RuntimeOptions` の設定対象にします。

## リスク

- file-backed 化により PHP ヒープは減るが、ランダムアクセス I/O と `unpack()` の回数が増えて解析速度が落ちる可能性がある。
- ページキャッシュを大きくしすぎると、結局 PHP ヒープへ辞書を戻す形になる。
- SQLite を細粒度アクセスに使うと、SQL 実行オーバーヘッドで解析速度が大きく落ちる可能性がある。
- OS や filesystem、PHP ビルド、opcache、Xdebug の有無で測定結果が変わる。
- `wordData()` の遅延読み込みは、解析後に全 morpheme の feature を必ず読む使い方では効果が小さくなる可能性がある。
- `RuntimeOptions` を細かくしすぎると公開 API が複雑になるため、まずは `defaults()`、`preferSpeed()`、`preferMemory()` の named constructor を中心にする。

## 未決事項

- 検索用途では `Morpheme::feature` 全体が必要か、表層形・原形・品詞などの検索 token 生成に必要な部分だけでよいか。
- `RuntimeOptions` の内部設定をどこまで細分化するか。
- `FileMappedInputStream` の `reduce` オプションを `RuntimeOptions` の配下で公開設定として扱うか。
- ページサイズの既定値を 8 KiB から変更する必要があるか。
- SQLite を採用する場合、PDO と ext-sqlite3 のどちらを reader の基盤にするか。
