<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\MatrixBuilder;
use IgoModern\Storage\Loader\FileBinaryDictionaryLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MatrixBuilder が matrix.def から runtime BinaryConnectionMatrix 互換の matrix.bin を生成することを検証するテスト。
 */
class MatrixBuilderTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テスト用に作成した入力・出力ディレクトリとファイルを削除し、状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            foreach (['matrix.def', 'matrix.bin', 'matrix.bin.tmp'] as $fileName) {
                $path = $directory . '/' . $fileName;

                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * matrix.def の MeCab 順コスト表を runtime が読む right-major 配列へ変換できることを確認する。
     */
    public function testBuildWritesMatrixBinReadableByRuntimeBinaryConnectionMatrix(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "3 2\n0 0 10\n0 1 -5\n1 0 20\n1 1 -6\n2 0 30\n2 1 -7\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'EUC-JP', ',');

        $matrix = FileBinaryDictionaryLoader::forFileStorage($outputDirectory)->loadConnectionMatrix();
        $this->assertSame(10, $matrix->linkCost(0, 0));
        $this->assertSame(30, $matrix->linkCost(2, 0));
        $this->assertSame(-5, $matrix->linkCost(0, 1));
        $this->assertSame(-7, $matrix->linkCost(2, 1));
    }

    /**
     * 出力ディレクトリが未作成でも build 呼び出し時に作成し、コンストラクタでは I/O しないことを確認する。
     */
    public function testBuildCreatesOutputDirectoryOnlyWhenBuildIsCalled(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createMissingTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0 99\n");

        $builder = new MatrixBuilder();
        $this->assertDirectoryDoesNotExist($outputDirectory);

        $builder->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $this->assertDirectoryExists($outputDirectory);
        $this->assertFileExists($outputDirectory . '/matrix.bin');
    }

