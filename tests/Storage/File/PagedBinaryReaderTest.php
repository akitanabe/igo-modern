<?php

declare(strict_types=1);

namespace IgoModern\Tests\Storage\File;

use IgoModern\Storage\File\PagedBinaryReader;
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

        $reader = new PagedBinaryReader($file, 0);
        unset($reader);
    }

    /**
     * ページ上限を超えた際に古いページがキャッシュから追い出されることを確認する。
     *
     * ページサイズ 4・上限 2 ページで 3 ページ分アクセスし、
     * 最初のページ（0）が追い出された後でも正しい内容が読めることを検証する。
     */
    public function testCacheEvictsOldestPageWhenLimitExceeded(): void
    {
        // ページ 0: 'abcd', ページ 1: 'efgh', ページ 2: 'ij'
        $reader = $this->createReader('abcdefghij', pageSize: 4, maxCachedPages: 2);

        // ページ 0 をキャッシュに載せる。
        $this->assertSame('ab', $reader->readBytes(0, 2));
        // ページ 1 をキャッシュに載せる。上限 2 で満杯。
        $this->assertSame('ef', $reader->readBytes(4, 2));
        // ページ 2 にアクセスすると上限超過 → ページ 0 が追い出される。
        $this->assertSame('ij', $reader->readBytes(8, 2));
        // ページ 0 は再ロードされても正しい内容を返すことを確認する。
        $this->assertSame('abcd', $reader->readBytes(0, 4));
    }

    /**
     * キャッシュ上限は maxCachedPages で固定され、無制限に増えないことを確認する。
     *
     * ページ数より多いアクセスをしても、内部キャッシュのエントリ数が上限を超えないことを
     * リフレクションで検証する。
     */
    public function testCacheSizeDoesNotExceedMaxCachedPages(): void
    {
        // 1 文字 1 バイトのファイル、ページサイズ 1 バイト → 各バイトが独立したページ。
        $reader = $this->createReader('abcdefghijklmnopqrstuvwxyz', pageSize: 1, maxCachedPages: 5);

        // 全 26 文字（= 26 ページ）を順次アクセスする。
        for ($i = 0; $i < 26; $i++) {
            $reader->readBytes($i, 1);
        }

        // リフレクションで pageCache の実際のエントリ数を検証する。
        $reflection = new \ReflectionProperty(PagedBinaryReader::class, 'pageCache');
        $reflection->setAccessible(true);
        $cache = $reflection->getValue($reader);

        $this->assertIsArray($cache);
        $this->assertLessThanOrEqual(5, count($cache));
    }

    /**
     * ページ単位に完結するアクセスの fast path が正しい値を返すことを確認する。
     *
     * 要求範囲が 1 ページ内に収まる場合はループを通らずに直接 substr で返すため、
     * 同ページへの複数パターンアクセスで結果が一致することを検証する。
     */
    public function testFastPathReturnsCorrectBytesForSinglePageAccess(): void
    {
        // ページサイズ 8、要求はすべて先頭ページ内に収まる。
        $reader = $this->createReader('abcdefghijklmnop', pageSize: 8);

        // ページ 0 内の各スライスが正しい内容を返すことを確認する。
        $this->assertSame('a', $reader->readBytes(0, 1));
        $this->assertSame('abcdefgh', $reader->readBytes(0, 8));
        $this->assertSame('efg', $reader->readBytes(4, 3));
        $this->assertSame('h', $reader->readBytes(7, 1));
    }

    /**
     * ページ境界をまたぐアクセスで fast path を通らず正しく連結して返すことを確認する。
     *
     * 複数ページにわたる要求はループ（slow path）で処理されるが、結果は不変であることを保証する。
     */
    public function testSlowPathConcatenatesBytesAcrossMultiplePages(): void
    {
        // ページサイズ 3: ページ 0='abc', ページ 1='def', ページ 2='ghi', ページ 3='j'
        $reader = $this->createReader('abcdefghij', pageSize: 3);

        // 3 ページにまたがる要求。
        $this->assertSame('cdefghi', $reader->readBytes(2, 7));
    }

    /**
     * ヒットしたページが再挿入されて追い出されにくくなる simple LRU を確認する。
     *
     * ページ 0 を先にアクセスしたあとページ 1 を挟んでもう一度ページ 0 を再アクセスし、
     * ページ 2 のアクセスで古いページ（ここではページ 1）が追い出されることを検証する。
     */
    public function testHitPageIsPromotedToPreventEarlyEviction(): void
    {
        // ページ 0: 'abcd', ページ 1: 'efgh', ページ 2: 'ij'
        $reader = $this->createReader('abcdefghij', pageSize: 4, maxCachedPages: 2);

        // ページ 0 をキャッシュに載せる。
        $reader->readBytes(0, 2);
        // ページ 1 をキャッシュに載せる（上限 2 で満杯）。
        $reader->readBytes(4, 2);
        // ページ 0 を再アクセス → 再挿入されキューの末尾へ移動する（simple LRU）。
        $reader->readBytes(0, 2);

        // ページ 2 にアクセス → キューの先頭（最古）のページ 1 が追い出される。
        $reader->readBytes(8, 2);

        // キャッシュ内にページ 0（再挿入済み）とページ 2 が残り、ページ 1 が不在であることを確認する。
        $reflection = new \ReflectionProperty(PagedBinaryReader::class, 'pageCache');
        $reflection->setAccessible(true);
        $cache = $reflection->getValue($reader);

        $this->assertIsArray($cache);
        $this->assertArrayHasKey(0, $cache);
        $this->assertArrayHasKey(2, $cache);
        $this->assertArrayNotHasKey(1, $cache);
    }

    /**
     * EOF を超えた読み取りが辞書読み込み失敗として扱われることを確認する。
     */
    public function testReadBytesThrowsOnReadBeyondEof(): void
    {
        $reader = $this->createReader('abc', 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dictionary reading failed.');

        // ファイルは 3 バイトだが offset 10 から読み込もうとする。
        $reader->readBytes(10, 1);
    }

    /**
     * 切り詰められた最終ページ内で要求長に満たない読み取りが、fast path でも失敗として扱われることを確認する。
     *
     * 旧実装（連結ループのみ）は次ページ読み込みで例外になっていた。fast path が短いバイト列を
     * 黙って返さないことを固定する回帰テスト。
     */
    public function testReadBytesThrowsWhenTruncatedLastPageCannotSatisfyLength(): void
    {
        $reader = $this->createReader('abc', 4);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dictionary reading failed.');

        // 要求範囲は名目上 1 ページ目（offset 1..3）に収まるが、実データは 2 バイトしか残っていない。
        $reader->readBytes(1, 3);
    }

    /**
     * 指定ページサイズで一時バイナリファイルを開く reader を直接構築する。
     *
     * @param positive-int $pageSize
     * @param positive-int $maxCachedPages
     */
    private function createReader(
        string $contents,
        int $pageSize = 4,
        int $maxCachedPages = PagedBinaryReader::DEFAULT_MAX_CACHED_PAGES,
    ): PagedBinaryReader {
        $file = fopen($this->createBinaryFile($contents), 'rb');
        $this->assertIsResource($file);

        return new PagedBinaryReader($file, $pageSize, $maxCachedPages);
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
