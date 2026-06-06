# runtime 辞書 loader へのディレクトリ構造集約プラン（段階4）

## 目的

[辞書ストレージ抽象化ロードマップ](dictionary-storage-next-plan.md)の段階3で、ファイル reader と
配列実体化ポリシーは Storage 側へ移った。一方で runtime 辞書構築では、まだ辞書クラスの
`fromDataDir()` が辞書ディレクトリ内のファイル配置を知っている。

この段階では、runtime 解析で使う辞書ディレクトリ構造の知識を Storage 内部 loader へ集約する。
辞書クラスは、構築済みの trie、配列 reader、行列、文字カテゴリを受け取って振る舞う責務に寄せる。

ここでいう「ディレクトリ構造」は、`word2id` / `word.dat` / `word.ary.idx` / `word.inf` /
`matrix.bin` / `code2category` / `char.category` などのファイル名と配置を指す。

## スコープ

### 対象

- runtime 辞書一式を構築する `BinaryDictionaryLoader` 契約を Storage に追加する。
- File/Memory の実体化方針ごとに `FileBinaryDictionaryLoader` / `MemoryBinaryDictionaryLoader` を追加する。
- loader は読み込み対象の辞書ディレクトリを constructor で受け取り、`load($dataDir)` のような呼び出し時入力を持たない。
- `BinaryStorage::loadTrio()` から `$dir` と stream 生成責務を外し、loader 契約から辞書一式を受け取る形にする。
- runtime 経路で使う辞書クラスの `fromDataDir()` を削除する。

### 対象外

- `Word2IdCategoryIdResolver` の責務整理。
  - Build 経路は今回は触らない。
  - 現状どおり `Searcher::fromFile()` を使って word2id からカテゴリ trie ID を解決する。
- `Searcher::fromFile()` の削除。
  - Build 経路からも使われているため、今回は互換的な構築点として残す。
  - 削除は `Word2IdCategoryIdResolver` を loader 注入へ移した後に再検討する。

## 現状

`BinaryStorage::loadTrio()` は Storage 側で `PagedByteReaderFactory` と `FileInputStreamFactory` を生成する。
しかし、各辞書部品の構築は辞書クラスの `fromDataDir()` へ委譲している。

```php
$word = BinaryWordDictionary::fromDataDir($dir, $streams, $byteReaderFactory);

return new static(
    $word,
    BinaryUnknownWordDictionary::fromDataDir($dir, $word, $streams),
    BinaryConnectionMatrix::fromDataDir($dir, $streams),
);
```

そのため、次の配置知識が Dictionary 層に残っている。

- `BinaryWordDictionary`
  - `$dataDir . '/word2id'`
  - `$dataDir . '/word.dat'`
  - `$dataDir . '/word.ary.idx'`
  - `$dataDir . '/word.inf'`
- `BinaryUnknownWordDictionary` / `CharCategory`
  - `$dataDir . '/code2category'`
  - `$dataDir . '/char.category'`
- `BinaryConnectionMatrix`
  - `$dataDir . '/matrix.bin'`

これは「辞書ディレクトリから辞書部品を組み立てる責務は Storage が持つ」という方針から見ると、
まだ辞書層に構築責務が残っている状態である。

一方で `Searcher::fromFile()` は、渡された単一ファイルから double-array trie を復元するだけであり、
辞書ディレクトリのファイル配置は知らない。また Build 経路の `Word2IdCategoryIdResolver` からも使われている。
このため今回の段階では、`Searcher::fromFile()` は削除せず互換的に維持する。

## 方針

Storage 配下に runtime 辞書一式を構築する loader 契約と Storage 実装別 loader を追加する。
`BinaryStorage` は loader 契約から構築済みの word / unknown / matrix を受け取るだけにする。

依存方向は引き続き **Binary ← Dictionary ← Storage** とする。

- Dictionary 層は `IgoModern\Storage` を import しない。
- Storage loader は Dictionary の具象クラスを組み立てるため、Dictionary に依存してよい。
- `InputStreamFactory` / `ByteReaderFactory` は既存どおり Binary 契約として Storage loader 内で使う。
- loader は読み込み対象の辞書ディレクトリを内部状態として保持し、呼び出しごとに `$dataDir` を受け取らない。
- File/Memory ごとの Lazy/Resident 方針は loader 実装に閉じる。

## 変更内容

### 1. Storage に runtime 辞書 loader 契約を追加する

新規 interface:

- `src/Storage/BinaryDictionaryLoader.php`

責務:

- runtime 解析で使う 3 種類の辞書を構築する loader の契約を表す。
- 呼び出し側へ辞書ディレクトリ、File/Memory の実体化方針、reader 生成手順を露出しない。
- 未知語辞書は単語辞書と word ID 解決を共有するため、構築済みの `BinaryWordDictionary` を受け取る。

想定する形:

```php
namespace IgoModern\Storage;

use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use IgoModern\Dictionary\Binary\BinaryUnknownWordDictionary;
use IgoModern\Dictionary\Binary\BinaryWordDictionary;

interface BinaryDictionaryLoader
{
    public function loadWordDictionary(): BinaryWordDictionary;

    public function loadUnknownWordDictionary(BinaryWordDictionary $wordDictionary): BinaryUnknownWordDictionary;

    public function loadConnectionMatrix(): BinaryConnectionMatrix;
}
```

契約を置くことで、`BinaryStorage` は具象 loader の種類や辞書ディレクトリの場所を知らずに辞書一式を
受け取れる。また、将来 Storage 実装を追加する場合も、その Storage 専用 loader を差し替えればよい。

### 2. Storage 実装別の runtime 辞書 loader を追加する

新規クラス:

- `src/Storage/FileBinaryDictionaryLoader.php`
- `src/Storage/MemoryBinaryDictionaryLoader.php`

責務:

- constructor で辞書ディレクトリを受け取り、以後の `load*()` では読み込み元を受け取らない。
- FileStorage 用 loader は Lazy 実体化を内包する。
- MemoryStorage 用 loader は Resident 実体化を内包する。
- `word.dat` 用のランダムアクセス reader は `ByteReaderFactory` から生成する。
- `word.inf` / `word.ary.idx` / `word2id` / `matrix.bin` / `code2category` / `char.category` は
  `InputStreamFactory` 経由で読み込む。

想定する形:

```php
namespace IgoModern\Storage;

use IgoModern\Binary\Contract\ByteReaderFactory;
use IgoModern\Binary\Contract\InputStreamFactory;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use IgoModern\Dictionary\Binary\BinaryUnknownWordDictionary;
use IgoModern\Dictionary\Binary\BinaryWordDictionary;
use IgoModern\Dictionary\Category;
use IgoModern\Dictionary\CharCategory;
use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Dictionary\WordDataReader;

final class FileBinaryDictionaryLoader implements BinaryDictionaryLoader
{
    public function __construct(
        private string $dataDir,
        private InputStreamFactory $streams,
        private ByteReaderFactory $byteReaderFactory,
    ) {}

    public static function create(string $dataDir): self
    {
        $byteReaderFactory = new PagedByteReaderFactory();

        return new self($dataDir, FileInputStreamFactory::lazy($byteReaderFactory), $byteReaderFactory);
    }

    public function loadWordDictionary(): BinaryWordDictionary
    {
        // word2id / word.dat / word.ary.idx / word.inf を組み合わせて単語辞書を作る。
    }

    public function loadUnknownWordDictionary(BinaryWordDictionary $wordDictionary): BinaryUnknownWordDictionary
    {
        // code2category / char.category を読み、単語辞書と組み合わせて未知語辞書を作る。
    }

    public function loadConnectionMatrix(): BinaryConnectionMatrix
    {
        // matrix.bin を読み、連接行列を作る。
    }

    private function readWordIndices(): IntArray
    {
        // word.ary.idx の int 配列 reader を作る。
    }

    /** @return list<Category> */
    private function readCategories(): array
    {
        // char.category のカテゴリ定義を読み、Category のリストへ変換する。
    }
}
```

`MemoryBinaryDictionaryLoader` は同じ読み取り処理を使い、Resident 用の `InputStreamFactory` を内包する。
実装重複が大きい場合は、Storage 配下の小さな抽象基底（例: `AbstractBinaryDictionaryLoader`）を検討する。
公開したいのは interface であり、基底クラスは必要になった場合だけ追加する。

`create(string $dataDir)` は任意だが、File/Memory それぞれの reader pair 生成を閉じ込められるため、
`FileStorage::fromDataDir()` / `MemoryStorage::fromDataDir()` を薄くできる。テストで独自 factory を注入したい場合は
constructor を使う。

### 3. `BinaryStorage::loadTrio()` を loader 契約経由にする

`src/Storage/BinaryStorage.php`:

```php
final protected static function loadTrio(BinaryDictionaryLoader $loader): static
{
    $word = $loader->loadWordDictionary();

    return new static(
        $word,
        $loader->loadUnknownWordDictionary($word),
        $loader->loadConnectionMatrix(),
    );
}
```

`FileStorage::fromDataDir()` / `MemoryStorage::fromDataDir()` は、それぞれの loader を作って `loadTrio()` へ渡す。

