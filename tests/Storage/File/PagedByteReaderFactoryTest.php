<?php

declare(strict_types=1);

namespace IgoModern\Tests\Storage\File;

use IgoModern\Binary\Contract\ByteReader;
use IgoModern\Storage\File\PagedBinaryReader;
use IgoModern\Storage\File\PagedByteReaderFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 指定ファイルを開きページ読み込み reader を生成するファクトリの挙動を検証するテスト。
 */
class PagedByteReaderFactoryTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ファイルの削除対象を保持する。 */
    private array $temporaryFiles = [];

    /**
     * テストで作成したバイナリファイルを削除して状態を戻す。
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
     * open が ByteReader を返し、生成した reader が正しいスライスを読めることを確認する。
     */
    public function testOpenReturnsByteReaderThatReadsSlice(): void
    {
        $reader = (new PagedByteReaderFactory())->open($this->createBinaryFile('abcdefghij'));

        $this->assertInstanceOf(ByteReader::class, $reader);
        $this->assertSame('cde', $reader->readBytes(2, 3));
    }

    /**
     * open が開けないファイルを辞書読み込み失敗として扱うことを確認する。
     */
    public function testOpenRejectsUnreadableFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dictionary reading failed.');

        // 欠損ファイルでは fopen が警告を出すため、警告を捨てて辞書読み込み失敗の例外だけを検証する。
        set_error_handler(static fn(): bool => true);

        try {
            (new PagedByteReaderFactory())->open(sys_get_temp_dir() . '/igo-missing-' . uniqid());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * 同一ファイルへ複数回 open すると、互いに独立したハンドルの reader が得られることを確認する。
     */
    public function testOpenReturnsIndependentReadersForSameFile(): void
    {
        $factory = new PagedByteReaderFactory();
        $fileName = $this->createBinaryFile('abcdefghij');

        $first = $factory->open($fileName);
        $second = $factory->open($fileName);

        $this->assertNotSame($first, $second);
        // 一方を破棄してもハンドルが共有されていなければ他方は読み続けられる。
        unset($first);
        $this->assertSame('fgh', $second->readBytes(5, 3));
    }

    /**
     * 生成される具象が移管後の Storage 名前空間の PagedBinaryReader であることを確認する。
     */
    public function testOpenReturnsPagedBinaryReader(): void
    {
        $reader = (new PagedByteReaderFactory())->open($this->createBinaryFile('abc'));

        $this->assertInstanceOf(PagedBinaryReader::class, $reader);
    }

    /**
     * 読み取り元にする一時バイナリファイルを作成する。
     */
    private function createBinaryFile(string $contents): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'igo-paged-');
        $this->assertIsString($fileName);
        $this->temporaryFiles[] = $fileName;

        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);

        return $fileName;
    }
}
