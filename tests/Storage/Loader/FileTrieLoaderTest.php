<?php

declare(strict_types=1);

namespace IgoModern\Tests\Storage\Loader;

use IgoModern\Binary\CharDynamicArray;
use IgoModern\Binary\CharMemoryArray;
use IgoModern\Dictionary\Trie\CommonPrefixCallback;
use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Storage\File\FileInputStreamFactory;
use IgoModern\Storage\File\PagedByteReaderFactory;
use IgoModern\Storage\Loader\FileTrieLoader;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * FileTrieLoader が trie ファイルから Searcher を復元し、実体化方式を引き継ぐことを検証するテスト。
 */
class FileTrieLoaderTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ファイルの削除対象を保持する。 */
    private array $temporaryFiles = [];

    /**
     * テストで作成したバイナリ辞書を削除してファイルシステム状態を戻す。
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
     * forBuild() が trie ファイルから Searcher を復元し、keySetSize を正しく返すことを確認する。
     */
    public function testForBuildLoadsTrieFileIntoSearcher(): void
    {
        $fileName = $this->createDictionaryFile();
        $searcher = FileTrieLoader::forBuild()->load($fileName);

        $this->assertInstanceOf(Searcher::class, $searcher);
        $this->assertSame(2, $searcher->size());
    }

    /**
     * forBuild() が復元した Searcher が共通接頭辞を短い順に通知できることを確認する。
     */
    public function testForBuildSearcherFindsCommonPrefixes(): void
    {
        $fileName = $this->createDictionaryFile();
        $searcher = FileTrieLoader::forBuild()->load($fileName);
        $callback = new CapturingFileTrieCallback();

        $searcher->eachCommonPrefix([10, 20, 30, 99], 0, $callback);

        $this->assertSame(
            [
                ['start' => 0, 'offset' => 1, 'id' => 0],
                ['start' => 0, 'offset' => 3, 'id' => 1],
            ],
            $callback->matches,
        );
    }

    /**
     * constructor に独自 InputStreamFactory を注入して load() できることを確認する。
     */
    public function testConstructorAcceptsCustomInputStreamFactory(): void
    {
        $factory = FileInputStreamFactory::lazy(new PagedByteReaderFactory());
        $fileName = $this->createDictionaryFile();
        $loader = new FileTrieLoader($factory);

        $searcher = $loader->load($fileName);

        $this->assertInstanceOf(Searcher::class, $searcher);
        $this->assertSame(2, $searcher->size());
    }

    /**
     * Lazy stream（forBuild 相当）で復元した Searcher の内部配列が DynamicArray であることを確認する。
     */
    public function testForBuildProducesLazyInternalArrays(): void
    {
        $fileName = $this->createDictionaryFile();
        $searcher = FileTrieLoader::forBuild()->load($fileName);
        $tailProperty = new ReflectionProperty(Searcher::class, 'tail');
        $tailProperty->setAccessible(true);

        $this->assertInstanceOf(CharDynamicArray::class, $tailProperty->getValue($searcher));
    }

    /**
     * Resident stream を注入した FileTrieLoader が MemoryArray を持つ Searcher を復元することを確認する。
     */
    public function testResidentStreamProducesResidentInternalArrays(): void
    {
        $factory = FileInputStreamFactory::resident(new PagedByteReaderFactory());
        $fileName = $this->createDictionaryFile();
        $searcher = (new FileTrieLoader($factory))->load($fileName);
        $tailProperty = new ReflectionProperty(Searcher::class, 'tail');
        $tailProperty->setAccessible(true);

        $this->assertInstanceOf(CharMemoryArray::class, $tailProperty->getValue($searcher));
    }

    /**
     * SearcherTest と同じバイナリ形式の 2 語 double-array trie ファイルを作成する。
     */
    private function createDictionaryFile(): string
    {
        $nodeSize = 41;
        $keySetSize = 2;
        $tailSize = 1;
        $begs = [0, 0];
        $base = array_fill(0, count: $nodeSize, value: 0);
        $lens = [0, 1];
        $chck = array_fill(0, count: $nodeSize, value: 0);
        $tail = [30];

        $base[0] = 1;
        $base[11] = 20;
        $base[20] = -1;
        $base[40] = -2;
        $chck[1] = 1;
        $chck[11] = 10;
        $chck[20] = 0;
        $chck[40] = 20;

        return $this->createBinaryFile(
            $this->packValues('l', [$nodeSize, $keySetSize, $tailSize])
                . $this->packValues('l', $begs)
                . $this->packValues('l', $base)
                . $this->packValues('s', $lens)
                . $this->packValues('S', $chck)
                . $this->packValues('S', $tail),
        );
    }

    /**
     * 読み取り元にする一時バイナリファイルを作成する。
     */
    private function createBinaryFile(string $contents): string
    {
        $fileName = tempnam(sys_get_temp_dir(), prefix: 'igo-trie-loader-');
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

/**
 * FileTrieLoader テストで Searcher から通知された一致結果を蓄積する。
 */
class CapturingFileTrieCallback implements CommonPrefixCallback
{
    /** @var list<array{start:int, offset:int, id:int}> 通知された一致結果を順序付きで保持する。 */
    public array $matches = [];

    /**
     * Searcher から通知された一致範囲と語 ID を記録する。
     */
    public function call(int $start, int $offset, int $id): void
    {
        $this->matches[] = ['start' => $start, 'offset' => $offset, 'id' => $id];
    }
}
