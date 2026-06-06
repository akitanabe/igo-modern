# loader への責務集約プラン（段階3）

## 目的

[辞書ストレージ抽象化ロードマップ](dictionary-storage-next-plan.md)の最終段階。
`FileMappedInputStream` の責務（順次読み込み + 配列実体化ポリシー選択）を Storage 内部 loader へ移し、
Binary 名前空間からファイルシステム・実体化ポリシーの知識を取り除く。

望ましい依存方向は **Binary ← Dictionary ← Storage**。Binary 層はバイナリ unpack と配列実装
（Memory/Dynamic）のみを持ち、「ファイルを開く」「Lazy/Resident どちらで実体化するか」という判断を
一切持たない状態を最終目標とする。

## 現状

`src/Binary/` でファイルシステム・実体化ポリシー知識を持つのは次の 2 ファイルだけ。

- `src/Binary/FileMappedInputStream.php` — `fopen/fread/fseek/filesize` と
  `ArrayMaterialization::Lazy()` 分岐による Dynamic/Memory 配列の作り分け。
- `src/Binary/ArrayMaterialization.php` — Lazy/Resident のポリシー型。

辞書クラス（`BinaryWordDictionary` / `BinaryConnectionMatrix` / `CharCategory` / `Searcher` /
`BinaryUnknownWordDictionary`）はこれらを直接 `use` し、`fromDataDir`/`fromFile` で
`?ArrayMaterialization` と `?ByteReaderFactory` を引き回している。

## 方針 — 契約反転

`Binary\Contract` に**読み取り契約**を追加し、その**具象 loader** を Storage に置く。
辞書フォーマット知識（ヘッダ構成・レコード幅）は辞書クラスに残したまま、「ファイルを開いて順次読み、
実体化方式に応じた配列を返す」プリミティブだけを Storage 側へ移す。

これにより:

- `src/Binary/` から FS コードと Lazy/Resident ポリシーが消え、契約（interface）だけが残る。
- 辞書クラスの `fromDataDir` から `ArrayMaterialization` 引数が消え、Storage が提供する
  `InputStreamFactory` を 1 つ受け取る形になる（materialization は factory に内包）。
- 辞書クラスは `IgoModern\Storage` を import せず `Binary\Contract` のみに依存し、依存方向を維持する。

既存の `ByteReader` / `ByteReaderFactory`（Binary に契約・Storage に実装）と同じ配置パターンを踏襲する。
word.dat の常時ランダムアクセスは引き続き `ByteReaderFactory` で扱う。

## 変更内容

### 1. Binary\Contract に契約を 2 つ追加

- `src/Binary/Contract/InputStream.php`（新規）— 辞書ファイルの順次読み取り契約。
  consumer が実際に呼ぶメソッドのみ宣言する。
  - `getInt(): int`
  - `getIntArray(int $count): array`
  - `getIntArrayInstance(int $count): IntArray`
  - `getShortArrayInstance(int $count): ShortArray`
  - `getCharArrayInstance(int $count): CharArray`
  - `size(): int`
  - `close(): bool`
- `src/Binary/Contract/InputStreamFactory.php`（新規）— `open(string $fileName): InputStream`。
  実体化方式（Lazy/Resident）は実装側に内包され、契約自体はポリシーを露出しない。

### 2. FileMappedInputStream / ArrayMaterialization を Storage へ移管

- `src/Binary/FileMappedInputStream.php` → `src/Storage/FileInputStream.php`（namespace `IgoModern\Storage`）
  - `Binary\Contract\InputStream` を実装。Memory 配列の `fromReader` 用に
    `IntArrayReader/ShortArrayReader/CharArrayReader`（Binary\Contract）の実装も維持。
  - FS 操作と `ArrayMaterialization::Lazy()` 分岐（Dynamic 配列生成 / `IntMemoryArray::fromReader` 等）は
    現行ロジックをそのまま保持。
  - 消費側で参照ゼロの旧 static helper（`getStringFromFile` / `_getIntArray` / `_getString`）は移管時に整理。
    char.category の全件読みを `open()->getIntArray()` のインスタンス経由へ置換するため `getIntArrayFromFile` も不要化。
- `src/Binary/ArrayMaterialization.php` → `src/Storage/ArrayMaterialization.php`（namespace `IgoModern\Storage`）
  - 実装本体は不変。Binary 名前空間からポリシー型が消える。
- `src/Storage/FileInputStreamFactory.php`（新規）— `Binary\Contract\InputStreamFactory` 実装。
  `ArrayMaterialization` と `ByteReaderFactory` を保持し、`open()` で `FileInputStream::fromFile(...)` を返す。
  Build 経路簡潔化のため `static lazy(ByteReaderFactory): self` / `static resident(ByteReaderFactory): self` を用意。

### 3. Storage 構築点（loadTrio）を factory 注入へ

- `src/Storage/BinaryStorage.php` `loadTrio`:
  `$byteReaderFactory = new PagedByteReaderFactory();`
  `$streams = new FileInputStreamFactory($materialization, $byteReaderFactory);` を生成し、
  各辞書へ `$streams`（word 辞書のみ加えて `$byteReaderFactory`）を渡す。
- `src/Storage/FileStorage.php` / `src/Storage/MemoryStorage.php` は `ArrayMaterialization` の import 元が
  Storage 内になるだけで挙動不変。

