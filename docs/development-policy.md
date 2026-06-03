# 開発方針

## 目的

このプロジェクトの目的は、古い Igo PHP ライブラリを PHP 8 以降の環境に適応させることです。

古いグローバルクラス API との互換性は維持対象にしません。`lib/` 配下の旧実装は挙動を把握するための参照実装として扱い、モダンな実装は `src/` 配下に新しく作成します。

## 主な目標

1. PHP の型を明記する。
2. namespace を適用し、PSR-4 autoload に対応する。
3. 実装を差し替える前に、期待する挙動を PHPUnit テストで固定する。

## ディレクトリ方針

### `lib/`

`lib/` には旧実装を残します。

モダン化作業では `lib/` を修正しません。旧実装の挙動を理解し、characterization test によって仕様として記録するために使用します。

### `src/`

`src/` には PHP 8 向けの新実装を作成します。

クラスは旧来のファイル構成に合わせるのではなく、PSR-4 に対応できるようにクラス単位で分割します。各クラスは適切な namespace を持つ専用ファイルに配置します。

例:

```text
src/Igo.php
src/Tagger.php
src/Morpheme.php
src/ViterbiNode.php
```

初期 namespace はシンプルに次の形とします。

```php
namespace Igo;
```

辞書処理やバイナリ IO など、責務の境界が明確になった場合はサブ namespace の導入を検討します。

## テスト戦略

モダン化は、旧実装のテストを書いてから新実装へ差し替える流れで進めます。

1. `lib/` のクラスに対して characterization test を書く。
2. 旧実装に対してそのテストが通ることを確認する。
3. 同等のクラスを `src/` に作成、または更新する。
4. テスト対象を namespaced な `src/` のクラスへ向ける。
5. 新実装が同じ挙動を満たすことを確認する。

これは通常の TDD をこの移植作業向けに調整した進め方です。

- 旧実装の挙動を記録する characterization test では、追加直後から Green になる場合があります。
- 新しい挙動や構造を追加する場合は、Red、Green、Refactor の流れに従います。

## Composer Autoload 方針

最終的な autoload 設定は PSR-4 を目標にします。

```json
{
    "autoload": {
        "psr-4": {
            "Igo\\": "src/"
        }
    }
}
```

現在の `classmap` 設定は移行期間中の暫定構成です。新実装が `lib/` に依存しなくなった時点で削除します。

## 互換性方針

古い公開 API は互換性維持の対象にしません。

`new Igo()` や `new Morpheme()` のような旧来のグローバルクラス利用を維持するためだけの互換ラッパーは作成しません。新しいコードでは `Igo\Igo` や `Igo\Morpheme` のような namespaced class を使用します。

## 移行順序

依存関係の少ないクラスから移行します。

1. `Morpheme`
2. `ViterbiNode`
3. `IntArray`, `ShortArray`, `CharArray`
4. `KeyStream`
5. `Searcher`
6. `FileMappedInputStream`
7. `Matrix`
8. `CharCategory`
9. `WordDic`
10. `Unknown`
11. `Tagger`
12. `Igo`

`Tagger` と `Igo` は依存するクラスが多いため、後半で移行します。

## コードスタイル方針

新規作成または変更するすべてのテスト、関数、クラスメソッドには、その処理の目的を説明する簡潔なコメントを付けます。

コメントは意図、不変条件、分かりにくい挙動の説明に集中させます。コードをそのまま言い換えるだけのコメントは避けます。

新実装のコードでは `declare(strict_types=1);` を使用し、引数型、戻り値型、適切な typed property を明記します。

## 検証

意味のある変更を行った後は、通常次のコマンドで確認します。

```bash
composer test
composer analyze
```

Composer の autoload 設定を変更した場合は、次のコマンドも実行します。

```bash
composer dump-autoload
```
