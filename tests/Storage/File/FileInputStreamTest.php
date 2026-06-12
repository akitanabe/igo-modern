<?php

declare(strict_types=1);

namespace IgoModern\Tests\Storage\File;

use IgoModern\Binary\CharDynamicArray;
use IgoModern\Binary\CharMemoryArray;
use IgoModern\Binary\IntDynamicArray;
use IgoModern\Binary\IntMemoryArray;
use IgoModern\Binary\ShortDynamicArray;
use IgoModern\Binary\ShortMemoryArray;
use IgoModern\Storage\File\ArrayMaterialization;
use IgoModern\Storage\File\FileInputStream;
use IgoModern\Tests\Support\RecordingByteReaderFactory;
use PHPUnit\Framework\TestCase;

/**
 * 辞書バイナリを順次読み込む FileInputStream の挙動を検証するテスト。
 */
class FileInputStreamTest extends TestCase
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
        $stream = FileInputStream::fromFile($this->createBinaryFile($this->packValues('l', [10, -20, 30])));

        $this->assertSame(10, $stream->getInt());
        $this->assertSame([-20, 30], $stream->getIntArray(2));
        $this->assertTrue($stream->close());
    }

    /**
     * size が読み取り対象ファイルのバイトサイズを返すことを確認する。
     */
    public function testSizeReturnsByteLengthOfFile(): void
    {
        $stream = FileInputStream::fromFile($this->createBinaryFile($this->packValues('l', [1, 2, 3])));

        $this->assertSame(12, $stream->size());
        $this->assertTrue($stream->close());
    }

    /**
     * Resident 実体化時は配列インスタンスがストリームから値を読み込んで保持することを確認する。
     */
    public function testArrayInstancesReadIntoMemoryWhenResident(): void
    {
        $stream = FileInputStream::fromFile(
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
        $stream = FileInputStream::fromFile($fileName, ArrayMaterialization::Lazy(), $factory);

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
        $stream = FileInputStream::fromFile(
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
     * Resident 時のチャンク読み込みがチャンク境界をまたいでも全値・順序を正しく返すことを確認する。
     *
     * チャンクサイズを 3 に設定し、5 件（= 1 チャンク + 2 件余り）の読み込みで境界をまたぐ正しさを検証する。
     */
    public function testResidentIntChunkBoundaryPreservesValues(): void
    {
        $values = [1, -2, 3, -4, 5];
        $stream = FileInputStream::fromFile(
            $this->createBinaryFile($this->packValues('l', $values)),
            ArrayMaterialization::Resident(),
            chunkSize: 3,
        );

        $instance = $stream->getIntArrayInstance(5);

        $this->assertInstanceOf(IntMemoryArray::class, $instance);

        foreach ($values as $idx => $expected) {
            $this->assertSame($expected, $instance->get($idx), "index {$idx} mismatch");
        }

        $this->assertTrue($stream->close());
    }

    /**
     * Resident 時のチャンク読み込みが signed short のチャンク境界で値・順序を保つことを確認する。
     *
     * チャンクサイズを 2 にして 5 件読み込み、複数のチャンクにまたがる境界処理を検証する。
     */
    public function testResidentShortChunkBoundaryPreservesValues(): void
    {
        $values = [100, -200, 300, -400, 500];
        $stream = FileInputStream::fromFile(
            $this->createBinaryFile($this->packValues('s', $values)),
            ArrayMaterialization::Resident(),
            chunkSize: 2,
        );

        $instance = $stream->getShortArrayInstance(5);

        $this->assertInstanceOf(ShortMemoryArray::class, $instance);

        foreach ($values as $idx => $expected) {
            $this->assertSame($expected, $instance->get($idx), "index {$idx} mismatch");
        }

        $this->assertTrue($stream->close());
    }

    /**
     * Resident 時のチャンク読み込みが unsigned short のチャンク境界で値・順序を保つことを確認する。
     *
     * チャンクサイズを 2 にして 5 件読み込み、複数のチャンクにまたがる境界処理を検証する。
     */
    public function testResidentCharChunkBoundaryPreservesValues(): void
    {
        $values = [65, 40_000, 65_535, 1, 32_768];
        $stream = FileInputStream::fromFile(
            $this->createBinaryFile($this->packValues('S', $values)),
            ArrayMaterialization::Resident(),
            chunkSize: 2,
        );

        $instance = $stream->getCharArrayInstance(5);

        $this->assertInstanceOf(CharMemoryArray::class, $instance);

        foreach ($values as $idx => $expected) {
            $this->assertSame($expected, $instance->get($idx), "index {$idx} mismatch");
        }

        $this->assertTrue($stream->close());
    }

    /**
     * Resident 時のチャンク読み込みが 0 件でも空の配列インスタンスを返すことを確認する。
     */
    public function testResidentChunkHandlesZeroCount(): void
    {
        $stream = FileInputStream::fromFile(
            $this->createBinaryFile(''),
            ArrayMaterialization::Resident(),
            chunkSize: 3,
        );

        $instance = $stream->getIntArrayInstance(0);

        $this->assertInstanceOf(IntMemoryArray::class, $instance);
        $this->assertTrue($stream->close());
    }

    /**
     * Resident 時のチャンク読み込みが件数がちょうどチャンクサイズの倍数でも正しく動くことを確認する。
     */
    public function testResidentChunkHandlesExactMultipleOfChunkSize(): void
    {
        $values = [10, -20, 30, -40, 50, -60];
        $stream = FileInputStream::fromFile(
            $this->createBinaryFile($this->packValues('l', $values)),
            ArrayMaterialization::Resident(),
            chunkSize: 3,
        );

        $instance = $stream->getIntArrayInstance(6);

        $this->assertInstanceOf(IntMemoryArray::class, $instance);

        foreach ($values as $idx => $expected) {
            $this->assertSame($expected, $instance->get($idx), "index {$idx} mismatch");
        }

        $this->assertTrue($stream->close());
    }

    /**
     * 読み取り元にする一時バイナリファイルを作成する。
     */
    private function createBinaryFile(string $contents): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'igo-fis-');
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
