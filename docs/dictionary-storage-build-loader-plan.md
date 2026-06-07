# Build 経路の Storage 依存除去 / TrieLoader 契約導入プラン（段階5）

## 目的

[辞書ストレージ抽象化ロードマップ](dictionary-storage-next-plan.md)の段階4で、runtime 辞書の
ディレクトリ構造と reader 生成は Storage loader 側へ集約された。その結果、runtime 辞書層からの
`IgoModern\Storage` 依存はゼロになった。

一方で **Build 経路**には依存が 1 箇所だけ残っている。`Word2IdCategoryIdResolver` が
`IgoModern\Storage\FileInputStreamFactory` / `IgoModern\Storage\PagedByteReaderFactory` を直接 import し、
`Searcher::fromFile()` を自前で組み立てている。

```php
// src/Dictionary/Build/Word2IdCategoryIdResolver.php
use IgoModern\Storage\FileInputStreamFactory;
use IgoModern\Storage\PagedByteReaderFactory;

// ...
Searcher::fromFile(
    $outputDirectory . '/word2id',
    FileInputStreamFactory::lazy(new PagedByteReaderFactory()),
)->eachCommonPrefix($key, 0, $callback);
```

この段階では、**trie loader 契約を Dictionary 層へ導入**し、resolver から Storage 具象の直接生成を外す。
これにより `src/Dictionary`（Build を含む）から `IgoModern\Storage` への依存をゼロにする。
あわせて、trie ファイル形式の読み取りロジックを `Searcher::fromFile()` から `FileTrieLoader` へ移し、
`Searcher::fromFile()` は削除する。`Searcher` はファイル知識を持たず、構築済みの配列を受け取って探索する
純粋クラスに寄せる。

依存方向は引き続き **Binary ← Dictionary ← Storage** とする。

## スコープ

### 対象

- `TrieLoader` 契約を `src/Dictionary/Trie` に追加する（「パスから `Searcher` を復元する」契約）。
- ファイル trie 用実装 `FileTrieLoader` を Storage に追加する。
  - named constructor `forBuild()` で Lazy 実体化を内包する。
  - constructor は `InputStreamFactory` を受け取り、テストや他 loader から再利用できる。
  - 現在 `Searcher::fromFile()` が持つ trie ファイル形式の読み取りロジックを `load()` へ移す。
- `Searcher::fromFile()` を削除し、`Searcher` をファイル知識のない純粋クラスにする。
- `Word2IdCategoryIdResolver` を `TrieLoader` 注入へ切り替え、Storage import を除去する。
- Build factory メソッドへ `TrieLoader` を引き回す。
  - `CharCategoryBuilder::createDefault(TrieLoader $trieLoader)`
  - `DictionaryBuilder::standard(TrieLoader $trieLoader)`
- composition root（`BuildDicCommand::createDefault()`）で `FileTrieLoader::forBuild()` を生成し注入する。
- runtime 側 `FileBinaryDictionaryLoader` の `Searcher::fromFile()` 直接呼び出しも `FileTrieLoader` 経由へ寄せ、
  契約の消費者を 2 箇所（Build resolver / runtime word loader）にして trie 復元を 1 点へ集約する。
- `Searcher::fromFile()` を呼ぶ全箇所（本番・テスト）を `FileTrieLoader` 経由へ移行し、`Searcher::fromFile()` を削除する。

### 対象外

- `CategoryIdResolver` 契約自体の見直しや Build 生成順序の変更。

## 契約の利用箇所と妥当性

新設 `TrieLoader` は単一実装・限定利用になりやすいため、利用箇所を明示しておく。

- 実装: `FileTrieLoader`（Storage、1 実装）
- 消費者:
  - `Word2IdCategoryIdResolver`（Build 経路、word2id を trie として読む）
  - `FileBinaryDictionaryLoader`（runtime 経路、word2id を trie として読む）

