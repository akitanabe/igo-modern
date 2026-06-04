<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\MatrixBuilder;
use IgoModern\Dictionary\Matrix;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MatrixBuilder が matrix.def から runtime Matrix 互換の matrix.bin を生成することを検証するテスト。
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
            foreach (['matrix.def', 'matrix.bin'] as $fileName) {
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
     * matrix.def のヘッダと right-major な連接コスト表から matrix.bin を生成できることを確認する。
     */
    public function testBuildWritesMatrixBinReadableByRuntimeMatrix(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-matrix-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-matrix-out-');
        $this->writeTextFile(
            $inputDirectory . '/matrix.def',
            "3 2\n0 0 10\n1 0 20\n2 0 30\n0 1 -5\n1 1 -6\n" . "2 1 -7\n",
        );

        (new MatrixBuilder())->build($outputDirectory, $inputDirectory, 'EUC-JP', ',');

        $matrix = new Matrix($outputDirectory);
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
        $this->writeTextFile($inputDirectory . '/matrix.def', "2 1\n1 0 20\n0 0 10\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matrix.def line 2 has unexpected context ids.');

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
