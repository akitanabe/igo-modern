Igo Modern
==========

Igo Modern は、MeCab 由来の辞書フォーマットを利用する形態素解析ライブラリ Igo の PHP 実装 [siahr/igo-php](https://github.com/siahr/igo-php)　をforkしPHP8以降の環境向けに近代化する独立プロジェクトです。

このリポジトリは、単なる fork として旧 API との互換性を維持するものではありません。型付きの PHP コード、namespace、PSR-4 autoload、PHPUnit による挙動保証を前提に、実装を再構成しています。

概要
----

- PHP 8.0 以降を対象にしています。
- Composer による autoload と CLI 実行に対応しています。
- 形態素解析と分かち書きの API を提供します。
- MeCab 互換辞書から Igo 形式辞書を生成できます。
- 解析処理のベンチマーク CLI を提供します。
- 旧 Igo-php の挙動をテストで固定しながら、現代的な実装へ移行しています。
- 旧グローバルクラス API の互換ラッパーは提供しません。

インストール
------------

開発中のリポジトリを利用する場合は、Composer で依存関係をインストールします。

```bash
composer install
```

このパッケージを別プロジェクトから利用する場合は、Composer の通常の手順で依存関係として追加してください。

辞書の準備
----------

Igo Modern は、MeCab 互換辞書から Igo 形式の辞書を生成できます。入力ディレクトリには `*.csv`、`unk.def`、`matrix.def`、`char.def` が必要です。例えば IPADIC などの MeCab 互換辞書を展開し、そのディレクトリを `--input` に指定します。

ローカルリポジトリでは、次の形式で辞書を生成します。

```bash
php bin/igo build-dic \
  --output=dist/igo-dic \
  --input=dist/mecab-ipadic-2.7.0-20070610 \
  --encoding=EUC-JP
```

Composer 経由でインストールした環境では、通常は `vendor/bin/igo` から同じ `build-dic` コマンドを実行できます。

```bash
vendor/bin/igo build-dic \
  --output=/path/to/igo-dic \
  --input=/path/to/mecab-ipadic \
  --encoding=EUC-JP
```

主なオプションは次のとおりです。

- `-o` / `--output`: 生成された Igo 形式辞書を書き込むディレクトリです。
- `-i` / `--input`: MeCab 互換辞書ファイルを含む入力ディレクトリです。
- `-e` / `--encoding`: 入力辞書 CSV と `unk.def` の文字エンコーディングです。
- `-d` / `--delimiter`: CSV 区切り文字です。未指定時は `,` を使用します。

生成後の辞書ディレクトリには、解析時に必要な `word2id`、`word.inf`、`word.dat`、`word.ary.idx`、`matrix.bin`、`char.category`、`code2category` が作成されます。

利用する辞書のライセンスは、辞書配布元の条件に従ってください。

解析 CLI の使い方
-----------------

ローカルリポジトリでは、次の形式で解析できます。

```bash
php bin/igo -d /path/to/igo-dic -i "すもももももももものうち"
```

Composer 経由でインストールした環境では、通常は `vendor/bin/igo` から実行できます。

```bash
vendor/bin/igo -d /path/to/igo-dic -i "すもももももももものうち"
```

解析対象の文字列は `-i` / `--input`、解析対象テキストを含むファイルのパスは `-f` / `--file` で指定します。辞書ディレクトリは `-d` / `--dictionary` で指定します。

出力エンコーディングを明示したい場合は、`--encoding` オプションを使用できます。

```bash
vendor/bin/igo --dictionary=/path/to/igo-dic --file=input.txt --encoding=UTF-8
```

入力エンコーディングが既知の場合は、`--input-encoding` で固定すると parse ごとの自動検出を省略でき、解析が速くなります。

```bash
vendor/bin/igo --dictionary=/path/to/igo-dic --file=input.txt --input-encoding=UTF-8
```

辞書読み込みのページキャッシュ上限は `--page-cache` で調整できます。推奨値は「ページキャッシュの調整」を参照してください。

`parse` は `bin/igo` のデフォルトコマンドです。そのため、上の例のようにコマンド名を省略できます。明示する場合は、次のように実行します。

```bash
vendor/bin/igo parse --dictionary=/path/to/igo-dic --input="すもももももももものうち"
```

解析結果は、1 行ごとに `surface<TAB>feature,start` 形式で出力されます。

ベンチマーク CLI の使い方
-------------------------

解析性能を比較するための開発者向け CLI として、`bin/bench` を提供しています。現時点では Composer の `bin` には公開していないため、リポジトリ内で `php bin/bench` として実行します。

```bash
php bin/bench parse --dictionary=dist/igo-dic
```

既定では、組み込みサンプル `mixed` を 3 回測定し、平均、中央値、p95、最小・最大、スループット、ピークメモリを出力します。Xdebug が有効な環境では数値がぶれやすいため、安定した測定では Xdebug を無効化してください。

```bash
XDEBUG_MODE=off php bin/bench parse \
  --dictionary=dist/igo-dic \
  --storage=memory \
  --sample=news \
  --warmup=1 \
  --iterations=10
```

主なオプションは次のとおりです。

- `-d` / `--dictionary`: 測定に使う Igo 形式辞書ディレクトリです。
- `-r` / `--iterations`: 実測する parse 回数です。未指定時は `3` です。
- `-w` / `--warmup`: 測定前に捨て実行する parse 回数です。未指定時は `0` です。
- `--sample`: 組み込みサンプルです。`short`、`news`、`mixed` を指定できます。
- `-s` / `--storage`: 辞書の読み込み方式です。`file`、`memory` を指定できます。未指定時は `file` です。
- `-i` / `--text`: 測定対象テキストを直接指定します。
- `-f` / `--file`: UTF-8 の測定対象ファイルを指定します。
- `-o` / `--output`: ベンチマークレポートをファイルへ保存します。
- `-m` / `--morpheme-output`: 最後の解析結果を `surface<TAB>feature,start` 形式で保存します。
- `--input-encoding`: 入力エンコーディングを固定し、parse ごとの自動検出を省略します。
- `--page-cache`: `--storage=file` のページキャッシュ上限（reader ごとのページ数）です。未指定時は `512` です。

`--text` と `--file` は同時に指定できません。`--output` と `--morpheme-output` のパスには `{datetime}` を含められます。`{datetime}` は、実行時に `Ymd-His` 形式の時刻へ展開されます。

`--file` に渡すベンチマーク用テキストは、UTF-8 のプレーンテキストです。ヘッダや列区切りは不要です。比較しやすくするため、1 行に 1 つの検索語、商品名、文章などを置く形式を推奨します。

CLI はファイル全体をそのまま解析対象にするため、改行も入力文字列の一部として扱われます。空ファイルは受け付けません。リポジトリ内の `bench/corpus/search.txt` には、検索語・商品名風の入力を 1 行ずつ並べたサンプルがあります。

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

`Igo` インスタンスは、辞書ストレージを `Igo::fromStorage()` に渡して構築します。辞書ディレクトリからストレージを生成するには、`FileStorage::fromDataDir()` を使用します。

形態素解析の例です。

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use IgoModern\Igo;
use IgoModern\Storage\FileStorage;

$storage = FileStorage::fromDataDir('/path/to/igo-dic');
$igo = Igo::fromStorage($storage, 'UTF-8');
$morphemes = $igo->parse('すもももももももものうち');

foreach ($morphemes as $morpheme) {
    echo $morpheme->surface . "\t" . $morpheme->feature . ',' . $morpheme->start . PHP_EOL;
}
```

分かち書きの例です。

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use IgoModern\Igo;
use IgoModern\Storage\FileStorage;

$storage = FileStorage::fromDataDir('/path/to/igo-dic');
$igo = Igo::fromStorage($storage, 'UTF-8');
$surfaces = $igo->wakati('すもももももももものうち');

print_r($surfaces);
```

出力エンコーディングは、`fromStorage()` の第 2 引数で指定します。第 2 引数を省略した場合は、辞書から読み込んだ結果をそのまま返します。入力エンコーディングが既知の場合は、第 3 引数で固定すると parse ごとの自動検出を省略できます。

```php
$storage = FileStorage::fromDataDir('/path/to/igo-dic');
$igo = Igo::fromStorage($storage);
$igoWithUtf8Output = Igo::fromStorage($storage, 'UTF-8');
$igoWithFixedInput = Igo::fromStorage($storage, 'UTF-8', 'UTF-8');
```

辞書ストレージには、辞書配列をアクセスごとに遅延読みする `FileStorage` のほかに、辞書配列をすべてメモリへ常駐させる `MemoryStorage` を選べます。いずれも `fromDataDir()` に同じ辞書ディレクトリを渡します。

```php
use IgoModern\Storage\MemoryStorage;

$storage = MemoryStorage::fromDataDir('/path/to/igo-dic');
$igo = Igo::fromStorage($storage, 'UTF-8');
```

辞書ディレクトリの読み込みに失敗した場合、`FileStorage::fromDataDir()` などのストレージ生成メソッドは例外を送出します。

ページキャッシュの調整
----------------------

`FileStorage` は辞書ファイルを 8KB のページ単位で読み込み、reader ごとに上限付きでキャッシュします。上限ページ数は `FileStorage::fromDataDir()` の第 2 引数（CLI / ベンチでは `--page-cache`）で指定できます。

```php
// メモリ節約重視の例: reader ごとの上限を 32 ページ（約 256KB）にする
$storage = FileStorage::fromDataDir('/path/to/igo-dic', 32);
```

UniDic 辞書と 59KB のコーパスを用いた実測に基づく推奨値は次のとおりです。

- バランス重視（既定値）: `512`。解析速度はこの付近で飽和し、これ以上増やしてもメモリだけが増えます。
- メモリ節約重視: `32`。既定値に比べて解析速度は約 1.3 倍遅くなりますが、ピークメモリを約 50MiB から約 19MiB に抑えられます。`16` 以下では速度が急激に劣化するため推奨しません。

最適値は辞書サイズとアクセスパターンに依存するため、`bin/bench parse --storage=file --page-cache=<N>` で実測して調整してください。

解析で例外を呼び出し側に出したくない場合は、失敗時に `null` を返す `tryParse()`、`tryWakati()` を利用できます。

```php
$storage = FileStorage::fromDataDir('/path/to/igo-dic');
$igo = Igo::fromStorage($storage, 'UTF-8');

$morphemes = $igo->tryParse('すもももももももものうち');

if ($morphemes !== null) {
    // 解析成功時のみ $morphemes を利用する
}
```

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

このリポジトリは、MIT License で配布されている[siahr/igo-php](https://github.com/siahr/igo-php) を fork し、PHP 8 以降の環境向けに再構成・改修したものです。

元実装である Igo-php の著作権表示およびライセンス表示は、MIT License の条件に従い `LICENSE` に保持しています。

また、Igo-php は Java 版 Igo の成果に基づいているため、その旨の表示も `LICENSE` に保持しています。

なお、MeCab 互換辞書や IPADIC などの辞書データは、本リポジトリの MIT License には含まれません。
利用する辞書のライセンスは、各辞書配布元の条件に従ってください。
