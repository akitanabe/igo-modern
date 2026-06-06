# 段階2 — ファイル reader の Storage 移管プラン

辞書ストレージ抽象化ロードマップ（[dictionary-storage-next-plan.md](dictionary-storage-next-plan.md)）の段階2を実装するためのプラン。

## 背景と目的

最終目標は Binary 層からファイルシステムと配列実体化ポリシーの知識を取り除くこと。

段階1で Dynamic 配列（Int/Short/Char）と `WordDataReader` のコンストラクタは既に `ByteReader` 契約に依存済み。残る具象結合は **`fromFile` 内の `PagedBinaryReader::fromFile()` 呼び出し4箇所のみ**：

- `src/Binary/IntDynamicArray.php`
- `src/Binary/ShortDynamicArray.php`
- `src/Binary/CharDynamicArray.php`
- `src/Dictionary/WordDataReader.php`

段階2のゴールは、この「具象 reader 生成」を Storage 層へ追い出し、**Storage がファイル reader を生成して Dynamic 配列へ注入する**構成へ変えること。完了後、`src/Binary/` と `src/Dictionary/` から `PagedBinaryReader` の参照が消える。

### 設計上の決め手

Lazy 時は `FileMappedInputStream` が1ファイルへ複数 reader ハンドルを開く（word.inf に対し int1本＋short3本など）。よって注入すべきは単一 reader ではなく **ファクトリ**（`open(string): ByteReader`）である。

## 確定した設計判断

- **配置先**: 移管 `PagedBinaryReader` と新設ファクトリ具象は `IgoModern\Storage` 直下。
- **引数の通し方**: `ByteReaderFactory` を `ArrayMaterialization` と並走する**別パラメータ**で通す。値オブジェクトへの束ねは段階3（loader 集約）で行う。
- **Build 経路**: `Word2IdCategoryIdResolver` は Lazy のまま `new PagedByteReaderFactory()` を生成して渡す（**案A**）。build の振舞いは現状と完全一致。Build→Storage の結合は移行途中の一時的なもので、将来 Build 読み込み経路を Storage へ寄せれば解消（現ロードマップのスコープ外）。

## 実装方針

### 1. 受け皿の新設・移動

**`src/Binary/PagedBinaryReader.php` → `src/Storage/PagedBinaryReader.php`**
- namespace を `IgoModern\Binary` → `IgoModern\Storage` に変更。`implements ByteReader` のため `use IgoModern\Binary\Contract\ByteReader;` は維持。
- `static fromFile()` を**削除**（fopen 責務は新ファクトリへ集約）。`__construct($file, int $pageSize)` / `readBytes` / `__destruct` のみの「ハンドルを受け取って読む」クラスに純化。

**新規 `src/Binary/Contract/ByteReaderFactory.php`（`IgoModern\Binary\Contract`）**

```php
interface ByteReaderFactory
{
    /** 指定ファイルを開き、ランダムアクセス可能な ByteReader を返す。 */
    public function open(string $fileName): ByteReader;
}
```

契約を `Binary\Contract` に置く理由：注入を受ける `FileMappedInputStream` 等はすべて Binary 層にあり、Binary 層は「契約に依存・具象は知らない」を保つ。

**新規 `src/Storage/PagedByteReaderFactory.php`（`IgoModern\Storage`）**

```php
final class PagedByteReaderFactory implements ByteReaderFactory
{
    /** 指定ファイルを開き、ページ読み込み reader を返す。 */
    public function open(string $fileName): ByteReader
    {
        $file = fopen($fileName, 'rb');
        if ($file === false) {
            throw new RuntimeException('dictionary reading failed.');
        }
        return new PagedBinaryReader($file);
    }
}
```

旧 `PagedBinaryReader::fromFile` の fopen＋例外ロジックがここへ移る。

### 2. Binary 層プラミング

