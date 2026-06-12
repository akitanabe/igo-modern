<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary;

use IgoModern\Storage\Loader\FileBinaryDictionaryLoader;
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
        $matrix = FileBinaryDictionaryLoader::forFileStorage($this->createDictionaryDirectory(3, 2, [
            10,
            20,
            30,
            -5,
            -6,
            -7,
        ]))->loadConnectionMatrix();

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
        $matrix = FileBinaryDictionaryLoader::forFileStorage($this->createDictionaryDirectory(2, 3, [
            1,
            2,
            3,
            4,
            5,
            6,
            999,
        ]))->loadConnectionMatrix();

        $this->assertSame(1, $matrix->linkCost(0, 0));
        $this->assertSame(4, $matrix->linkCost(1, 1));
        $this->assertSame(5, $matrix->linkCost(0, 2));
        $this->assertSame(6, $matrix->linkCost(1, 2));
    }

    /**
     * MemoryStorage 相当では rawCosts() が matrix.bin の生コスト配列を、leftSize() が行幅を返すことを確認する。
     *
     * Tagger の fast 経路が直接添字参照に使う前提（生配列 + 行幅）を固定する。
     */
    public function testRawCostsExposesResidentMatrixAndLeftSize(): void
    {
        $matrix = FileBinaryDictionaryLoader::forMemoryStorage($this->createDictionaryDirectory(3, 2, [
            10,
            20,
            30,
            -5,
            -6,
            -7,
        ]))->loadConnectionMatrix();

        $this->assertSame(3, $matrix->leftSize());
        $this->assertSame([10, 20, 30, -5, -6, -7], $matrix->rawCosts());
    }

    /**
     * FileStorage 相当（Lazy）では rawCosts() が null を返し、Tagger が fallback 経路へ落ちることを確認する。
     */
    public function testRawCostsReturnsNullForLazyMatrix(): void
    {
        $matrix = FileBinaryDictionaryLoader::forFileStorage($this->createDictionaryDirectory(3, 2, [
            10,
            20,
            30,
            -5,
            -6,
            -7,
        ]))->loadConnectionMatrix();

        $this->assertNull($matrix->rawCosts());
        $this->assertSame(3, $matrix->leftSize());
    }

    /**
     * 常駐メモリと Lazy で linkCost / 生配列直接参照が同一値を返すことを確認し、fast/fallback の等価性を固定する。
     */
    public function testFastAndFallbackLinkCostAreEquivalent(): void
    {
        $costs = [10, 20, 30, -5, -6, -7];

        $resident = FileBinaryDictionaryLoader::forMemoryStorage($this->createDictionaryDirectory(
            3,
            2,
            $costs,
        ))->loadConnectionMatrix();
        $lazy = FileBinaryDictionaryLoader::forFileStorage($this->createDictionaryDirectory(
            3,
            2,
            $costs,
        ))->loadConnectionMatrix();

        $rawCosts = $resident->rawCosts();
        $this->assertNotNull($rawCosts);
        $leftSize = $resident->leftSize();

        // linkCost($a, $b) は内部で get($b * leftSize + $a) を引く。Tagger fast 版が使う
        // 直接添字（行 = 第 2 引数、列 = 第 1 引数）が linkCost と一致することを総当たりで検証する。
        for ($a = 0; $a < 3; $a++) {
            for ($b = 0; $b < 2; $b++) {
                $expected = $lazy->linkCost($a, $b);

                $this->assertSame($expected, $resident->linkCost($a, $b));
                $this->assertSame($expected, $rawCosts[($b * $leftSize) + $a]);
            }
        }
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
