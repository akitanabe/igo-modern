<?php

declare(strict_types=1);

namespace IgoModern\Tests\Binary;

use IgoModern\Binary\ArrayMaterialization;
use IgoModern\Binary\CharDynamicArray;
use IgoModern\Binary\CharMemoryArray;
use IgoModern\Binary\FileMappedInputStream;
use IgoModern\Binary\IntDynamicArray;
use IgoModern\Binary\IntMemoryArray;
use IgoModern\Binary\ShortDynamicArray;
use IgoModern\Binary\ShortMemoryArray;
use IgoModern\Tests\Support\RecordingByteReaderFactory;
use PHPUnit\Framework\TestCase;

/**
 * 辞書バイナリを順次読み込む FileMappedInputStream の挙動を検証するテスト。
 */
class FileMappedInputStreamTest extends TestCase
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
     * getInt と getIntArray が signed int を現在位置から順に読むことを確認する。
     */
    public function testReadsIntValuesSequentially(): void
    {
        $stream = FileMappedInputStream::fromFile($this->createBinaryFile($this->packValues('l', [10, -20, 30])));

        $this->assertSame(10, $stream->getInt());
        $this->assertSame([-20, 30], $stream->getIntArray(2));
        $this->assertTrue($stream->close());
    }

    /**
     * getShortArray と getCharArray が signed/unsigned short を区別して読むことを確認する。
     */
    public function testReadsShortAndCharValuesSequentially(): void
    {
        $stream = FileMappedInputStream::fromFile($this->createBinaryFile(
            $this->packValues('s', [-1, 200]) . $this->packValues('S', [65, 65_535]),
        ));

        $this->assertSame([-1, 200], $stream->getShortArray(2));
        $this->assertSame([65, 65_535], $stream->getCharArray(2));
        $this->assertTrue($stream->close());
    }

    /**
     * getString が旧実装と同じくバイト数ではなく文字数に 2 を掛けた長さを読むことを確認する。
     */
    public function testGetStringReadsTwoBytesPerCountWithoutAdvancingNumericCursor(): void
    {
        $stream = FileMappedInputStream::fromFile($this->createBinaryFile('abcd' . $this->packValues('l', [99])));

        $this->assertSame('abcd', $stream->getString(2));
        $this->assertSame(99, $stream->getInt());
        $this->assertTrue($stream->close());
    }

    /**
     * Resident 実体化時は配列インスタンスがストリームから値を読み込んで保持することを確認する。
     */
    public function testArrayInstancesReadIntoMemoryWhenResident(): void
    {
        $stream = FileMappedInputStream::fromFile(
            $this->createBinaryFile(
                $this->packValues('l', [10, -20]) . $this->packValues('s', [30, -40])
                    . $this->packValues('S', [50, 60]),
            ),
            ArrayMaterialization::Resident(),
        );

        $ints = $stream->getIntArrayInstance(2);
        $shorts = $stream->getShortArrayInstance(2);
        $chars = $stream->getCharArrayInstance(2);

        $this->assertInstanceOf(IntMemoryArray::class, $ints);
        $this->assertSame(-20, $ints->get(1));
        $this->assertInstanceOf(ShortMemoryArray::class, $shorts);
        $this->assertSame(-40, $shorts->get(1));
        $this->assertInstanceOf(CharMemoryArray::class, $chars);
        $this->assertSame(60, $chars->get(1));
        $this->assertTrue($stream->close());
    }

    /**
     * Lazy 実体化時は配列インスタンスが注入された factory の reader を使い、開始オフセットから必要な値だけ読むことを確認する。
     */
    public function testArrayInstancesReadDynamicallyWhenLazy(): void
    {
        $fileName = $this->createBinaryFile(
            $this->packValues('l', [10, -20]) . $this->packValues('s', [30, -40]) . $this->packValues('S', [50, 60]),
        );
        $factory = new RecordingByteReaderFactory();
        $stream = FileMappedInputStream::fromFile($fileName, ArrayMaterialization::Lazy(), $factory);

        $ints = $stream->getIntArrayInstance(2);
        $shorts = $stream->getShortArrayInstance(2);
        $chars = $stream->getCharArrayInstance(2);

        $this->assertInstanceOf(IntDynamicArray::class, $ints);
        $this->assertSame(-20, $ints->get(1));
        $this->assertInstanceOf(ShortDynamicArray::class, $shorts);
        $this->assertSame(-40, $shorts->get(1));
        $this->assertInstanceOf(CharDynamicArray::class, $chars);
        $this->assertSame(60, $chars->get(1));
        $this->assertTrue($stream->close());
        // Lazy では各 instance 生成ごとに factory->open($fileName) が呼ばれ、reader が注入されることを検証する。
        $this->assertSame([$fileName, $fileName, $fileName], $factory->openedFiles);
    }

    /**
     * Lazy 実体化なのに factory 未注入で配列を生成しようとすると設定漏れとして失敗することを確認する。
     */
    public function testArrayInstanceCreationFailsWhenFactoryMissingOnLazy(): void
    {
        $stream = FileMappedInputStream::fromFile(
            $this->createBinaryFile($this->packValues('l', [10, -20])),
            ArrayMaterialization::Lazy(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dictionary reading failed.');

        try {
            $stream->getIntArrayInstance(2);
        } finally {
            $stream->close();
        }
    }

    /**
     * static helper がファイル全体を int 配列として読み切ることを確認する。
     */
    public function testGetIntArrayFromFileReadsWholeFile(): void
    {
        $fileName = $this->createBinaryFile($this->packValues('l', [7, -8, 9]));

        $this->assertSame([7, -8, 9], FileMappedInputStream::getIntArrayFromFile($fileName));
    }

    /**
     * static helper がファイル全体を UTF-16 相当のバイト列として読み切ることを確認する。
     */
    public function testGetStringFromFileReadsWholeFileAsTwoByteUnits(): void
    {
        $fileName = $this->createBinaryFile('abcdef');

        $this->assertSame('abcdef', FileMappedInputStream::getStringFromFile($fileName));
    }

    /**
     * 読み取り元にする一時バイナリファイルを作成する。
     */
    private function createBinaryFile(string $contents): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'igo-fmis-');
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
