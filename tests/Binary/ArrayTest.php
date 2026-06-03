<?php

declare(strict_types=1);

use IgoModern\Binary\CharDynamicArray;
use IgoModern\Binary\CharMemoryArray;
use IgoModern\Binary\Contract\CharArrayReader;
use IgoModern\Binary\Contract\IntArrayReader;
use IgoModern\Binary\Contract\ShortArrayReader;
use IgoModern\Binary\IntDynamicArray;
use IgoModern\Binary\IntMemoryArray;
use IgoModern\Binary\ShortDynamicArray;
use IgoModern\Binary\ShortMemoryArray;
use PHPUnit\Framework\TestCase;

/**
 * 辞書バイナリ上の数値配列をメモリまたはファイルから読む実装を検証するテスト。
 */
class ArrayTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ファイルの削除対象を保持する。 */
    private array $temporaryFiles = [];

    /**
     * テストで作成したバイナリファイルを削除してファイルシステム状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $fileName) {
            if (!is_file($fileName)) {
                continue;
            }

            unlink($fileName);
        }
    }

    /**
     * IntMemoryArray が reader から読んだ int 配列を添字指定で返すことを確認する。
     */
    public function testIntMemoryArrayReturnsValuesLoadedFromReader(): void
    {
        $array = new IntMemoryArray($this->createIntReader([5, -7, 13]), 3);

        $this->assertSame(5, $array->get(0));
        $this->assertSame(-7, $array->get(1));
        $this->assertSame(13, $array->get(2));
    }

    /**
     * IntDynamicArray が指定開始位置から 4 バイト単位の signed int を読むことを確認する。
     */
    public function testIntDynamicArrayReturnsValuesLoadedFromFileOffset(): void
    {
        $fileName = $this->createBinaryFile('xx' . $this->packValues('l', [10, -20, 30]));
        $array = new IntDynamicArray($fileName, 2);

        $this->assertSame(10, $array->get(0));
        $this->assertSame(-20, $array->get(1));
        $this->assertSame(30, $array->get(2));
    }

    /**
     * ShortMemoryArray が reader から読んだ signed short 配列を添字指定で返すことを確認する。
     */
    public function testShortMemoryArrayReturnsValuesLoadedFromReader(): void
    {
        $array = new ShortMemoryArray($this->createShortReader([12, -34, 56]), 3);

        $this->assertSame(12, $array->get(0));
        $this->assertSame(-34, $array->get(1));
        $this->assertSame(56, $array->get(2));
    }

    /**
     * ShortDynamicArray が指定開始位置から 2 バイト単位の signed short を読むことを確認する。
     */
    public function testShortDynamicArrayReturnsValuesLoadedFromFileOffset(): void
    {
        $fileName = $this->createBinaryFile('x' . $this->packValues('s', [100, -200, 300]));
        $array = new ShortDynamicArray($fileName, 1);

        $this->assertSame(100, $array->get(0));
        $this->assertSame(-200, $array->get(1));
        $this->assertSame(300, $array->get(2));
    }

    /**
     * CharMemoryArray が reader から読んだ unsigned short 配列を添字指定で返すことを確認する。
     */
    public function testCharMemoryArrayReturnsValuesLoadedFromReader(): void
    {
        $array = new CharMemoryArray($this->createCharReader([65, 40_000, 65_535]), 3);

        $this->assertSame(65, $array->get(0));
        $this->assertSame(40_000, $array->get(1));
        $this->assertSame(65_535, $array->get(2));
    }

    /**
     * CharDynamicArray が指定開始位置から 2 バイト単位の unsigned short を読むことを確認する。
     */
    public function testCharDynamicArrayReturnsValuesLoadedFromFileOffset(): void
    {
        $fileName = $this->createBinaryFile('x' . $this->packValues('S', [65, 40_000, 65_535]));
        $array = new CharDynamicArray($fileName, 1);

        $this->assertSame(65, $array->get(0));
        $this->assertSame(40_000, $array->get(1));
        $this->assertSame(65_535, $array->get(2));
    }

    /**
     * IntMemoryArray の入力元として指定件数の int 値を返す reader を作る。
     *
     * @param list<int> $values
     */
    private function createIntReader(array $values): IntArrayReader
    {
        return new class($values) implements IntArrayReader {
            /**
             * reader が返す値をテスト用に保持する。
             *
             * @param list<int> $values
             */
            public function __construct(
                private array $values,
            ) {}

            /**
             * メモリ配列が要求した件数分だけ値を返す。
             *
             * @return list<int>
             */
            public function getIntArray(int $count): array
            {
                return array_slice($this->values, 0, $count);
            }
        };
    }

    /**
     * ShortMemoryArray の入力元として指定件数の short 値を返す reader を作る。
     *
     * @param list<int> $values
     */
    private function createShortReader(array $values): ShortArrayReader
    {
        return new class($values) implements ShortArrayReader {
            /**
             * reader が返す値をテスト用に保持する。
             *
             * @param list<int> $values
             */
            public function __construct(
                private array $values,
            ) {}

            /**
             * メモリ配列が要求した件数分だけ値を返す。
             *
             * @return list<int>
             */
            public function getShortArray(int $count): array
            {
                return array_slice($this->values, 0, $count);
            }
        };
    }

    /**
     * CharMemoryArray の入力元として指定件数の char 値を返す reader を作る。
     *
     * @param list<int> $values
     */
    private function createCharReader(array $values): CharArrayReader
    {
        return new class($values) implements CharArrayReader {
            /**
             * reader が返す値をテスト用に保持する。
             *
             * @param list<int> $values
             */
            public function __construct(
                private array $values,
            ) {}

            /**
             * メモリ配列が要求した件数分だけ値を返す。
             *
             * @return list<int>
             */
            public function getCharArray(int $count): array
            {
                return array_slice($this->values, 0, $count);
            }
        };
    }

    /**
     * dynamic 配列の読み取り元にする一時バイナリファイルを作成する。
     */
    private function createBinaryFile(string $contents): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'igo-array-');
        $this->assertIsString($fileName);
        $this->temporaryFiles[] = $fileName;

        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);

        return $fileName;
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