`FileTrieLoader` は「単一 trie ファイルを `InputStreamFactory` 経由で読み、`Searcher` へ復元する」という知識の唯一の置き場になり、
runtime と Build の両経路がこれを共有する。これまで `Searcher::fromFile()` が担っていた trie ファイル形式の読み取りは
`FileTrieLoader` に集約され、`Searcher` 自身はファイル知識を持たなくなる。契約は「広い拡張性」ではなく
「Dictionary 層から Storage 具象を外す境界」と「trie 復元ロジックの単一化」として正当化される。

## 方針

### 1. `TrieLoader` 契約を Dictionary に追加する

新規 interface:

- `src/Dictionary/Trie/TrieLoader.php`

```php
namespace IgoModern\Dictionary\Trie;

interface TrieLoader
{
    /**
     * 指定された単一 trie ファイルから探索器を復元する。
     */
    public function load(string $filePath): Searcher;
}
```

契約は `Searcher`（Dictionary 型）を返すため Dictionary 層に置く。Storage が実装し、依存方向は
Dictionary ← Storage を維持する。`Word2IdCategoryIdResolver` は `CategoryIdResolver::resolve()` の引数として
`$outputDirectory` を受け取るため、load 対象パスは呼び出しごとに変わる。よって `TrieLoader::load()` は
load 時にパスを受け取る（dataDir に束縛する `BinaryDictionaryLoader` とは前提が異なる点を明記する）。

### 2. `FileTrieLoader` を Storage に追加する

新規クラス:

- `src/Storage/FileTrieLoader.php`

```php
namespace IgoModern\Storage;

use IgoModern\Binary\Contract\InputStreamFactory;
use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Dictionary\Trie\TrieLoader;

final class FileTrieLoader implements TrieLoader
{
    public function __construct(
        private InputStreamFactory $streams,
    ) {}

    /**
     * Build 経路向けに Lazy 読み込みを内包した loader を組み立てる。
     */
    public static function forBuild(): self
    {
        return new self(FileInputStreamFactory::lazy(new PagedByteReaderFactory()));
    }

    public function load(string $filePath): Searcher
    {
        // 旧 Searcher::fromFile の trie ファイル形式読み取りをここへ移す。
        $stream = $this->streams->open($filePath);

        try {
            $nodeSize = $stream->getInt();
            $tailIndexSize = $stream->getInt();
            $tailSize = $stream->getInt();

            return new Searcher(
                $tailIndexSize,
                $stream->getIntArrayInstance($tailIndexSize),
                $stream->getIntArrayInstance($nodeSize),
                $stream->getShortArrayInstance($tailIndexSize),
                $stream->getCharArrayInstance($nodeSize),
                $stream->getCharArrayInstance($tailSize),
            );
        } finally {
            $stream->close();
        }
    }
}
```

reader pair（Lazy stream + `PagedByteReaderFactory`）の生成は named constructor に閉じ込め、段階4の
`FileBinaryDictionaryLoader::forFileStorage()` などと同じ規定の入口にする。独自 factory を注入したいテストは
constructor を直接使う。trie ファイル形式（先頭 3 つの int ヘッダ + 各配列）の知識は `Searcher::fromFile()` から
そのまま移送し、Storage がファイル形式知識を持つという段階4までの方針と揃える。

`forBuild()` は **Build 経路専用の入口**であり、常に Lazy 実体化になる。runtime 経路はこれを使わない。
runtime には `FileStorage`（Lazy）と `MemoryStorage`（Resident）の 2 つがあり、実体化方針は
`FileBinaryDictionaryLoader::forFileStorage()` / `forMemoryStorage()` が保持する `$this->streams` で決まる。
runtime 側は `forBuild()` ではなく `new FileTrieLoader($this->streams)` で既存の stream を引き継ぎ（方針6）、
trie の内部配列も Lazy / Resident の既存方針を維持する。

### 3. `Word2IdCategoryIdResolver` を `TrieLoader` 注入へ切り替える

`src/Dictionary/Build/Word2IdCategoryIdResolver.php`:

- `IgoModern\Storage\*` の import を削除する。
- constructor で `TrieLoader $trieLoader` を必須依存として受け取る。
- `resolve()` は `$this->trieLoader->load($outputDirectory . '/word2id')->eachCommonPrefix(...)` に変える。

```php
use IgoModern\Dictionary\Trie\TrieLoader;

class Word2IdCategoryIdResolver implements CategoryIdResolver
{
    public function __construct(
        private TrieLoader $trieLoader,
    ) {}

    public function resolve(string $outputDirectory, string $encoding, string $categoryName): int
    {
        // ...
        $this->trieLoader
            ->load($outputDirectory . '/word2id')
            ->eachCommonPrefix($key, 0, $callback);
        // ...
    }
}
```

### 4. Build factory へ `TrieLoader` を引き回す

`CharCategoryBuilder::createDefault()` と `DictionaryBuilder::standard()` は Dictionary/Build にあり、
Storage 具象を組み立てられない。そのため `TrieLoader` を引数で受け取り、resolver へ渡す。

```php
// CharCategoryBuilder
public static function createDefault(TrieLoader $trieLoader): self
{
    return new self(new Word2IdCategoryIdResolver($trieLoader));
}

// DictionaryBuilder
public static function standard(TrieLoader $trieLoader): self
{
    return new self(new WordDictionaryBuilder(), new MatrixBuilder(), CharCategoryBuilder::createDefault($trieLoader));
}
```

### 5. composition root で `FileTrieLoader` を注入する

`src/Console/BuildDicCommand.php`:

- Console は composition root であり、すでに `ParseCommand` が `FileStorage` を import している。
  ここで Storage 具象を生成するのは既存パターンと整合する。

```php
use IgoModern\Storage\FileTrieLoader;

public static function createDefault(): self
{
    return new self(static fn(): DictionaryBuilder => DictionaryBuilder::standard(FileTrieLoader::forBuild()));
}
```

`createDefault()` は引数なしのまま（`FileTrieLoader::forBuild()` を内部生成）なので、
`BuildDicCommandTest::testCreateDefaultReturnsBuildDicCommand()` のシグネチャ追従は不要。

これで `FileInputStreamFactory` / `PagedByteReaderFactory` の直接生成は Build 経路（Dictionary 層）から消え、
Console へ移る。

### 6. runtime word loader を `FileTrieLoader` 経由へ寄せる

`src/Storage/FileBinaryDictionaryLoader.php`:

- `loadWordDictionary()` の `Searcher::fromFile($this->dataDir . '/word2id', $this->streams)` を
  `(new FileTrieLoader($this->streams))->load($this->dataDir . '/word2id')` へ置き換える。
- ここでは `FileTrieLoader::forBuild()` を使わない。`forFileStorage()` / `forMemoryStorage()` が保持する
  `$this->streams`（Lazy / Resident）をそのまま渡すことで、trie の内部配列実体化方針を経路ごとに維持する。
- これにより trie 復元を行う本番コードは `FileTrieLoader` だけになる。

### 7. `Searcher::fromFile()` を削除する

`src/Dictionary/Trie/Searcher.php`:

- `Searcher::fromFile()` を削除する。trie ファイル形式の読み取りは `FileTrieLoader::load()` へ移送済み。
- 不要になった `use IgoModern\Binary\Contract\InputStreamFactory;` を除去する
  （`fromFile` 以外で使っていないことを確認する）。
- `Searcher` は constructor で配列を受け取り探索する純粋クラスになる。クラスコメントから
  「単一 trie ファイルからの復元」に関する記述を外す。

## テスト（TDD: Red → Green → Refactor）

### Red

