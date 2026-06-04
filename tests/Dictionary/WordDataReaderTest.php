<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary;

use IgoModern\Dictionary\WordDataReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * word.dat の素性バイト列を必要範囲だけ読む reader の挙動を検証するテスト。
 */
class WordDataReaderTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ファイルの削除対象を保持する。 */
    private array $temporaryFiles = [];

    /**
     * テストで作成した word.dat 相当ファイルを削除して状態を戻す。
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
     * readCodeUnitSlice が UTF-16 code unit オフセットから該当バイト列だけを読むことを確認する。
     */
    public function testReadCodeUnitSliceReturnsFeatureBytesByOffsets(): void
    {
        $reader = WordDataReader::fromFile($this->createWordDataFile($this->packValues('S', [1000, 1001, 2000, 3000])));

        $this->assertSame($this->packValues('S', [1001, 2000]), $reader->readCodeUnitSlice(1, 3));
        $this->assertSame($this->packValues('S', [3000]), $reader->readCodeUnitSlice(3, 4));
    }

    /**
     * readCodeUnitSlice が空範囲ではファイルアクセス結果に依存しない空文字列を返すことを確認する。
     */
    public function testReadCodeUnitSliceReturnsEmptyStringForEmptyRange(): void
    {
        $reader = WordDataReader::fromFile($this->createWordDataFile($this->packValues('S', [1000])));

        $this->assertSame('', $reader->readCodeUnitSlice(1, 1));
    }

    /**
     * readCodeUnitSlice が不正な逆向き範囲を辞書読み込み失敗として扱うことを確認する。
     */
    public function testReadCodeUnitSliceRejectsNegativeLengthRange(): void
    {
        $reader = WordDataReader::fromFile($this->createWordDataFile($this->packValues('S', [1000])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dictionary reading failed.');

        $reader->readCodeUnitSlice(2, 1);
    }

    /**
     * 読み取り元にする word.dat 相当の一時バイナリファイルを作成する。
     */
    private function createWordDataFile(string $contents): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'igo-worddat-');
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
