# 段階1: ByteReader 契約の導入と Dynamic 配列の契約依存化

[辞書ストレージ抽象化ロードマップ](dictionary-storage-next-plan.md)の段階1を実装するための詳細プラン。

## 背景

現状、Binary 層の Dynamic 配列（`IntDynamicArray` と子の `ShortDynamicArray` / `CharDynamicArray`）と
`WordDataReader` は、具象クラス `PagedBinaryReader` をコンストラクタで直接保持し、
`readBytes(int $byteOffset, int $byteLength): string` を呼んでいる。

ロードマップの最終目標は、Binary 層の Dynamic 配列を「小さな読み取り契約」に依存させ、
具象ファイル reader の生成・注入責務を Storage 層へ移すこと（段階2・3）。その前提となる本段階では、
`ByteReader` 契約（interface）を導入し、Dynamic 配列と `WordDataReader` を具象 `PagedBinaryReader`
依存から契約依存へ切り替える。

本段階では振る舞いを一切変えない。`fromFile` ファクトリ内での `PagedBinaryReader` 生成はそのまま残す
（その移動は段階2の責務）。本段階のゴールは「型ヒントを具象から契約へ差し替える」こと。

## 対象範囲

- 段階1のみ。段階2（`PagedBinaryReader` の Storage 移管）・段階3（`FileMappedInputStream` 責務移管）は対象外。
- `WordDataReader` も今回 `ByteReader` 契約へ切り替える（`readBytes` を使う唯一の他コンシューマのため、一貫性を取る）。

## 変更内容

### 1. 新規 `src/Binary/Contract/ByteReader.php`

既存の Contract 群（`IntArray` 等）と同じ `IgoModern\Binary\Contract` namespace に、
バイト読み取り契約を定義する。シグネチャは現行の `PagedBinaryReader::readBytes` と完全一致させる。

```php
<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/** 指定 offset から指定長のバイト列を読み出す契約。 */
interface ByteReader
{
    /** 指定された byte offset から byte length 分のバイト列を返す。 */
    public function readBytes(int $byteOffset, int $byteLength): string;
}
```

### 2. `src/Binary/PagedBinaryReader.php`

`implements ByteReader` を付与する。`readBytes` の実装・シグネチャは既存のままで契約を満たす。

### 3. `src/Binary/IntDynamicArray.php`

コンストラクタの型ヒントを `PagedBinaryReader` → `ByteReader` に変更する。
`fromFile` は `PagedBinaryReader::fromFile()` 生成のまま残す（`PagedBinaryReader` は `ByteReader` を実装するので注入可）。
子クラス `ShortDynamicArray` / `CharDynamicArray` はコンストラクタを再宣言していないため変更不要。
ただし各子クラスの `fromFile()` は段階2まで `PagedBinaryReader::fromFile()` 生成のまま残る。
つまり、本段階では「コンストラクタ注入は契約化し、ファイル factory は具象生成を維持する」切り分けにする。

### 4. `src/Dictionary/WordDataReader.php`

コンストラクタの型ヒントを `PagedBinaryReader` → `ByteReader` に変更する。
`fromFile` 内では引き続き `PagedBinaryReader` を生成するため、`PagedBinaryReader` の `use` は残す。

## TDD 手順

### Red — 契約依存を検証する失敗テストを先に書く

具象 `PagedBinaryReader` ではなく `ByteReader` を実装した test double を注入して動くことを確認するテストを追加する。
これにより「コンストラクタが契約に依存している」ことを直接検証できる。

- 既存 `tests/Binary/ArrayTest.php` に追加する（Dynamic / Memory 配列の挙動が集約されており、匿名 reader double のパターンも流用できるため、ここに集約する）。
  - 内部バイト列に対して `substr($bytes, $byteOffset, $byteLength)` を返す匿名クラス（`ByteReader` 実装）を用意。
  - 必要なら `readBytes()` に渡された `[$byteOffset, $byteLength]` を記録し、`start + idx * byteWidth` が正しいことを assert する。
  - `new IntDynamicArray($fakeReader, $start)` を構築し、`get($idx)` が期待する unpack 結果（4byte `'l'`）を返すことを assert。
  - 同じ fake を使い `ShortDynamicArray`（2byte `'s'`）/ `CharDynamicArray`（2byte `'S'`）の `get()` も検証。
- 既存 `tests/Dictionary/WordDataReaderTest.php`
  - `ByteReader` test double を注入して `readCodeUnitSlice` が動くケースを追加。
  - `readBytes()` に渡る offset が code unit offset の 2 倍、length が code unit 長の 2 倍になることを確認する。

各テストには意図を述べる日本語コメントを付す。匿名クラスの `readBytes()` や補助メソッドにも、
プロジェクト規約どおり処理目的を説明する簡潔なコメントを付ける。

### Green — 最小実装

上記「変更内容」1〜4 を実装し、追加テストを通す。

### Refactor

重複する `ByteReader` test double をテスト用ヘルパー / 共有 fixture に整理（必要なら）。振る舞いは変えない。

## 既存テストの非回帰

`PagedBinaryReaderTest` / `FileMappedInputStreamTest` / `IgoTest`（FileStorage・MemoryStorage 経由の解析一致）が
変更なしで通ること。

## 検証方法

```bash
composer test      # phpunit: 追加テスト + 既存テストが全て green
composer analyze   # phpstan: 契約型への差し替えで型エラーが出ないこと
composer lint      # mago lint --fix
composer format    # mago format
```

- 期待: Dynamic 配列・`WordDataReader` が `ByteReader` 型を受け取り、`PagedBinaryReader` 実装が引き続き注入できる。
- 解析結果（`IgoTest`）が従来と一致し、振る舞いの変化がないこと。

## レビュー指摘と反映方針

### 1. Test double は offset / length を検証できる形にする

単に固定バイト列を返す fake reader では、`IntDynamicArray::get()` や
`WordDataReader::readCodeUnitSlice()` が正しい byte offset / byte length を計算しているかを検証できない。
`ByteReader` test double は内部バイト列から `substr()` で指定範囲を返すか、呼び出し引数を記録して assert する。

### 2. 新規 interface は既存 `src/` と同じファイル形式にする

`ByteReader.php` には `<?php`、`declare(strict_types=1);`、namespace、interface コメント、
method コメントを含める。既存 Contract 群と同様に、契約の目的が分かる短いコメントに留める。

### 3. テスト追加先は既存構成との一貫性を優先する

Dynamic / Memory 配列の既存テストは `tests/Binary/ArrayTest.php` に集約されている。
関連挙動を一箇所で追えるよう、契約依存の検証も `ArrayTest.php` に追加することで確定する。

### 4. コメント規約は匿名クラスにも適用する

プロジェクト規約では、すべてのテスト、関数、クラスメソッドに処理目的のコメントを付ける。
匿名 `ByteReader` の `readBytes()`、constructor、テスト用ヘルパーも例外にしない。
