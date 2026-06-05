# 辞書ストレージ抽象化計画

## ステータス

未着手（計画のみ）。辞書（単語辞書・未知語辞書・連接コスト行列）をインターフェイス化し、`Tagger` を具象から切り離して任意のストレージへ差し替え可能にする。File/Memory の違いはこの差し替え境界（`DictionaryStorage`）上の2実装（`FileStorage` / `MemoryStorage`）として表現する。

## 背景

現状 `Tagger`（`src/Analysis/Tagger.php`）は辞書の具象クラス `WordDic` / `Unknown` / `Matrix` に直接依存しており、辞書のデータソースを差し替える余地がない。

辞書バイナリの配列は既に `IntArray` / `ShortArray` / `CharArray`（`src/Binary/Contract/`）として抽象化され、`FileMappedInputStream` の `reduce`（遅延読み=`DynamicArray` / 常駐=`MemoryArray`）で実体化方式を選べる。したがって「バイナリ辞書フォーマットの読み取り」は File/Memory で共有でき、違いは配列の実体化だけになる。

本計画では、フォーマット読み取り（共有）とストレージ戦略（差し替え）を分離し、`DictionaryStorage` を辞書ストレージの抽象インターフェイスとして新設する。File/Memory はその実装として表現し、SQLite 等の別ストレージも将来同じ境界へ追加できるようにする。

## 設計

### 名前空間構成

```
Dictionary/
  Contract/                       … 抽象（ストレージ非依存）
    WordDictionary
    UnknownWordDictionary
    ConnectionMatrix
    DictionaryStorage             … 辞書ストレージのファサード interface
  Binary/                         … バイナリ辞書フォーマットの共有リーダ（File/Memory 共通）
    BinaryWordDictionary          (implements Contract\WordDictionary)
    BinaryUnknownWordDictionary   (implements Contract\UnknownWordDictionary)
    BinaryConnectionMatrix        (implements Contract\ConnectionMatrix)
  Storage/                        … DictionaryStorage 実装
    BinaryStorage                 (abstract; implements Contract\DictionaryStorage; trio 構築と getter を共有)
    FileStorage                   (extends BinaryStorage; 遅延読み=DynamicArray)
    MemoryStorage                 (extends BinaryStorage; 常駐=MemoryArray)
```

- バイナリ専用の協力クラス（`Trie/Searcher` ほか `Trie/*`、`CharCategory`、`Category`、`WordDataReader`、`WordDicCallbackCaller`）は**移動しない**。フォーマット読み取りの内部詳細であり、リネームの影響を最小化する。

### インターフェイス（`IgoModern\Dictionary\Contract\`）

```php
interface WordDictionary {
    // 既知語の共通接頭辞探索。一致した語を ViterbiNode として callback へ通知する。
    public function search(array $text, int $start, WordDicCallback $fn): void;
    // wordId に対応する素性データを返す。
    public function wordData(int $wordId): string;
}
interface UnknownWordDictionary {
    // 未知語候補の生成まで自完結で行い、ViterbiNode を callback へ通知する。
    // 不変条件: 通知する ViterbiNode::$wordId は、同一 storage の
    //          WordDictionary::wordData() で解決可能でなければならない。
    public function search(array $text, int $start, WordDicCallback $fn): void;
}
interface ConnectionMatrix {
    public function linkCost(int $leftId, int $rightId): int;
}
interface DictionaryStorage {
    public function wordDictionary(): WordDictionary;
    public function unknownWordDictionary(): UnknownWordDictionary;
    public function connectionMatrix(): ConnectionMatrix;
}
```

- `WordDicCallback`（`src/Dictionary/WordDicCallback.php`）・`ViterbiNode` はストレージ非依存なのでそのまま流用。
- **未知語辞書は自完結**（レビュー指摘①）。`UnknownWordDictionary::search` は `WordDictionary` を引数に取らず、interface に辞書間の協調を露出しない。現状 `WordDic::searchFromTrieId` が担う「trie ID 起点の未知語展開」は public 契約に出さず、binary 内部に閉じる（下記参照）。

### バイナリ実装（`IgoModern\Dictionary\Binary\`）