**`src/Binary/FileMappedInputStream.php`**
- フィールド `private ?ByteReaderFactory $byteReaderFactory;` を追加。
- `__construct($file, string $fileName, ?ArrayMaterialization $materialization = null, ?ByteReaderFactory $byteReaderFactory = null)`。
- `fromFile(string $fileName, ?ArrayMaterialization $materialization = null, ?ByteReaderFactory $byteReaderFactory = null): self`。
- Dynamic 生成を差し替え（`getIntArrayInstance` / `getShortArrayInstance` / `getCharArrayInstance`）:

  ```php
  // 変更前: IntDynamicArray::fromFile($this->fileName, $this->cur)
  // 変更後:
  $array = new IntDynamicArray($this->requireByteReaderFactory()->open($this->fileName), $this->cur);
  ```

- private ガード `requireByteReaderFactory(): ByteReaderFactory` を追加し、Lazy なのに factory が null の場合は `RuntimeException('dictionary reading failed.')`。
  - sequential 専用 static helper（`getIntArrayFromFile` / `getStringFromFile` / `_getIntArray` / `_getString`、`self::fromFile($fileName)` で factory=null）は Dynamic を作らないため到達せず、null のままで安全。
- `use IgoModern\Binary\Contract\ByteReaderFactory;` 追加。具象 `PagedBinaryReader` / `PagedByteReaderFactory` は import しない。

**`src/Binary/{Int,Short,Char}DynamicArray.php`**
- 各 `public static function fromFile(string $fileName, int $start): self` を**削除**。`PagedBinaryReader` への参照が消える。コンストラクタ（`ByteReader $reader`）は不変。

### 3. Dictionary 層プラミング（factory を materialization と並走で転送）

各 `fromDataDir` / `fromFile` の署名に `?ByteReaderFactory $byteReaderFactory = null` を末尾追加し、内部の `FileMappedInputStream::fromFile(...)` へ転送する。

**署名は nullable のまま（レビュー点1・2の整合）**: PHP では `?ArrayMaterialization $materialization = null` の後ろに必須引数を置けないため、materialization と同じ「内部限定・nullable 正規化」慣習に揃えて nullable 既定とする。ただし factory が実際に要る箇所（FileMappedInputStream の Lazy 経路＝Dynamic 配列生成、および BinaryWordDictionary の word.dat 生成）では**ガードで fail-fast** し、null は「設定漏れ」として明示的に失敗させる。これにより既定 Lazy で factory を渡さない呼び出しは例外になるため、**辞書層を直接構築する既存テストは「無変更」ではなく factory を渡す非回帰更新対象**となる（後述の検証セクション）。

- **`src/Dictionary/Trie/Searcher.php`**: `fromFile(string $filePath, ?ArrayMaterialization $materialization = null, ?ByteReaderFactory $byteReaderFactory = null)`。
- **`src/Dictionary/CharCategory.php`**: `fromDataDir(string $dataDir, ?ArrayMaterialization $materialization = null, ?ByteReaderFactory $byteReaderFactory = null)`。`readCategories` の `getIntArrayFromFile`（Dynamic 不使用）は不変。
- **`src/Dictionary/Binary/BinaryWordDictionary.php`**: `fromDataDir(..., ?ByteReaderFactory $byteReaderFactory = null)`。`FileMappedInputStream::fromFile` / `Searcher::fromFile` / `readIndices` へ factory を転送。`WordDataReader::fromFile($dataDir.'/word.dat')` → `new WordDataReader($byteReaderFactory->open($dataDir.'/word.dat'))`。`readIndices` 署名にも factory 追加。
  - **注意（レビュー点2）**: word.dat は materialization に関係なく `WordDataReader` が常にランダムアクセス reader を要するため、**Resident でも factory が必須**。署名は nullable のまま保持しつつ、`word.dat` 生成前に専用ガードを置き、factory が null なら `RuntimeException('dictionary reading failed.')` で fail-fast する。`BinaryWordDictionary::fromDataDir($dir, ArrayMaterialization::Resident(), null)` も安全ではない点に注意。
