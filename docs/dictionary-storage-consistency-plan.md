# 辞書ストレージ抽象の整合性リファクタ計画

## ステータス

未着手（計画のみ）。直前の「[辞書ストレージ抽象化](dictionary-backend-abstraction-plan.md)」（コミット 2e6ebe2）で導入した `DictionaryStorage` 抽象の境界が曖昧なまま残った点を整理し、整合性を高める後続リファクタ。挙動は不変。

## 背景

`DictionaryStorage` 抽象を入れたが、次の3点で境界がぼやけている。

1. **`bool $reduce` の漏れ** — 「遅延読み(DynamicArray) か 常駐(MemoryArray) か」を表す `bool $reduce`（既定値 `= true`）が `BinaryStorage` → `Binary*` / `CharCategory` → `Searcher` / `FileMappedInputStream` の全層を貫通し、意味の不明瞭な magic bool が各所に散在している。
2. **公開構築点の重複** — Storage が「ディレクトリから辞書一式を組む」責務を持つはずなのに、`Binary*::fromDataDir`・`CharCategory::fromDataDir`・`Tagger::fromDataDir`・`Igo::fromDictDir` といった並行の構築入口が残り、Storage をバイパスして辞書を組める。
3. **Storage の置き場所** — 公開 API である Storage 実装が `IgoModern\Dictionary\Storage`（内部 namespace の奥）に埋もれている。

確認済みの方針:

- 提案1 = 両モード維持・`bool` を型へ置換（`FileStorage` / `MemoryStorage` は残す）
- 提案2 = 最も徹底。高水準の構築も必ず Storage 経由に統一（`Igo::fromDictDir` も見直す）
- 提案3 = Storage をトップレベル namespace へ移動

## 設計

### 1. `bool $reduce` を enum へ置換

新規 enum `IgoModern\Binary\ArrayMaterialization`（`src/Binary/ArrayMaterialization.php`）を導入する。

- `Lazy` … 旧 `reduce=true`（`DynamicArray`、ファイル遅延読み）
- `Resident` … 旧 `reduce=false`（`MemoryArray`、常駐）
- enum 本体と各 case に意図を記すコメントを付す。

`bool $reduce` → `ArrayMaterialization $materialization` の置換箇所:

- `src/Binary/FileMappedInputStream.php` — プロパティ・`fromFile`・`getIntArrayInstance` / `getShortArrayInstance` / `getCharArrayInstance` の分岐を `=== ArrayMaterialization::Lazy` に。eager 全読みヘルパ（`getIntArrayFromFile` / `getStringFromFile` / `_*`）は materialization 非依存なので既定 `= ArrayMaterialization::Lazy` を残す。
- `src/Dictionary/Trie/Searcher.php` `fromFile`
- `src/Dictionary/Binary/BinaryWordDictionary.php` `fromDataDir` および `readIndices`
- `src/Dictionary/Binary/BinaryConnectionMatrix.php` `fromDataDir`
- `src/Dictionary/Binary/BinaryUnknownWordDictionary.php` `fromDataDir`
- `src/Dictionary/CharCategory.php` `fromDataDir`

これら「葉」のファクトリは単体テストが直接構築に使うため、既定値 `= ArrayMaterialization::Lazy` を残す。Storage は常に明示的に case を渡す。各メソッドの `$reduce は…` コメントを enum 説明に更新する。

### 2. 公開構築点を Storage に一本化

PHP に package-private が無く、辞書層ファクトリは各層の単体テスト（WordDicTest, MatrixTest, CharCategoryTest, SearcherTest, UnknownTest 等）が直接構築に使うため、完全削除はできない。よって:

- 辞書層ファクトリ（`Binary*::fromDataDir`・`CharCategory::fromDataDir`・`Searcher::fromFile`・`FileMappedInputStream::fromFile`）に `@internal` を付け、「公開構築点は Storage のみ」を明示する。enum 既定値により既存呼び出しは維持。
- **`Tagger::fromDataDir` を削除**（`src/Analysis/Tagger.php`）。Tagger は `fromStorage` + コンストラクタのみにし、`use FileStorage` を除去。
- **`Igo::fromDictDir` / `Igo::tryFromDictDir` を削除**（`src/Igo.php`）。`Igo::fromStorage` を唯一の構築入口とし、文字列ディレクトリからの構築は呼び出し側が `FileStorage::fromDataDir(...)` を明示して渡す。
- 影響する本体呼び出し側を更新:
  - `src/Console/ParseCommand.php` → `Igo::fromStorage(FileStorage::fromDataDir($dataDir), $outputEncoding)`
  - `src/Benchmark/ParseBenchmarkRunner.php` → `Igo::fromStorage(FileStorage::fromDataDir($dictionary), 'UTF-8')`

> 注（承認の論点）: 例外を握りつぶす `tryFromDictDir` は公開 API から消える。本体での利用は無くテストのみだが、失敗時に null を得たい呼び出し側は `FileStorage::fromDataDir` を try/catch する必要がある。これは公開 API の縮小である。