```php
final class FileStorage extends BinaryStorage
{
    public static function fromDataDir(string $dir): self
    {
        return self::loadTrio(FileBinaryDictionaryLoader::create($dir));
    }
}
```

これにより、`BinaryStorage` は辞書ディレクトリ、ファイル名、Lazy/Resident の reader 生成を知らない状態になる。

### 4. runtime 辞書クラスの `fromDataDir()` を削除する

削除対象:

- `BinaryWordDictionary::fromDataDir()`
- `BinaryUnknownWordDictionary::fromDataDir()`
- `BinaryConnectionMatrix::fromDataDir()`
- `CharCategory::fromDataDir()`

あわせて次の private helper を Storage loader 側へ移す。

- `BinaryWordDictionary::readIndices()`
- `CharCategory::readCategories()`

各辞書クラスは、構築済み部品を受け取って runtime 振る舞いを提供する責務だけを持つ。

- `BinaryWordDictionary`
  - `Searcher`
  - `WordDataReader`
  - `IntArray $indices`
  - `IntArray $dataOffsets`
  - `ShortArray $leftIds`
  - `ShortArray $rightIds`
  - `ShortArray $costs`
- `BinaryUnknownWordDictionary`
  - `CharCategory`
  - `BinaryWordDictionary`
- `BinaryConnectionMatrix`
  - `int $leftSize`
  - `ShortArray $matrix`
- `CharCategory`
  - `list<Category> $categories`
  - `IntArray $char2id`
  - `IntArray $eqlMasks`

### 5. `Searcher::fromFile()` は残す

`src/Dictionary/Trie/Searcher.php`:

- 今回は削除しない。
- コメントを調整し、辞書ディレクトリからの公開構築入口ではなく、単一 trie ファイルから復元する内部的な足場であることを明示する。

例:

```php
/**
 * 指定された double-array trie ファイルから探索器を復元する。
 *
 * Storage loader と Build 経路の互換的な構築点であり、辞書ディレクトリからの構築入口ではない。
 */
public static function fromFile(string $filePath, InputStreamFactory $streams): self
```

`@internal` タグは、静的解析でテストや Build 経路の呼び出しを落とす可能性があるため、今回は本文コメントに留める。

## テスト（TDD: Red → Green → Refactor）

### Red

先に次のテスト更新を行い、辞書クラスの `fromDataDir()` 削除前提の失敗を確認する。

- `tests/Storage/BinaryDictionaryLoaderTest.php`（新規）
  - File loader が Lazy stream と byte reader factory を使って word / unknown / matrix を構築できることを確認する。
  - Memory loader が Resident stream を使って word / unknown / matrix を構築できることを確認する。
  - loader が constructor で受け取った辞書ディレクトリへ束縛され、`load*()` 呼び出し側が `$dataDir` を渡さないことを確認する。
  - `word.ary.idx` の読み込み漏れがあると trie ID から word ID 範囲を展開できないことを、検索結果で検知する。
  - `matrix.bin` の読み込み漏れがあると `linkCost()` が期待値を返せないことを確認する。
  - `code2category` / `char.category` の読み込み漏れがあると未知語検索が期待候補を返せないことを確認する。
- `tests/Dictionary/WordDicTest.php`
  - `BinaryWordDictionary::fromDataDir()` 直接呼び出しを `BinaryDictionaryLoader` 経由へ置換する。
  - Lazy / Resident の配列実体化差分テストは維持する。
- `tests/Dictionary/UnknownTest.php`
  - `BinaryUnknownWordDictionary::fromDataDir()` 直接呼び出しを `BinaryDictionaryLoader` 経由へ置換する。
  - 未知語検索の期待値は変更しない。
- `tests/Dictionary/MatrixTest.php`
  - `BinaryConnectionMatrix::fromDataDir()` 直接呼び出しを `BinaryDictionaryLoader` 経由へ置換する。
  - `linkCost()` の期待値は変更しない。
- `tests/Dictionary/CharCategoryTest.php`
  - `CharCategory::fromDataDir()` 直接呼び出しを `BinaryDictionaryLoader` 経由、または constructor に構築済みデータを渡す純粋テストへ置換する。
  - 文字カテゴリと互換性判定の期待値は変更しない。
- `tests/Dictionary/Build/WordDictionaryBuilderTest.php`
  - build 後の単語辞書読み戻しを File loader 経由へ置換する。
- `tests/Dictionary/Build/MatrixBuilderTest.php`
  - build 後の行列読み戻しを File loader 経由へ置換する。
- `tests/Dictionary/Build/CharCategoryBuilderTest.php`
  - build 後の文字カテゴリ読み戻しを File loader 経由へ置換する。