- **`src/Dictionary/Binary/BinaryConnectionMatrix.php`**: `fromDataDir(..., ?ByteReaderFactory $byteReaderFactory = null)` を転送。
- **`src/Dictionary/Binary/BinaryUnknownWordDictionary.php`**: `fromDataDir(string $dataDir, BinaryWordDictionary $wordDic, ?ArrayMaterialization $materialization = null, ?ByteReaderFactory $byteReaderFactory = null)`。`CharCategory::fromDataDir` へ転送。

**`src/Dictionary/WordDataReader.php`**
- `static fromFile()` と `use IgoModern\Binary\PagedBinaryReader;` を**削除**。コンストラクタ（`ByteReader $reader`）は不変。生成は上記 BinaryWordDictionary 側へ移譲。

**`src/Dictionary/Build/Word2IdCategoryIdResolver.php`（案A）**

```php
use IgoModern\Storage\PagedByteReaderFactory;
...
Searcher::fromFile(
    $outputDirectory . '/word2id',
    null,                          // materialization 既定(Lazy)
    new PagedByteReaderFactory(),
)->eachCommonPrefix($key, 0, $callback);
```

### 4. Storage 層 — ファクトリの唯一の生成点

**`src/Storage/BinaryStorage.php`**
- `loadTrio(string $dir, ArrayMaterialization $materialization): static` 内で `$byteReaderFactory = new PagedByteReaderFactory();` を生成し、各 `fromDataDir` へ materialization と並べて渡す。
- `FileStorage` / `MemoryStorage` は `loadTrio` 委譲のまま変更不要。ランタイムでのファクトリ生成は `BinaryStorage` に閉じる。

## TDD の段取り

### Red（先に書く／壊す失敗テスト）
1. 新規 `tests/Storage/PagedByteReaderFactoryTest.php`: `open()` が `ByteReader` を返し `readBytes` が正しいスライスを返す／fopen 失敗で `RuntimeException`／同一ファイルへ複数回 `open` すると独立ハンドルが得られる、を検証。
2. 新規 `tests/Support/RecordingByteReaderFactory.php`: `open` のファイル名を記録し内部バイト列から `RecordingByteReader`（既存 `tests/Support/RecordingByteReader.php`）を返す test double。
3. `tests/Binary/FileMappedInputStreamTest.php`: Lazy テストを `FileMappedInputStream::fromFile($fileName, ArrayMaterialization::Lazy(), $recordingFactory)` 版に改修。Lazy 時に各 instance 生成で `factory->open($fileName)` が呼ばれ、生成物が Dynamic 配列で値が正しいことを assert。Resident 経路は factory 未使用で従来通り。
4. **伝播漏れ検知の上位テスト（レビュー点3）**: 単体だけでは `BinaryStorage→BinaryWordDictionary→Searcher / readIndices / WordDataReader`、`BinaryUnknownWordDictionary→CharCategory`、`Word2IdCategoryIdResolver→Searcher` の多段伝播漏れを捕まえられないため、以下を Red に含める。
   - `FileStorage::fromDataDir()` 経由で Lazy 読み込みが成功する統合テスト。
   - `RecordingByteReaderFactory` を `Searcher` / `BinaryWordDictionary` / `CharCategory` に渡し、期待ファイル名（word2id / word.dat / word.ary.idx / word.inf / code2category 等）が `open()` されることを検証。
   - `Word2IdCategoryIdResolverTest`: build 経路でも `PagedByteReaderFactory` が渡されて Lazy 読み込みが維持されることを既存 fixture で確認。

### Green
上記 1〜4 の本体変更を実装。