### 2.5. 辞書層ファクトリの扱いは段階的に整理する

今回のリファクタでは、辞書層ファクトリを `@internal` 付きで残す。これは最終形ではなく、既存テストを保ったまま Storage 境界を先に明確化するための移行中の足場である。

最終的には `BinaryWordDictionary::fromDataDir`、`BinaryConnectionMatrix::fromDataDir`、`BinaryUnknownWordDictionary::fromDataDir`、`CharCategory::fromDataDir`、`Searcher::fromFile` などの辞書層ファクトリも、公開・準公開の構築経路から外す。ディレクトリ構造、ファイル名、Lazy/Resident の選択、複数辞書部品の読み込み順序は Storage 側に閉じ込め、辞書クラスは構築済みの配列・Trie・行列などを受け取って振る舞いを提供する責務に寄せる。

段階:

1. **今回** — 高水準入口（`Igo` / `Tagger`）を Storage 経由に統一し、辞書層ファクトリは `@internal` と enum 既定値で維持する。既存テストは最小変更で通し、挙動不変を確認する。
2. **次フェーズ** — 辞書層の単体テストを「ファイルから直接構築するテスト」から、「Storage 経由の統合的な読み込みテスト」と「辞書クラスに構築済みデータを渡す純粋ロジックテスト」へ分割する。
3. **次フェーズ以降** — テスト移行後に辞書層ファクトリを削除、または Storage 配下の内部 loader へ移す。これにより本番コードとテストコードの構築経路を Storage 境界へ揃える。

この段階分けにより、今回の変更では namespace 移動・enum 化・高水準 API 削除に集中し、辞書部品の責務分離は別リファクタとして扱う。失敗時の原因を追いやすくしつつ、最終的な設計方針としては「辞書一式の構築責務は Storage に集約する」ことを明示する。

### 2.6. ファイル I/O 部品も最終的には Storage 内部へ寄せる

`FileMappedInputStream` や `PagedBinaryReader` は現在 `IgoModern\Binary` にあるが、責務としては Storage 側に近い。どちらも `fopen` / `fseek` / `fread`、ファイル名、ページキャッシュ、Lazy/Resident の配列実体化選択といった永続化方式の詳細を扱うため、最終的には Storage の内部実装へ移す。

ただし、単純な namespace 移動は避ける。現在 `IntDynamicArray` / `ShortDynamicArray` / `CharDynamicArray` は `PagedBinaryReader` に直接依存しているため、`PagedBinaryReader` だけを Storage 配下へ移すと `Binary` 層が Storage の具象実装に依存してしまう。

次フェーズ以降の望ましい依存方向:

- `Binary` 層には、バイナリ値の unpack、`IntArray` / `ShortArray` / `CharArray`、Memory/Dynamic 配列の振る舞いを残す。
- `Binary` 層の Dynamic 配列は、具象ファイル reader ではなく `ByteReader` のような小さな読み取り契約に依存する。
- `Storage` 層は `PagedBinaryReader` 相当のファイル reader を実装し、Dynamic 配列へ注入する。
- `FileMappedInputStream` 相当の「順次読み込み + 配列実体化ポリシー選択」は、Storage 内部の loader として扱う。

段階:

1. **辞書層ファクトリ整理後** — `ByteReader` 相当の契約を導入し、Dynamic 配列を具象 `PagedBinaryReader` 依存から契約依存へ切り替える。
2. **その後** — `PagedBinaryReader` を Storage 内部へ移し、Storage がファイル reader を生成して Dynamic 配列に渡す。
3. **最後** — `FileMappedInputStream` の責務を Storage 内部 loader へ移し、`Binary` namespace からファイルシステム・実体化ポリシーの知識を取り除く。

この最終形では、`Binary` は「バイト列をどう値として解釈するか」と「配列としてどう振る舞うか」に集中し、`Storage` は「どこから読み、Lazy/Resident のどちらで実体化するか」を管理する。

### 3. Storage をトップレベル namespace へ移動

`IgoModern\Dictionary\Storage` / `Dictionary\Contract\DictionaryStorage` を `IgoModern\Storage` へ集約する（PSR-4: `IgoModern\` → `src/`）。

- `src/Dictionary/Contract/DictionaryStorage.php` → `src/Storage/DictionaryStorage.php`（`IgoModern\Storage\DictionaryStorage`）。返り値の `WordDictionary` / `UnknownWordDictionary` / `ConnectionMatrix` への `use`（`Dictionary\Contract\*`）はそのまま。
- `src/Dictionary/Storage/BinaryStorage.php` → `src/Storage/BinaryStorage.php`（`@internal` 実装基底）。`loadTrio` の引数も `ArrayMaterialization` 化。
- `src/Dictionary/Storage/FileStorage.php` → `src/Storage/FileStorage.php`（`loadTrio($dir, ArrayMaterialization::Lazy)`）。
- `src/Dictionary/Storage/MemoryStorage.php` → `src/Storage/MemoryStorage.php`（`loadTrio($dir, ArrayMaterialization::Resident)`）。
- 旧 `src/Dictionary/Storage/` と旧 `src/Dictionary/Contract/DictionaryStorage.php` を削除。
- `use` 参照を更新: `src/Analysis/Tagger.php`、`src/Igo.php`。

### 移動後の構成（抜粋）

```
Storage/                          … 公開: 辞書ストレージ
  DictionaryStorage               (interface; ファサード境界)
  BinaryStorage                   (@internal; バイナリ共有基底)
  FileStorage                     (Lazy)
  MemoryStorage                   (Resident)
