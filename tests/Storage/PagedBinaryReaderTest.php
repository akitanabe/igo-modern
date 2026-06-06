<?php

declare(strict_types=1);

namespace IgoModern\Tests\Storage;

use IgoModern\Storage\PagedBinaryReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * バイナリファイルを固定サイズページで読み、必要範囲だけ返す reader の挙動を検証するテスト。
 */
class PagedBinaryReaderTest extends TestCase
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
     * readBytes が同一ページ内の指定 byte offset と byte length だけを返すことを確認する。
     */
    public function testReadBytesReturnsSliceWithinPage(): void
    {
        $reader = $this->createReader('abcdefghij', 4);

        $this->assertSame('cde', $reader->readBytes(2, 3));
    }

    /**
     * readBytes がページ境界をまたぐ範囲でも連続したバイト列として返すことを確認する。
     */
    public function testReadBytesReturnsSliceAcrossPageBoundary(): void
    {
        $reader = $this->createReader('abcdefghij', 4);

        $this->assertSame('defgh', $reader->readBytes(3, 5));
    }

    /**
     * readBytes が空範囲ではファイル内容に依存しない空文字列を返すことを確認する。
     */
    public function testReadBytesReturnsEmptyStringForEmptyRange(): void
    {
        $reader = $this->createReader('abc', 4);

        $this->assertSame('', $reader->readBytes(1, 0));
    }

    /**
     * readBytes が負の offset を辞書読み込み失敗として扱うことを確認する。
     */
    public function testReadBytesRejectsNegativeOffset(): void
    {
        $reader = $this->createReader('abc', 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dictionary reading failed.');

        $reader->readBytes(-1, 1);
    }

    /**
     * 不正なページサイズを辞書読み込み失敗として扱うことを確認する。
     */
    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $file = fopen($this->createBinaryFile('abc'), 'rb');
        $this->assertIsResource($file);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dictionary reading failed.');

        new PagedBinaryReader($file, 0);
    }

    /**
     * 指定ページサイズで一時バイナリファイルを開く reader を直接構築する。
     */
    private function createReader(string $contents, int $pageSize): PagedBinaryReader
    {
        $file = fopen($this->createBinaryFile($contents), 'rb');
        $this->assertIsResource($file);

        return new PagedBinaryReader($file, $pageSize);
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
