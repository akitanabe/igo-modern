<?php

declare(strict_types=1);

namespace IgoModern\Tests\Storage\File;

use IgoModern\Binary\Contract\InputStream;
use IgoModern\Binary\Contract\InputStreamFactory;
use IgoModern\Binary\IntDynamicArray;
use IgoModern\Binary\IntMemoryArray;
use IgoModern\Storage\File\FileInputStreamFactory;
use IgoModern\Storage\File\PagedByteReaderFactory;
use IgoModern\Tests\Support\RecordingByteReaderFactory;
use PHPUnit\Framework\TestCase;

/**
 * FileInputStreamFactory が実体化方式と ByteReaderFactory を内包して InputStream を生成する挙動を検証するテスト。
 */
class FileInputStreamFactoryTest extends TestCase
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
     * factory が契約型として扱え、open が InputStream 契約を返すことを確認する。
     */
    public function testOpenReturnsInputStreamContract(): void
    {
        $factory = FileInputStreamFactory::lazy(new PagedByteReaderFactory());
        $stream = $factory->open($this->createBinaryFile($this->packValues('l', [1])));

        $this->assertInstanceOf(InputStreamFactory::class, $factory);
        $this->assertInstanceOf(InputStream::class, $stream);
        $this->assertTrue($stream->close());
    }

    /**
     * lazy() で構築した factory は遅延読みの DynamicArray を作ることを確認する。
     */
    public function testLazyFactoryProducesDynamicArrays(): void
    {
        $factory = FileInputStreamFactory::lazy(new PagedByteReaderFactory());
        $stream = $factory->open($this->createBinaryFile($this->packValues('l', [10, -20])));

        try {
            $this->assertInstanceOf(IntDynamicArray::class, $stream->getIntArrayInstance(2));
        } finally {
            $stream->close();
        }
    }

    /**
     * resident() で構築した factory は常駐の MemoryArray を作ることを確認する。
     */
    public function testResidentFactoryProducesMemoryArrays(): void
    {
        $factory = FileInputStreamFactory::resident(new PagedByteReaderFactory());
        $stream = $factory->open($this->createBinaryFile($this->packValues('l', [10, -20])));

        try {
            $this->assertInstanceOf(IntMemoryArray::class, $stream->getIntArrayInstance(2));
        } finally {
            $stream->close();
        }
    }

    /**
     * 注入された ByteReaderFactory が Lazy 配列生成時に対象ファイルへ伝播することを確認する。
     */
    public function testByteReaderFactoryIsPropagatedToDynamicArrays(): void
    {
        $fileName = $this->createBinaryFile($this->packValues('l', [10, -20]));
        $byteReaderFactory = new RecordingByteReaderFactory();
        $stream = FileInputStreamFactory::lazy($byteReaderFactory)->open($fileName);

        try {
            $stream->getIntArrayInstance(2);
        } finally {
            $stream->close();
        }

        $this->assertSame([$fileName], $byteReaderFactory->openedFiles);
    }

    /**
     * 読み取り元にする一時バイナリファイルを作成する。
     */
    private function createBinaryFile(string $contents): string
    {
        $fileName = tempnam(sys_get_temp_dir(), prefix: 'igo-fisf-');
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