### 4. 辞書クラスの fromDataDir を契約依存へ（`?ArrayMaterialization` 引数を削除）

共通変更: `FileMappedInputStream::fromFile(...)` を `$streams->open(...)` に置換、
`?ArrayMaterialization $materialization` 引数を削除、import を `Binary\Contract\InputStreamFactory` へ差し替え。

- `src/Dictionary/Trie/Searcher.php`:
  `fromFile(string $filePath, InputStreamFactory $streams): self`
- `src/Dictionary/Binary/BinaryConnectionMatrix.php`:
  `fromDataDir(string $dataDir, InputStreamFactory $streams): self`
- `src/Dictionary/CharCategory.php`:
  `fromDataDir(string $dataDir, InputStreamFactory $streams): self`。
  `readCategories` の `FileMappedInputStream::getIntArrayFromFile($dir.'/char.category')` は
  `$streams->open($dir.'/char.category')` のインスタンス経由の全件読みに置換
  （ByteReader を開かない順次読みのため、`code2category` のみ open されるテスト前提を維持）。
  `getIntArrayFromFile` は内部で `close()` していたため、置換後の `readCategories` も
  `$stream = $streams->open(...)`、`try { $data = $stream->getIntArray(intdiv($stream->size(), 4)); } finally { $stream->close(); }`
  の形にし、`char.category` 用 stream を確実に閉じる。
- `src/Dictionary/Binary/BinaryWordDictionary.php`:
  `fromDataDir(string $dataDir, InputStreamFactory $streams, ByteReaderFactory $byteReaderFactory): self`。
  word.dat の `new WordDataReader($byteReaderFactory->open($dir.'/word.dat'))` と
  `Searcher::fromFile(..., $streams)` を踏襲。`readIndices` も `$streams` 経由に。
- `src/Dictionary/Binary/BinaryUnknownWordDictionary.php`:
  `fromDataDir(string $dataDir, BinaryWordDictionary $wordDic, InputStreamFactory $streams): self`。
  `CharCategory::fromDataDir($dir, $streams)` へ委譲。

### 5. Build 経路の更新

- `src/Dictionary/Build/Word2IdCategoryIdResolver.php`:
  `Searcher::fromFile($dir.'/word2id', null, new PagedByteReaderFactory())` を
  `Searcher::fromFile($dir.'/word2id', FileInputStreamFactory::lazy(new PagedByteReaderFactory()))` に置換。
  Build は既に `IgoModern\Storage\PagedByteReaderFactory` を import 済みのため新たな方向違反は発生しない。

## テスト（TDD: Red → Green → Refactor）

`composer test`（= phpunit、`phpunit.xml` で `tests/` 全体）。

新規・移設（先に失敗テストを用意してから実装）:

- `tests/Storage/FileInputStreamTest.php` — 旧 `tests/Binary/FileMappedInputStreamTest.php` を Storage 名前空間へ移設し、
  Lazy→Dynamic / Resident→Memory・factory 欠落ガード・size/close を検証。
- `tests/Storage/FileInputStreamFactoryTest.php`（新規）— `open()` が `InputStream` を返し、
  Lazy/Resident と `ByteReaderFactory` を正しく内包・伝播することを確認。
- 契約 `InputStream` / `InputStreamFactory` への依存検証（既存 ArrayTest の ByteReader 依存テストに倣う）。

既存テストのシグネチャ追従（機械的、`null, new PagedByteReaderFactory()` → factory 注入）:

- `tests/Dictionary/Trie/SearcherTest.php`
- `tests/Dictionary/MatrixTest.php`
- `tests/Dictionary/CharCategoryTest.php`（`RecordingByteReaderFactory` を `FileInputStreamFactory::lazy(...)` で包む。
  `code2category` のみ open のアサートは不変）
- `tests/Dictionary/WordDicTest.php`（Lazy/Resident 実体化切替テストは `FileInputStreamFactory::lazy/resident` で構築）
- `tests/Dictionary/UnknownTest.php`
- `tests/Dictionary/Build/*Test.php`（WordDictionaryBuilder / MatrixBuilder / CharCategoryBuilder /
  DoubleArrayTrieBuilder / DictionaryBuilderIntegration）
- `tests/Binary/ArrayTest.php` は Dynamic 配列が Binary に残るため変更不要。
- `tests/Support/RecordingByteReaderFactory.php` は `ByteReaderFactory` 実装のまま流用。

## 検証手順

1. `composer test` 全緑（WordDic / Matrix / CharCategory / Unknown / Trie / Build 統合を含む）。
2. `composer analyze`（phpstan）で型・未使用 import なしを確認。
3. `composer lint` で lint ルール違反がないことを確認。
4. `composer format` で整形差分がない状態にする。
5. `grep -rn "FileMappedInputStream\|ArrayMaterialization" src/Binary` が**ゼロ件**であること
   （Binary から FS・ポリシー知識が消えた証跡）。
6. 辞書クラス（`src/Dictionary/`）が `use IgoModern\Storage\` を含まないこと（Build 経路は既存通り許容）。
7. 実辞書での解析回帰（手元に辞書があれば）: `ParseCommand` / `FileStorage::fromDataDir` 経由で既存と同一の解析結果になること。

## 完了後

[辞書ストレージ抽象化ロードマップ](dictionary-storage-next-plan.md)の段階3を「✅ 実装済み」へ更新する。