    /**
     * matrix.def の文脈 ID が出力配列の期待順序と一致しない場合は parse error として扱うことを確認する。
     */
    public function testBuildFailsWhenContextIdsAreOutOfOrder(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n1 0 20\n0 0 10\n0 1 -5\n1 1 -6\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def line 2 has unexpected context ids.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * 出力 matrix.bin がヘッダ 8 バイト + エントリ数 × 2 バイトちょうどで、余計なバイトを残さないことを確認する。
     */
    public function testBuildWritesExactlySizedBinary(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "3 2\n0 0 10\n0 1 -5\n1 0 20\n1 1 -6\n2 0 30\n2 1 -7\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        // ヘッダ 4+4 バイトに leftSize*rightSize=6 エントリ分の short(2 バイト) が続く。
        $this->assertSame(8 + (3 * 2 * 2), filesize($outputDirectory . '/matrix.bin'));
    }

    /**
     * matrix.def を生バイナリとして読み戻し、left-major 定義が right-major 配列へ正しく転置されることを確認する。
     */
    public function testBuildTransposesCostsIntoRightMajorOrder(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "3 2\n0 0 10\n0 1 -5\n1 0 20\n1 1 -6\n2 0 30\n2 1 -7\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        // ヘッダ 8 バイトを読み飛ばし、index=(rightId*leftSize)+leftId 順に並ぶ short 列を直接検証する。
        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s6costs', $binary));
        $this->assertSame([3, 2, 10, 20, 30, -5, -6, -7], $shorts);
    }

    /**
     * 定義行がヘッダのエントリ数を超える場合は、余剰行を parse する前に entry count 不一致として扱うことを確認する。
     */
    public function testBuildFailsWhenEntryCountExceedsHeader(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // 2x2=4 エントリの宣言に対し 5 行目を余分に与える。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n0 0 10\n0 1 -5\n1 0 20\n1 1 -6\n2 0 99\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def entry count does not match header sizes.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * 定義行がヘッダのエントリ数に満たない場合も entry count 不一致として扱うことを確認する。
     */
    public function testBuildFailsWhenEntryCountIsShortOfHeader(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // 2x2=4 エントリの宣言に対し 3 行しか与えない。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n0 0 10\n0 1 -5\n1 0 20\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def entry count does not match header sizes.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * 空行が混在しても、エラーは詰め直した連番ではなく元ファイルの物理行番号で報告されることを確認する。
     */
    public function testBuildReportsPhysicalLineNumberWhenBlankLinesPresent(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // 4 行目に空行を挟むため、順序違反の物理行番号は 6 になる。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n0 0 10\n0 1 -5\n\n1 1 -6\n1 0 20\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def line 5 has unexpected context ids.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * fallback(タブ区切り)行でも現行の preg ロジックで正しく転置され、fast path と同一結果になることを確認する。
     */
    public function testBuildAcceptsTabSeparatedLinesViaFallback(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // タブ区切りは fast path を外れ fallback の preg_split('/\s+/') で処理される。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n0\t0\t10\n0\t1\t-5\n1\t0\t20\n1\t1\t-6\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s4costs', $binary));
        $this->assertSame([2, 2, 10, 20, -5, -6], $shorts);
    }

    /**
     * 連続空白・前後空白を含む行も fallback で正規化され、fast path と同一の転置結果になることを確認する。
     */
    public function testBuildAcceptsIrregularWhitespaceViaFallback(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // 連続空白・行頭空白・行末空白はいずれも fast path を外れ fallback で処理される。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n0  0  10\n 0 1 -5\n1 0 20 \n1 1 -6\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s4costs', $binary));
        $this->assertSame([2, 2, 10, 20, -5, -6], $shorts);
    }

    /**
     * "007" のような round-trip しない値は fast path を外れても fallback の preg では整数として受理されることを確認する。
     */
    public function testBuildAcceptsZeroPaddedValuesViaFallback(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // cost "007" は (string)(int)"007" !== "007" のため fast path 不採用だが、preg では \d+ にマッチし受理される。
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0 007\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s1costs', $binary));
        $this->assertSame([1, 1, 7], $shorts);
    }

    /**
     * 空白のみの行は fast path に乗せず、現行どおり空行として skip されることを確認する。
     */
    public function testBuildSkipsWhitespaceOnlyLinesLikeBlankLines(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // 2 行目に空白のみの行を挟んでも skip され、順序検証は崩れない。
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n   \n0 0 42\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s1costs', $binary));
        $this->assertSame([1, 1, 42], $shorts);
    }

    /**
     * 不正なフィールド数の行は fast path/fallback の双方を経て、現行と同じ例外メッセージで失敗することを確認する。
     */
    public function testBuildFailsWithUnchangedMessageOnMalformedLine(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // フィールドが 2 つしかない行は fast path を外れ、fallback の preg でも 3 要素にならず例外になる。
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def line 2 must contain left id, right id, and cost.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * 非整数フィールド("abc")の行も現行と同じ例外メッセージで失敗することを確認する。
     */
    public function testBuildFailsWithUnchangedMessageOnNonIntegerField(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // "abc" は fast path round-trip も preg の整数判定も通らない。
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0 abc\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def line 2 must contain left id, right id, and cost.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * 負のコスト値が fast path 経由でも正しく signed short として書き込まれることを確認する。
     */
    public function testBuildWritesNegativeCostThroughFastPath(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0 -12345\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s1costs', $binary));
        $this->assertSame([1, 1, -12_345], $shorts);
    }

    /**
     * 境界値 -32768 / 32767 が fast path で受理され、正しく書き込まれることを確認する。
     */
    public function testBuildAcceptsSignedShortBoundariesThroughFastPath(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 1\n0 0 -32768\n1 0 32767\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s2costs', $binary));
        $this->assertSame([2, 1, -32_768, 32_767], $shorts);
    }

    /**
     * 範囲外コスト(32768)が fast path 経由でも現行と同じ例外メッセージで失敗することを確認する。
     */
    public function testBuildFailsWhenCostExceedsSignedShortRange(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0 32768\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def line 2 cost is outside signed short range.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * 末尾改行のないファイルでも、最終行が carry 経由で 1 行として処理されることを確認する。
     */
    public function testBuildHandlesFileWithoutTrailingNewline(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // 最終行 "1 1 -6" に改行を付けず、fgets 同様に最終行として処理されることを検証する。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n0 0 10\n0 1 -5\n1 0 20\n1 1 -6");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s4costs', $binary));
        $this->assertSame([2, 2, 10, 20, -5, -6], $shorts);
    }

    /**
     * CRLF 改行のファイルでも、各行の末尾 \r が trim/プレフィックス処理を妨げず正しく転置されることを確認する。
     */
    public function testBuildHandlesCarriageReturnNewlines(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // "\r\n" 改行では explode("\n") 後の各行末に "\r" が残るが、fallback の trim で吸収される。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\r\n0 0 10\r\n0 1 -5\r\n1 0 20\r\n1 1 -6\r\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s4costs', $binary));
        $this->assertSame([2, 2, 10, 20, -5, -6], $shorts);
    }

    /**
     * "007" のような zero-padded 値が round 2 で fast path 受理されても、(int) キャストで 7 になることを確認する。
     */
    public function testBuildAcceptsZeroPaddedValuesInFastPath(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // "007" は strspn で \d+ として受理され、(int) "007" === 7 となる(fallback の preg と同一受理言語)。
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0 007\n");

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        /** @var array<int, int> $shorts */
        $shorts = array_values((array) unpack('lleft/lright/s1costs', $binary));
        $this->assertSame([1, 1, 7], $shorts);
    }

    /**
     * チャンク境界をまたぐ大量行でも carry 連結により行が欠落・重複せず正しく転置されることを確認する。
     *
     * 4MiB チャンクを実際にまたぐサイズの matrix.def を生成し、carry ロジックの結合を end-to-end で検証する。
     */
    public function testBuildHandlesLinesCrossingChunkBoundaries(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');

        // left-major 順に left/right を連続させた定義を組み立て、4MiB を十分に超えるサイズにする。
        $leftSize = 600;
        $rightSize = 600;
        $handle = fopen($inputDirectory . '/matrix.def', 'w');
        $this->assertIsResource($handle);
        fwrite($handle, $leftSize . ' ' . $rightSize . "\n");

        for ($left = 0; $left < $leftSize; $left++) {
            $buffer = '';

            for ($right = 0; $right < $rightSize; $right++) {
                // cost を (left*rightSize+right) % 65536 - 32768 で signed short 範囲に収める。
                $cost = ((($left * $rightSize) + $right) % 65_536) - 32_768;
                $buffer .= $left . ' ' . $right . ' ' . $cost . "\n";
            }

            fwrite($handle, $buffer);
        }

        fclose($handle);
        // 入力が 4MiB(チャンクサイズ)を確実に超え、境界跨ぎが発生することを保証する。
        $this->assertGreaterThan(4 * 1024 * 1024, filesize($inputDirectory . '/matrix.def'));

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $binary = (string) file_get_contents($outputDirectory . '/matrix.bin');
        $header = (array) unpack('lleft/lright', substr($binary, 0, 8));
        $this->assertSame($leftSize, $header['left']);
        $this->assertSame($rightSize, $header['right']);

        // 抜き取り検証: right-major offset の数点を計算値と突き合わせ、転置が崩れていないことを確認する。
        foreach ([[0, 0], [599, 599], [123, 321], [200, 0], [0, 599]] as [$left, $right]) {
            $expectedCost = ((($left * $rightSize) + $right) % 65_536) - 32_768;
            $offset = 8 + ((($right * $leftSize) + $left) * 2);
            /** @var array<int, int> $unpacked */
            $unpacked = (array) unpack('s', substr($binary, $offset, 2));
            $this->assertSame($expectedCost, $unpacked[1], sprintf('cost mismatch at (%d,%d)', $left, $right));
        }
    }

    /**
     * チャンク読みでも不正行の例外がファイルの物理行番号で報告されることを確認する。
     */
    public function testBuildReportsAccurateLineNumberWithChunkReading(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        // 5 行目を不正(タブ→fallback 経由でフィールド不足ではなく順序違反)にし、行番号 5 が報告されることを確認する。
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 2\n0 0 10\n0 1 -5\n1 0 20\n0 0 99\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def line 5 has unexpected context ids.');

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');
    }

    /**
     * 指定 prefix の一時ディレクトリを作成し、後片付け対象として記録する。
     */
    private function createTemporaryDirectory(string $prefix): string
    {
        $baseName = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        return $baseName;
    }

    /**
     * 存在しない一時ディレクトリパスを確保し、build が作成する対象として記録する。
     */
    private function createMissingTemporaryDirectory(string $prefix): string
    {
        $baseName = $this->createTemporaryDirectory($prefix);
        rmdir($baseName);

        return $baseName;
    }

    /**
     * テスト入力ファイルを書き込み、期待バイト数が保存されたことを確認する。
     */
    private function writeTextFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);
    }
}
