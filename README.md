Igo Modern
==========

Igo Modern は、MeCab 由来の辞書フォーマットを利用する形態素解析ライブラリ
Igo の PHP 実装を、PHP 8 以降の環境向けに近代化する独立プロジェクトです。

このリポジトリは単なる fork として旧 API の互換性を維持するものではなく、
型付きの PHP コード、namespace、PSR-4 autoload、PHPUnit による挙動保証を前提に
再構成しています。[siahr/igo-php](https://github.com/siahr/igo-php)
を旧実装の参照元としていますが、
利用者向けの公開 API は `src/` 配下の `IgoModern\` namespace のクラスです。

概要
----

- PHP 8.0 以降を対象にします。
- Composer による autoload と CLI 実行に対応します。
- 形態素解析と分かち書きの API を提供します。
- MeCab 互換辞書から Igo 形式辞書を生成できます。
- 解析処理のベンチマーク CLI を提供します。
- 旧 Igo-php の挙動をテストで固定しながら、現代的な実装へ移行しています。
- 旧グローバルクラス API の互換ラッパーは提供しません。

インストール
------------

開発中のリポジトリを利用する場合は、依存関係を Composer でインストールします。

```bash
composer install
```

このパッケージを別プロジェクトから利用する場合は、Composer の通常の方法で
依存関係として追加してください。

辞書の準備
----------

Igo Modern は MeCab 互換辞書から Igo 形式の辞書を生成できます。入力ディレクトリには
`*.csv`、`unk.def`、`matrix.def`、`char.def` が必要です。例えば IPADIC などの
MeCab 互換辞書を展開し、そのディレクトリを `--input` に指定します。

ローカルリポジトリでは、次の形式で辞書を生成します。

```bash
php bin/igo build-dic \
  --output=dist/igo-dic \
  --input=dist/mecab-ipadic-2.7.0-20070610 \
  --encoding=EUC-JP
```

Composer 経由でインストールされた環境では、通常は `vendor/bin/igo` から同じ
`build-dic` コマンドを実行できます。

```bash
vendor/bin/igo build-dic \
  --output=/path/to/igo-dic \
  --input=/path/to/mecab-ipadic \
  --encoding=EUC-JP
```

主なオプション:

- `-o` / `--output`: 生成された Igo 形式辞書を書き込むディレクトリです。
- `-i` / `--input`: MeCab 互換辞書ファイルを含む入力ディレクトリです。
- `-e` / `--encoding`: 入力辞書 CSV と `unk.def` の文字エンコーディングです。
- `-d` / `--delimiter`: CSV 区切り文字です。未指定時は `,` を使います。

生成後の辞書ディレクトリには、解析時に必要な `word2id`、`word.inf`、`word.dat`、
`word.ary.idx`、`matrix.bin`、`char.category`、`code2category` が作成されます。
利用する辞書のライセンスは、辞書配布元の条件に従ってください。

解析 CLI の使い方
-----------------

ローカルリポジトリでは、次の形式で解析できます。

```bash
php bin/igo -d /path/to/igo-dic -i "すもももももももものうち"
```

Composer 経由でインストールされた環境では、通常は `vendor/bin/igo` から実行できます。

```bash
vendor/bin/igo -d /path/to/igo-dic -i "すもももももももものうち"
```

解析対象の文字列は `-i` / `--input`、解析対象テキストを含むファイルパスは
`-f` / `--file` で指定します。辞書ディレクトリは `-d` / `--dictionary` で指定します。
出力エンコーディングを明示したい場合は `--encoding` オプション、または旧 CLI 互換の
`IGO_OUTPUT_ENCODING` 環境変数を使用できます。

```bash
vendor/bin/igo --dictionary=/path/to/igo-dic --file=input.txt --encoding=UTF-8
```

`parse` は `bin/igo` のデフォルトコマンドなので、上の例のようにコマンド名を省略できます。
明示する場合は次のように実行します。

```bash
vendor/bin/igo parse --dictionary=/path/to/igo-dic --input="すもももももももものうち"
```

解析結果は、1 行ごとに `surface<TAB>feature,start` 形式で出力されます。

ベンチマーク CLI の使い方
-------------------------

解析性能を比較するための開発者向け CLI として `bin/bench` を提供しています。現時点では
Composer の `bin` には公開していないため、リポジトリ内で `php bin/bench` として実行します。

```bash
php bin/bench parse --dictionary=dist/igo-dic
```

既定では組み込みサンプル `mixed` を 3 回測定し、平均、中央値、p95、最小・最大、
スループット、ピークメモリを出力します。Xdebug が有効な環境では数値がぶれやすいため、
安定した測定では Xdebug を無効化してください。

```bash
XDEBUG_MODE=off php bin/bench parse \
  --dictionary=dist/igo-dic \
  --sample=news \
  --warmup=1 \
  --iterations=10
```

主なオプション:

- `-d` / `--dictionary`: 測定に使う Igo 形式辞書ディレクトリです。
- `-r` / `--iterations`: 実測する parse 回数です。未指定時は `3` です。
- `-w` / `--warmup`: 測定前に捨て実行する parse 回数です。未指定時は `0` です。
- `-s` / `--sample`: 組み込みサンプルです。`short`、`news`、`mixed` を指定できます。
- `-i` / `--text`: 測定対象テキストを直接指定します。
- `-f` / `--file`: UTF-8 の測定対象ファイルを指定します。
- `-o` / `--output`: ベンチマークレポートをファイルへ保存します。
- `-m` / `--morpheme-output`: 最後の解析結果を `surface<TAB>feature,start` 形式で保存します。

`--text` と `--file` は同時に指定できません。`--output` と `--morpheme-output` のパスには
`{datetime}` を含められ、実行時に `Ymd-His` 形式の時刻へ展開されます。

`--file` に渡すベンチマーク用テキストは、UTF-8 のプレーンテキストです。ヘッダや列区切りは
不要で、比較しやすいように 1 行に 1 つの検索語、商品名、文章などを置く形式を推奨します。
CLI はファイル全体をそのまま解析対象にするため、改行も入力文字列の一部として扱われます。
空ファイルは受け付けません。リポジトリ内の `bench/corpus/search.txt` は、検索語・商品名風の
入力を 1 行ずつ並べたサンプルです。

例:

```text
ライオン LED電球60形相当 24本入 2026年モデル
HDMI2.1 ケーブル 8K対応 3m
すもももももももものうち
```

```bash
XDEBUG_MODE=off php bin/bench parse \
  --dictionary=dist/igo-dic \
  --file=bench/corpus/search.txt \
  --warmup=2 \
  --iterations=20 \
  --output=bench/results/parse-{datetime}.txt \
  --morpheme-output=bench/results/morphemes-{datetime}.txt
```

PHP API の使い方
---------------

形態素解析:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use IgoModern\Igo;

$igo = Igo::fromDictDir('/path/to/igo-dic', 'UTF-8');
$morphemes = $igo->parse('すもももももももものうち');

foreach ($morphemes as $morpheme) {
    echo $morpheme->surface . "\t" . $morpheme->feature . ',' . $morpheme->start . PHP_EOL;
}
```

分かち書き:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use IgoModern\Igo;

$igo = Igo::fromDictDir('/path/to/igo-dic', 'UTF-8');
$surfaces = $igo->wakati('すもももももももものうち');

print_r($surfaces);
```

辞書ディレクトリと出力エンコーディングは、`fromDictDir()` に渡します。第 2 引数を省略した場合は、
辞書から読み込んだ結果をそのまま返します。

```php
$igo = Igo::fromDictDir('/path/to/igo-dic');
$igoWithUtf8Output = Igo::fromDictDir('/path/to/igo-dic', 'UTF-8');
```

辞書読み込みや解析で例外を呼び出し側に出したくない場合は、失敗時に `null` を返す
`tryFromDictDir()`、`tryParse()`、`tryWakati()` を利用できます。

```php
$igo = Igo::tryFromDictDir('/path/to/igo-dic', 'UTF-8');

if ($igo !== null) {
    $morphemes = $igo->tryParse('すもももももももものうち');
}
```

開発方針
--------

このプロジェクトは、旧実装の挙動を characterization test で固定してから
`src/` 配下の namespaced class に移行しました。現在の実装は `src/` 配下に集約しており、
旧グローバルクラス API は互換性維持の対象にしていません。

詳細は `docs/migration-policy.md` を参照してください。

検証
----

通常の検証ループは次のとおりです。

```bash
composer test
composer analyze
composer lint
composer format
```

Composer の autoload 設定を変更した場合は、次も実行します。

```bash
composer dump-autoload
```

ライセンス
----------

Igo Modern は MIT License で配布します。詳しくは `LICENSE` を参照してください。

このソフトウェアは [siahr/igo-php](https://github.com/siahr/igo-php) と
Java 版 Igo の成果を参考にしています。辞書生成機能は、Java 版 Igo の
`BuildDic`、辞書生成、double-array trie 生成の実装を参考にし、PHP 8 の型付きコードと
既存 runtime reader のバイナリ契約に合わせて移植しています。
旧 Igo-php のライセンス表記も `LICENSE` にまとめています。