### 既存テストの移設・use 修正（非回帰）
- `tests/Binary/PagedBinaryReaderTest.php` を `tests/Storage/` へ移設。`PagedBinaryReader::fromFile` 廃止に伴い、`(new PagedByteReaderFactory())->open($file)` 経由、またはページサイズ指定が要るテストは `new PagedBinaryReader($handle, 4)` の直接構築に書き換え。namespace/use を `IgoModern\Storage` へ。
- `tests/Binary/ArrayTest.php`: Dynamic 配列の `fromFile` を使う3テストを `new IntDynamicArray((new PagedByteReaderFactory())->open($fileName), $offset)` 等へ書き換え。`...DependsOnByteReaderContract` 系は不変。
- `tests/Dictionary/WordDataReaderTest.php`: `WordDataReader::fromFile` を使う3テストを `new WordDataReader((new PagedByteReaderFactory())->open($file))` へ書き換え。契約依存テストは不変。
- **辞書層を直接構築する既存テストへ factory を渡す（レビュー点1の非回帰更新）**。これらは現状 factory なし・既定 Lazy で構築しており、ガード導入後は `new PagedByteReaderFactory()` を渡すよう更新が必須（「無変更」ではない）:
  - `tests/Dictionary/CharCategoryTest.php`（`CharCategory::fromDataDir`）
  - `tests/Dictionary/WordDicTest.php`（`BinaryWordDictionary::fromDataDir`、Lazy/Resident 両方。Resident でも word.dat のため factory 必須）
  - `tests/Dictionary/UnknownTest.php`（`BinaryWordDictionary` / `BinaryUnknownWordDictionary::fromDataDir`）
  - `tests/Dictionary/MatrixTest.php`（`BinaryConnectionMatrix::fromDataDir`）
  - `tests/Dictionary/Trie/SearcherTest.php`（`Searcher::fromFile`）
  - build 統合系 `tests/Dictionary/Build/*`（`DictionaryBuilderIntegrationTest` / `WordDictionaryBuilderTest` / `MatrixBuilderTest` / `CharCategoryBuilderTest` / `DoubleArrayTrieBuilderTest` の `*::fromDataDir` / `Searcher::fromFile` 呼び出し）

### Refactor
ファクトリ double の重複整理、全 method/test への意図コメント付与（コメント規約）。

## 検証
- `composer test`（既存119＋追加が green）。
  - **非回帰だが「無変更」ではない（レビュー点1で修正）**: `WordDicTest` / `MatrixTest` / `SearcherTest` / `CharCategoryTest` / `UnknownTest` および build 経路の `DictionaryBuilder*Test` 等は、辞書層を直接構築しているため `new PagedByteReaderFactory()` を渡す**更新を施したうえで** green にする（結果・振舞いは不変）。
  - **真に無変更で green**: `Storage` の公開構築点（`FileStorage` / `MemoryStorage`）経由でファクトリが内部注入される `IgoTest` / `TaggerFromStorageTest` 等は、署名追加の影響を受けず結果不変。
- `composer analyze`（phpstan：契約型で型エラーなし）／`composer lint`／`composer format`。
- 最終: `grep -rn PagedBinaryReader src/Binary src/Dictionary` が **0件**であること（具象参照の消去確認）。`src/Storage/` 配下のみにヒット。

## 変更対象ファイル一覧（段階順）

**A 受け皿**
1. 移動 `src/Binary/PagedBinaryReader.php` → `src/Storage/PagedBinaryReader.php`
2. 新規 `src/Binary/Contract/ByteReaderFactory.php`
3. 新規 `src/Storage/PagedByteReaderFactory.php`

**B Binary**
4. `src/Binary/FileMappedInputStream.php`
5. `src/Binary/{Int,Short,Char}DynamicArray.php`

**C Dictionary**
6. `src/Dictionary/WordDataReader.php`
7. `src/Dictionary/Trie/Searcher.php`
8. `src/Dictionary/CharCategory.php`
9. `src/Dictionary/Binary/BinaryWordDictionary.php`
10. `src/Dictionary/Binary/BinaryConnectionMatrix.php`
11. `src/Dictionary/Binary/BinaryUnknownWordDictionary.php`
12. `src/Dictionary/Build/Word2IdCategoryIdResolver.php`

**D Storage**
13. `src/Storage/BinaryStorage.php`

