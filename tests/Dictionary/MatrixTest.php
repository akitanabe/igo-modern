<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary;

use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use PHPUnit\Framework\TestCase;

/**
 * BinaryConnectionMatrix が辞書ディレクトリ内の連接コスト表を読み取る挙動を検証するテスト。
 */
class MatrixTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テストで作成した辞書ディレクトリと matrix.bin を削除して状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $matrixFile = $directory . '/matrix.bin';

            if (is_file($matrixFile)) {
                unlink($matrixFile);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * linkCost が right ID ごとの行に格納された signed short の連接コストを返すことを確認する。
     */
    public function testLinkCostReadsCostByLeftAndRightIds(): void
    {
        $matrix = BinaryConnectionMatrix::fromDataDir($this->createDictionaryDirectory(3, 2, [10, 20, 30, -5, -6, -7]));

        $this->assertSame(10, $matrix->linkCost(0, 0));
        $this->assertSame(30, $matrix->linkCost(2, 0));
        $this->assertSame(-5, $matrix->linkCost(0, 1));
        $this->assertSame(-7, $matrix->linkCost(2, 1));
    }

    /**
     * matrix.bin のヘッダサイズに基づいて、余分な行列要素を参照範囲から外すことを確認する。
     */
    public function testLinkCostUsesHeaderSizesAsMatrixDimensions(): void
    {
        $matrix = BinaryConnectionMatrix::fromDataDir($this->createDictionaryDirectory(2, 3, [1, 2, 3, 4, 5, 6, 999]));

        $this->assertSame(1, $matrix->linkCost(0, 0));
        $this->assertSame(4, $matrix->linkCost(1, 1));
        $this->assertSame(5, $matrix->linkCost(0, 2));
        $this->assertSame(6, $matrix->linkCost(1, 2));
    }

    /**
     * テスト用の辞書ディレクトリを作り、matrix.bin を旧実装と同じバイナリ形式で配置する。
     *
     * @param list<int> $costs
     */
    private function createDictionaryDirectory(int $leftSize, int $rightSize, array $costs): string
    {
        $baseName = tempnam(sys_get_temp_dir(), 'igo-matrix-');
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        $contents = $this->packValues('l', [$leftSize, $rightSize]) . $this->packValues('s', $costs);
        $writtenBytes = file_put_contents($baseName . '/matrix.bin', $contents);
        $this->assertSame(strlen($contents), $writtenBytes);

        return $baseName;
    }

    /**
     * 旧実装と同じ pack 形式で数値列をバイナリ文字列へ変換する。
     *
     * @param list<int> $values
     */
    private function packValues(string $format, array $values): string
    {
        $binary = '';

        foreach ($values as $value) {
            $binary .= pack($format, $value);
        }

        return $binary;
    }
}
