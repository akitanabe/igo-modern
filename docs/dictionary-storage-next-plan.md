# 辞書ストレージ抽象化ロードマップ

## 目的

辞書ストレージ抽象化リファクタリングの次フェーズ以降で目指す、レイヤー間の望ましい依存方向と
その実現に向けた段階を定める。各層の責務を明確に分離し、Binary 層からファイルシステムと
配列実体化ポリシーの知識を取り除くことを最終目標とする。

## 望ましい依存方向

- **Binary 層**: バイナリ値の unpack、IntArray / ShortArray / CharArray、Memory / Dynamic 配列の
  振る舞いを保持する。
- Binary 層の **Dynamic 配列**は、具象ファイル reader ではなく `ByteReader` のような
  **小さな読み取り契約**に依存する。
- **Storage 層**が `PagedBinaryReader` 相当のファイル reader を実装し、Dynamic 配列へ注入する。
- `FileMappedInputStream` 相当の「順次読み込み + 配列実体化ポリシー選択」は、
  Storage 内部の loader として扱う。

## 段階

### 段階1 — ByteReader 契約の導入
辞書層ファクトリ整理後の最初の一歩。`ByteReader` 相当の読み取り契約を導入し、
Dynamic 配列を具象 `PagedBinaryReader` 依存から契約依存へ切り替える。

### 段階2 — ファイル reader の Storage 移管
`PagedBinaryReader` を Storage 内部へ移し、Storage がファイル reader を生成して
Dynamic 配列へ渡すよう構成を変える。

### 段階3 — loader への責務集約
`FileMappedInputStream` の責務（順次読み込み + 実体化ポリシー選択）を Storage 内部 loader へ移し、
Binary namespace からファイルシステム・実体化ポリシーの知識を取り除く。
