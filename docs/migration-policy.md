# 移行方針

> この移行計画は完了済みです。移行順序に記載した対象はすべて `src/` 配下へ移行され、旧参照実装の `lib/` は削除されています。

## 移行目的

このプロジェクトの目的は、古い Igo PHP ライブラリを PHP 8 以降の環境へ段階的に移行することです。

古いグローバルクラス API との互換性は維持対象にしません。移行作業中は旧実装を参照実装として扱い、PHP 8 向けの移行先実装は `src/` 配下に新しく作成しました。

## 移行目標

1. PHP の型を明記する。
2. namespace を適用し、PSR-4 autoload に対応する。
3. 実装を差し替える前に、期待する挙動を PHPUnit テストで固定する。

## 移行先ディレクトリ

### 旧参照実装

移行作業中は [siahr/igo-php](https://github.com/siahr/igo-php) 由来の旧実装を `lib/` に置き、修正せずに参照しました。旧実装は挙動を理解し、characterization test によって移行対象の仕様として記録するために使用しました。移行完了後は、リポジトリの実装を `src/` に集約するため `lib/` を削除しています。

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

初期 namespace は、非公式の近代化版であることを示すために次の形とします。

```php
namespace IgoModern;
```

辞書処理やバイナリ IO など、責務の境界が明確になった場合はサブ namespace の導入を検討します。

## 移行テスト戦略

移行は、旧実装の挙動をテストで固定してから新実装へ差し替える流れで進めます。

1. [siahr/igo-php](https://github.com/siahr/igo-php) 由来の `lib/` のクラスに対して characterization test を書く。
2. 旧実装に対してそのテストが通ることを確認する。
3. 同等のクラスを `src/` に作成、または更新する。
4. テスト対象を namespaced な `src/` のクラスへ向ける。
5. 新実装が同じ挙動を満たすことを確認する。

これは通常の TDD をこの移行作業向けに調整した進め方です。

- 旧実装の挙動を記録する characterization test では、追加直後から Green になる場合があります。
- 新しい挙動や構造を追加する場合は、Red、Green、Refactor の流れに従います。

## Composer Autoload 移行方針

最終的な autoload 設定は PSR-4 を目標にします。

```json
{
    "autoload": {
        "psr-4": {
            "IgoModern\\": "src/"
        }
    }
}
```

移行期間中は `src/` の PSR-4 autoload と、参照実装を読むための `lib/` classmap を併用しました。新実装が `lib/` に依存しなくなったため、現在は `src/` の PSR-4 autoload のみを使用します。

## 互換性の扱い

古い公開 API は互換性維持の対象にしません。

`new Igo()` や `new Morpheme()` のような旧来のグローバルクラス利用を維持するためだけの互換ラッパーは作成しません。新しいコードでは `IgoModern\Igo` や `IgoModern\Morpheme` のような namespaced class を使用します。

## 移行順序

依存関係の少ないクラスから移行します。

1. [x] `Morpheme`
2. [x] `ViterbiNode`
3. [x] `IntArray`, `ShortArray`, `CharArray`
4. [x] `KeyStream`
5. [x] `Searcher`
6. [x] `FileMappedInputStream`
7. [x] `Matrix`
8. [x] `CharCategory`
9. [x] `WordDic`
10. [x] `Unknown`
11. [x] `Tagger`
12. [x] `Igo`

上記の移行対象はすべて `src/` 配下へ移行済みです。`Tagger` と `Igo` は依存するクラスが多いため後半で移行し、現在は `IgoModern\Igo` を公開ファサード、`IgoModern\Tagger` を解析処理の実体として分離しています。

## 移行後コードスタイル

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