| 現状 | 新 |
|---|---|
| `WordDic`（`src/Dictionary/WordDic.php`） | `BinaryWordDictionary` |
| `Unknown`（`src/Dictionary/Unknown.php`） | `BinaryUnknownWordDictionary` |
| `Matrix`（`src/Dictionary/Matrix.php`） | `BinaryConnectionMatrix` |

- `BinaryWordDictionary`: コンストラクタは現状どおり配列・協力クラスを受け取る。`callWordRange()` は binary 内部の実装詳細として保持（interface には載せない）。現状の `searchFromTrieId()` は削除し、呼び出し元は `callWordRange()` を直接使う。
- `BinaryUnknownWordDictionary`: コンストラクタで姉妹の `BinaryWordDictionary`（具象）と `CharCategory` を保持し、`search()` 内で `$this->wordDic->callWordRange(...)` を直接呼んで候補生成を完結する（現状 `Unknown::search` が引数で受けていた `WordDic` をフィールド保持へ移す）。
- `WordDicCallbackCaller`（据え置き）と `BinaryUnknownWordDictionary` は、`callWordRange` を呼ぶため**具象 `BinaryWordDictionary` に依存**する（レビュー指摘②の責務分離）。interface `WordDictionary` には `search`/`wordData` だけが載る。
- File/Memory の配列実体化は `FileMappedInputStream` の `reduce`（遅延=true / 常駐=false）で表現する。現状 `reduce` は既定 true で固定だが、各 `fromDataDir`/`fromFile` に内部限定の `bool $reduce`（既定 true）を追加して storage 実装から渡せるようにする。`reduce` は公開 API には出さない。

### ストレージ実装（`Storage\BinaryStorage` 抽象 + `FileStorage` / `MemoryStorage`）

```php
abstract class BinaryStorage implements Contract\DictionaryStorage {
    // 3 実装を保持し getter を提供。
    final protected static function loadTrio(string $dir, bool $reduce): array {
        $word = BinaryWordDictionary::fromDataDir($dir, $reduce);
        $unknown = BinaryUnknownWordDictionary::fromDataDir($dir, $word, $reduce);
        $matrix = BinaryConnectionMatrix::fromDataDir($dir, $reduce);
        return [$word, $unknown, $matrix];
    }
}
final class FileStorage extends BinaryStorage {
    public static function fromDataDir(string $dir): self { /* loadTrio($dir, reduce: true) */ }
}
final class MemoryStorage extends BinaryStorage {
    public static function fromDataDir(string $dir): self { /* loadTrio($dir, reduce: false) */ }
}
```

### 消費側（Tagger / Igo）

- `Tagger` コンストラクタの 3 引数を interface 型へ変更。`parseImpl` の未知語呼び出しは `$this->unknown->search($text, $i, $callback)`（`$wordDic` 引数を撤去）。素性解決 `$this->wordDic->wordData(...)` は不変条件により従来どおり。
- `Tagger::fromStorage(DictionaryStorage $storage, ?string $outputEncoding = null): self` を新設（主入口）。
- `Tagger::fromDataDir(string $dir, ?string $encoding = null): self` は `self::fromStorage(FileStorage::fromDataDir($dir), $encoding)` に委譲（File が既定）。**シグネチャは現状のまま**で互換維持。
- `Igo::fromStorage(DictionaryStorage, ?string)` を追加。`Igo::fromDictDir` / `tryFromDictDir($dir, $encoding)` は**現状シグネチャのまま** Tagger 経由で委譲する。

## 影響ファイル