**E テスト**
14. 新規 `tests/Storage/PagedByteReaderFactoryTest.php`（旧 `tests/Binary/PagedBinaryReaderTest.php` 移設）
15. 新規 `tests/Support/RecordingByteReaderFactory.php`
16. `tests/Binary/FileMappedInputStreamTest.php`
17. `tests/Binary/ArrayTest.php`
18. `tests/Dictionary/WordDataReaderTest.php`

## レビュー追記

### 1. nullable factory と既定 Lazy の直接構築経路が衝突する

`fromDataDir` / `fromFile` の末尾に `?ByteReaderFactory $byteReaderFactory = null` を追加する方針は、
既存の直接構築テストと衝突する。

現在も `Searcher::fromFile($file)`、`BinaryConnectionMatrix::fromDataDir($dir)`、
`CharCategory::fromDataDir($dir)`、`BinaryWordDictionary::fromDataDir($dir)` などは、テストや build
検証から factory なしで呼ばれている。これらは既定の `ArrayMaterialization::Lazy()` で Dynamic 配列を
生成するため、計画どおり `FileMappedInputStream::requireByteReaderFactory()` を入れると、factory が
null のまま Lazy 配列生成に到達して `RuntimeException('dictionary reading failed.')` になる。

したがって「既存テストが無変更で green」という検証方針は成り立たない。Storage 境界へ reader 生成を
寄せる目的を優先するなら、辞書層の直接構築 API とそのテストは `new PagedByteReaderFactory()` を明示的に
渡す形へ更新する、と計画に含める必要がある。

推奨方針:

- `FileMappedInputStream::fromFile()` は sequential helper 用に factory nullable を許す。
- Dynamic 配列を作る Lazy 経路では `ByteReaderFactory` を必須にし、null は明示的な設定漏れとして扱う。
- 辞書層直接構築テストは「無変更」ではなく、factory を渡す非回帰更新対象に含める。

### 2. `BinaryWordDictionary` は Resident でも factory が必要になる

`BinaryWordDictionary::fromDataDir` の `WordDataReader::fromFile($dataDir . '/word.dat')` を
`new WordDataReader($byteReaderFactory->open($dataDir . '/word.dat'))` に置き換える場合、
`$byteReaderFactory` が nullable のままだと Resident 経路でも null dereference になる。

`word.inf` や `word.ary.idx` の配列は Resident ならストリームから読み切れるが、`word.dat` は
`WordDataReader` が常にランダムアクセス reader を必要とする。つまり
`BinaryWordDictionary::fromDataDir($dir, ArrayMaterialization::Resident(), null)` も安全ではない。

推奨方針:

- `BinaryWordDictionary::fromDataDir` では materialization に関係なく `ByteReaderFactory` を必須にする。
- 互換的に nullable 署名を残す場合でも、`word.dat` 生成前に専用 guard で明示的に失敗させる。
- `BinaryStorage::loadTrio` と build 経路は、必ず `PagedByteReaderFactory` を生成して渡す。

### 3. Red テストは factory の伝播漏れを検知できる粒度に広げる

現在の Red 案は `PagedByteReaderFactory` と `FileMappedInputStream` 単体を中心にしているため、
factory の伝播漏れを十分には捕まえられない。実装時に壊れやすいのは、次のような上位経路である。

- `BinaryStorage -> BinaryWordDictionary -> Searcher / readIndices / WordDataReader`
- `BinaryUnknownWordDictionary -> CharCategory`
- `Word2IdCategoryIdResolver -> Searcher`

推奨方針:

- `FileStorage::fromDataDir()` 経由で Lazy 読み込みが成功する統合テストを Red に含める。
- `RecordingByteReaderFactory` を `Searcher` / `BinaryWordDictionary` / `CharCategory` に渡し、期待ファイル名が
  `open()` されることを検証する。
- `Word2IdCategoryIdResolverTest` は、build 経路でも `PagedByteReaderFactory` が渡されて Lazy 読み込みが
  維持されることを既存 fixture で確認する。

この修正により、段階2の目的である「`src/Binary/` と `src/Dictionary/` から `PagedBinaryReader` 具象参照を
消す」ことと、TDD の失敗検知ポイントが一致する。