- `tests/Dictionary/Build/DictionaryBuilderIntegrationTest.php`
  - 辞書部品単体の読み戻し確認を loader 経由へ置換する。
  - `FileStorage::fromDataDir()` 経由の解析統合テストは維持する。

### Green

- `BinaryDictionaryLoader` interface を追加する。
- `FileBinaryDictionaryLoader` / `MemoryBinaryDictionaryLoader` を追加する。
- `BinaryStorage::loadTrio()` を loader 契約だけに依存する形へ変更する。
- `FileStorage::fromDataDir()` / `MemoryStorage::fromDataDir()` で対応する loader を生成する。
- runtime 辞書クラスの `fromDataDir()` と関連 private helper を削除する。
- テストの構築呼び出しを loader 経由、または constructor 直接構築へ揃える。

### Refactor

- File / Memory の loader 実装に読み取り処理の重複が大きく出た場合のみ、Storage 配下の非公開基底へ寄せる。
- `BinaryStorage` から `InputStreamFactory` / `ByteReaderFactory` / `PagedByteReaderFactory` /
  `FileInputStreamFactory` への依存が消えていることを確認する。
- runtime 辞書クラスの import を整理し、Storage 契約への依存が残っていないことを確認する。
- `Searcher::fromFile()` のコメントを、互換的な構築点としての位置づけに更新する。

## 影響範囲

本番コード:

- `src/Storage/BinaryDictionaryLoader.php`（新規）
- `src/Storage/FileBinaryDictionaryLoader.php`（新規）
- `src/Storage/MemoryBinaryDictionaryLoader.php`（新規）
- `src/Storage/FileStorage.php`
- `src/Storage/MemoryStorage.php`
- `src/Storage/BinaryStorage.php`
- `src/Dictionary/Binary/BinaryWordDictionary.php`
- `src/Dictionary/Binary/BinaryUnknownWordDictionary.php`
- `src/Dictionary/Binary/BinaryConnectionMatrix.php`
- `src/Dictionary/CharCategory.php`
- `src/Dictionary/Trie/Searcher.php`（コメント調整のみ）

テスト:

- `tests/Storage/BinaryDictionaryLoaderTest.php`（新規）
- `tests/Dictionary/WordDicTest.php`
- `tests/Dictionary/UnknownTest.php`
- `tests/Dictionary/MatrixTest.php`
- `tests/Dictionary/CharCategoryTest.php`
- `tests/Dictionary/Build/WordDictionaryBuilderTest.php`
- `tests/Dictionary/Build/MatrixBuilderTest.php`
- `tests/Dictionary/Build/CharCategoryBuilderTest.php`
- `tests/Dictionary/Build/DictionaryBuilderIntegrationTest.php`
- `tests/IgoTest.php`（File/Memory の解析結果一致テストで loader 経由の統合確認）

## 検証

通常の検証ループを実行する。

```bash
composer test
composer analyze
composer lint
composer format
```

追加確認:

```bash
if grep -RInE --include='*.php' 'BinaryWordDictionary::fromDataDir|BinaryUnknownWordDictionary::fromDataDir|BinaryConnectionMatrix::fromDataDir|CharCategory::fromDataDir' src tests; then
    echo 'runtime dictionary fromDataDir references remain.' >&2
    exit 1
fi

if grep -InE 'InputStreamFactory|ByteReaderFactory|PagedByteReaderFactory|FileInputStreamFactory' src/Storage/BinaryStorage.php; then
    echo 'BinaryStorage still depends on reader construction details.' >&2
    exit 1
fi

if grep -RInF --include='*.php' 'use IgoModern\Storage' src/Dictionary; then
    echo 'Dictionary layer still depends on Storage namespace.' >&2
    exit 1
fi
```

期待値:

- runtime 辞書クラスの `fromDataDir` 参照はゼロ件。
- `BinaryStorage` は loader 契約以外の reader 生成詳細を知らない。
- `src/Dictionary` から `IgoModern\Storage` への依存は増えない。
- `Searcher::fromFile` は残るが、辞書ディレクトリからの構築入口ではないことがコメントで明示されている。

## 完了後の次候補

今回の段階では、Build 経路の `Word2IdCategoryIdResolver` はあえて残す。
次の整理候補は次のいずれか。

- `Word2IdCategoryIdResolver` に trie loader を注入し、Build 経路から `FileInputStreamFactory` /
  `PagedByteReaderFactory` の直接生成を外す。
- `Searcher::fromFile()` を Storage loader 経由に閉じ、Build 経路の移行完了後に削除を検討する。