- 新規 interface: `src/Dictionary/Contract/{WordDictionary,UnknownWordDictionary,ConnectionMatrix,DictionaryStorage}.php`
- 新規 storage: `src/Dictionary/Storage/{BinaryStorage,FileStorage,MemoryStorage}.php`
- 改名/移設: `WordDic`→`Binary/BinaryWordDictionary`、`Unknown`→`Binary/BinaryUnknownWordDictionary`、`Matrix`→`Binary/BinaryConnectionMatrix`
- 内部 `bool $reduce` 追加（公開 API 不変）: `src/Dictionary/Trie/Searcher.php`、`src/Dictionary/CharCategory.php`、および上記 Binary 実装の `fromDataDir`
- 型ヒント/委譲更新: `src/Analysis/Tagger.php`、`src/Igo.php`、`src/Dictionary/WordDicCallbackCaller.php`
- テスト追従（レビュー指摘④の漏れ込み）:
  - `tests/Analysis/TaggerTest.php`、`tests/IgoTest.php`（File/Memory 比較を storage 経由で追加）
  - `tests/Dictionary/WordDicTest.php`（新クラス名へ追従、`MemoryStorage` 経由で `IntMemoryArray` を確認するケースを追加）
  - `tests/Dictionary/UnknownTest.php`（`$wordDic` を factory 注入へ、`search` から引数撤去）
  - `tests/Dictionary/MatrixTest.php`
  - `tests/Dictionary/Build/DictionaryBuilderIntegrationTest.php`、`tests/Dictionary/Build/WordDictionaryBuilderTest.php`、`tests/Dictionary/Build/MatrixBuilderTest.php`
  - `tests/Psr4AutoloadTest.php` は `WordDicCallbackCaller` を**据え置く**ため影響なし（移設しない方針の明示で指摘④に対応）。
  - 旧テストクラス/ファイル名は新クラス名へ追従（最小 churn なら中身更新のみ）。

## TDD / 検証

1. **Red**: バイナリファイルを使わない **fake `DictionaryStorage`**（test double）を組み、`Tagger::fromStorage($fake)->parse(...)` を要求するテストを追加 → 未実装で失敗。
   - レビュー指摘⑥に対応し、**未知語経路も固定**する: 通常辞書にない入力に対し fake `UnknownWordDictionary` が候補を返し、その `wordId` を fake `WordDictionary::wordData()` が解決して `parse()` 結果になるケースを含める。これにより自完結化した未知語契約と「wordId は同一 storage で解決可能」という不変条件を固定する。
2. **Green**: interface 群・`Binary/*`・`Storage/*`・`fromStorage` を実装し、trio を Binary 実装へ改名。既存テストはクラス名・注入形追従で緑へ。
3. **Refactor**: `searchFromTrieId` 撤去、命名整理。
4. File/Memory は storage 差し替えで挙動不変であることを `WordDicTest`（`IntMemoryArray` 確認）・`IgoTest`（FileStorage/MemoryStorage 比較）で確認。
5. コマンド（レビュー指摘⑤: プロジェクト標準の composer スクリプトを使用）:
   - `composer test`（= phpunit）全緑
   - `composer analyze`（= phpstan、メモリ不足時のみ `--memory-limit` 付与）クリーン
   - `composer format`（= mago format）／`composer lint`（= `mago lint --fix`。自動修正が走るため diff を確認）。触ったファイルに新規 error を出さない。

## 留意

- 解析結果を変えないリファクタ。公開 API の入口（`Igo::fromDictDir` / `Tagger::fromDataDir`）はシグネチャ不変。差し替えの正式な拡張点として `fromStorage(DictionaryStorage)` を追加する。
- SQLite 等の実ストレージは本計画のスコープ外（`Storage/SqliteStorage` として同列に追加できる土台を用意するところまで）。動機の弱さ（メモリ/速度）は別途ベンチで判断。

---
（参考: レビュー指摘の対応状況）
- ①未知語の協調 → 自完結化で interface から除去
- ②WordDicCallbackCaller の依存矛盾 → 具象 BinaryWordDictionary 依存に統一、interface は search/wordData のみ
- ③searchUnknown の categoryId 契約 → public 契約から除去（binary 内部 callWordRange へ）
- ④影響ファイル漏れ → WordDictionaryBuilderTest / MatrixBuilderTest を追加、Psr4AutoloadTest は据え置きで無影響を明記
- ⑤検証コマンド → composer スクリプトへ統一
- ⑥Red テストの未知語検証不足 → fake unknown 経路を Red に含める
- ⑦命名 → format(Binary/) と storage(Storage/) を分離。ファサード interface 名を `DictionaryStorage` とし、File/Memory を `FileStorage`/`MemoryStorage` 実装として表現