- `tests/Storage/FileTrieLoaderTest.php`（新規）
  - `FileTrieLoader::forBuild()` が trie ファイルから動作する `Searcher` を復元できることを確認する
    （`eachCommonPrefix` が期待の共通接頭辞を返す）。
  - constructor に独自 `InputStreamFactory` を注入して `load()` できることを確認する。
  - Lazy stream（`forBuild()` 相当）と Resident stream を注入したとき、復元した `Searcher` の内部配列が
    それぞれ Lazy / Resident 実体化になることを確認し、`word.ary.idx` 以外の実体化方針の回帰も検知できるようにする。
- `tests/Dictionary/Build/Word2IdCategoryIdResolverTest.php`
  - `new Word2IdCategoryIdResolver()` 直接生成を `TrieLoader` 注入版へ置換する。
  - constructor が `TrieLoader` を必須とすること（null 既定で隠さないこと）を確認する。
  - `resolve()` のカテゴリ ID 期待値は変更しない。
- `tests/Dictionary/Build/CharCategoryBuilderTest.php`
  - `CharCategoryBuilder::createDefault()` が `TrieLoader` を要求することへ追従する。
- `tests/Dictionary/Build/WordDictionaryBuilderTest.php`
  - `new Word2IdCategoryIdResolver()` 呼び出し（trie ID 取得）を `TrieLoader` 注入版へ置換する。
- `tests/Storage/BinaryDictionaryLoaderTest.php`
  - `DictionaryBuilder::standard()` 呼び出しを `DictionaryBuilder::standard(FileTrieLoader::forBuild())` へ置換する。
- `tests/Console/BuildDicCommandTest.php`
  - `BuildDicCommand::createDefault()` の標準構成が `FileTrieLoader` を注入することへ追従し、CLI 経路の注入漏れを検知する。
- `tests/Dictionary/Trie/SearcherTest.php`
  - `Searcher::fromFile()` による構築を `FileTrieLoader::load()` 経由へ移行する。
  - 探索結果（共通接頭辞・キー数など）の期待値は変更しない。
- `tests/Dictionary/Build/DoubleArrayTrieBuilderTest.php`
  - build 後の trie 読み戻しを `Searcher::fromFile()` 直接から `FileTrieLoader` 経由へ移行する。
- `tests/Dictionary/Build/DictionaryBuilderIntegrationTest.php`
  - word2id の読み戻し（`Searcher::fromFile()`）を `FileTrieLoader` 経由へ移行する。

### Green

- `TrieLoader` interface を追加する。
- `FileTrieLoader` を追加する。
- `Word2IdCategoryIdResolver` を `TrieLoader` 注入へ変更し、Storage import を除去する。
- `CharCategoryBuilder::createDefault()` / `DictionaryBuilder::standard()` へ `TrieLoader` を引き回す。
- `BuildDicCommand::createDefault()` で `FileTrieLoader::forBuild()` を注入する。
- `FileBinaryDictionaryLoader::loadWordDictionary()` を `FileTrieLoader` 経由へ変更する。
- テストの構築・読み戻し呼び出しを loader 経由へ揃える。

### Refactor

- `Searcher::fromFile()` の参照が本番・テストともにゼロであることを確認する。
- trie 復元を行う本番コードが `FileTrieLoader` だけであることを確認する。
- `FileTrieLoader::forBuild()` 以外に reader pair 生成知識が漏れていないことを確認する。
- `Searcher` から `InputStreamFactory` 依存が消えていることを確認する。
- `Word2IdCategoryIdResolver` の import が Binary/Dictionary 契約のみであることを確認する。

## 影響範囲

本番コード:

- `src/Dictionary/Trie/TrieLoader.php`（新規）
- `src/Storage/FileTrieLoader.php`（新規）
- `src/Dictionary/Build/Word2IdCategoryIdResolver.php`
- `src/Dictionary/Build/CharCategoryBuilder.php`
- `src/Dictionary/Build/DictionaryBuilder.php`
- `src/Console/BuildDicCommand.php`
- `src/Storage/FileBinaryDictionaryLoader.php`
- `src/Dictionary/Trie/Searcher.php`（`fromFile()` 削除・import 整理）

テスト:

- `tests/Storage/FileTrieLoaderTest.php`（新規）
- `tests/Dictionary/Build/Word2IdCategoryIdResolverTest.php`
- `tests/Dictionary/Build/CharCategoryBuilderTest.php`
- `tests/Dictionary/Build/WordDictionaryBuilderTest.php`
- `tests/Storage/BinaryDictionaryLoaderTest.php`
- `tests/Console/BuildDicCommandTest.php`
- `tests/Dictionary/Trie/SearcherTest.php`
- `tests/Dictionary/Build/DoubleArrayTrieBuilderTest.php`
- `tests/Dictionary/Build/DictionaryBuilderIntegrationTest.php`

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
# Dictionary 層（Build 含む）から Storage namespace への依存がゼロであること。
if grep -RInF --include='*.php' 'use IgoModern\Storage' src/Dictionary; then
    echo 'Dictionary layer still depends on Storage namespace.' >&2
    exit 1
fi

# Searcher::fromFile への参照が本番・テストともにゼロであること。
if grep -RInE --include='*.php' 'Searcher::fromFile' src tests; then
    echo 'Searcher::fromFile references remain.' >&2
    exit 1
fi
```

期待値:

- `src/Dictionary`（Build 含む）から `IgoModern\Storage` への依存はゼロ件。
- `Searcher::fromFile()` の参照は本番・テストともにゼロ件（trie 復元は `FileTrieLoader` に集約）。
- Build / runtime の両経路が `TrieLoader` を共有し、word2id の trie 読み込みが 1 点に集約されている。

## レビュー追加

### 1. `BuildDicCommand::standard()` は現行コードと命名がずれている

計画書では composition root を `BuildDicCommand::standard()` としているが、現行コードの標準 factory は
`BuildDicCommand::createDefault()` である。さらに CLI 登録経路も `ApplicationFactory` から
`BuildDicCommand::createDefault()` を呼んでいる。

このまま実装すると、`standard()` を新設しても CLI 経路へ反映されない、または
`DictionaryBuilder::standard(TrieLoader $trieLoader)` へ署名変更した後に `createDefault()` が旧呼び出しのまま壊れる
リスクがある。計画の composition root は `BuildDicCommand::createDefault()` に揃えるのが望ましい。

### 2. Lazy / Resident の保持条件を明記した方がよい

`FileTrieLoader::forBuild()` の説明は「Build / runtime の双方で Lazy 読み込み」と読めるが、runtime には
`FileStorage` と `MemoryStorage` があり、`FileBinaryDictionaryLoader::forMemoryStorage()` は Resident stream を使う。

詳細手順の `new FileTrieLoader($this->streams)` という方針自体は妥当である。runtime 経路では
`FileTrieLoader::forBuild()` を使わず、`FileBinaryDictionaryLoader` が保持する `$this->streams` を引き継ぐことで、
`forFileStorage()` は Lazy、`forMemoryStorage()` は Resident という既存の実体化方針を維持する、と明記するとよい。

テストでは、`forFileStorage()` / `forMemoryStorage()` が trie の内部配列実体化方針も Lazy / Resident として維持することを
確認すると、`word.ary.idx` 以外の実体化方針の回帰も検知できる。

### 3. 影響範囲のテスト一覧に漏れがある

`DictionaryBuilder::standard()` に `TrieLoader` 引数を追加する場合、
`tests/Storage/BinaryDictionaryLoaderTest.php` の `DictionaryBuilder::standard()` 呼び出しも更新対象になる。
また `BuildDicCommand::createDefault()` の標準構成が変わるため、CLI 経路の注入漏れを防ぐ目的で
`tests/Console/BuildDicCommandTest.php` も影響範囲に入れておくとよい。

## 完了後の次候補

- `CategoryIdResolver::resolve()` から `$encoding` など未使用に近い引数の整理を検討する。
- `TrieLoader` の他フォーマット実装が必要になった場合の拡張点として再評価する。