Binary/
  ArrayMaterialization            (enum: Lazy / Resident)
```

## テスト

挙動不変のため新規テストは追加せず、既存テストをシグネチャ変更へ追従させる（回帰の安全網として機能させる）。

- `tests/Dictionary/WordDicTest.php` — `BinaryWordDictionary::fromDataDir($dir, false)` → `..., ArrayMaterialization::Resident`。Dynamic/Memory 配列型のアサーションは維持（enum マッピングの振る舞い検証）。
- `tests/Binary/FileMappedInputStreamTest.php` — `fromFile($file, true)` → `..., ArrayMaterialization::Lazy`。`reduce 有効/無効` のコメントも enum 用語へ更新。
- `tests/IgoTest.php` — `Igo::fromDictDir(...)` を `Igo::fromStorage(FileStorage::fromDataDir(...), ...)` に置換。`tryFromDictDir` の2テストは削除、または `FileStorage::fromDataDir(missing)` の例外確認へ書き換え。`use` を `IgoModern\Storage\{FileStorage, MemoryStorage}` に更新。
- `tests/Analysis/TaggerTest.php` — `Tagger::fromDataDir(...)` を `Tagger::fromStorage(FileStorage::fromDataDir(...), ...)` に置換。
- `tests/Analysis/TaggerFromStorageTest.php` / `tests/Dictionary/Build/DictionaryBuilderIntegrationTest.php` — `use` と構築呼び出しを更新。

## TDD の扱い

型置換・namespace 移動・冗長ファクトリ削除のみで挙動を変えない機械的リファクタのため、グローバル規約の例外（strictly mechanical）として Red ステップは省略する。既存スイート（File vs Memory 解析結果一致 `tests/IgoTest.php`、WordDicTest の実体化型、TaggerFromStorageTest）が回帰の安全網となる。

## 検証

- `composer test`（phpunit）が全グリーン。特に File/Memory 等価テストと実体化型テストで挙動不変を確認。
- `composer analyze`（phpstan）— enum 型・namespace 解決・到達不能コードを確認。
- `composer lint` / `composer format`（mago）。
- `grep -rn "fromDictDir\|tryFromDictDir\|bool \$reduce\|Dictionary\\\\Storage" src tests` で旧シンボルの残存ゼロを確認。

## 実装前レビュー

### 1. `@internal` と静的解析の衝突に注意する

辞書層ファクトリに `@internal` を付けたまま既存テストから直接呼び続けると、PHPStan が internal API への外部アクセスとして検出し、`composer analyze` が落ちる可能性がある。`phpstan.neon` は `src` と `tests` の両方を解析対象にしているため、実装時に確認する。

対応案:

- 今回は `@internal` タグではなく、コメント本文で「内部用」と書くに留める。
- または、辞書層テストを Storage 経由へ移す次フェーズまで `@internal` 付与を延期する。

### 2. 旧 namespace 検出の検証対象を広げる

検証コマンドの `Dictionary\\Storage` だけでは、移動対象である `IgoModern\Dictionary\Contract\DictionaryStorage` の残存を拾い切れない。Storage namespace 移動後は、旧 Storage 実装 namespace と旧 DictionaryStorage interface namespace の両方を確認する。

候補:

```bash
rg -n "fromDictDir|tryFromDictDir|bool \\$reduce|IgoModern\\\\Dictionary\\\\Storage|IgoModern\\\\Dictionary\\\\Contract\\\\DictionaryStorage" src tests
```

### 3. 「挙動不変」と「公開 API 縮小」を書き分ける

解析結果や辞書読み込み結果は不変だが、`Igo::fromDictDir` / `Igo::tryFromDictDir` / `Tagger::fromDataDir` の削除は公開 API としては破壊的変更である。利用者がいないため方針は許容できるが、計画書上は「解析挙動は不変。公開 API は Storage 境界へ縮小する」と表現するとより正確になる。

### 4. `PagedBinaryReader` の責務説明を厳密にする

`FileMappedInputStream` は Lazy/Resident の配列実体化選択を持つが、`PagedBinaryReader` 自体はページキャッシュ付き byte reader であり、実体化ポリシーは持たない。`2.6` の説明では、両者が共通して持つファイル I/O 責務と、`FileMappedInputStream` 固有の実体化ポリシー責務を分けて記述するとより正確になる。

### 5. 末尾の余分なコードフェンスを削除する

現在の文書末尾に余分な ````` が残っている。Markdown 表示を崩すため、実装着手前または次の文書整理時に削除する。
```
```
