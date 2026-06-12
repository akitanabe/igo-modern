<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Trie;

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
 * Searcher が double-array trie 辞書から共通接頭辞を列挙する挙動を検証するテスト。
 */
class SearcherTest extends TestCase
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
     * size が辞書に格納されたキー数を返し、ID が double-array trie の負数 ID を復元することを確認する。
     */
    public function testSizeAndIdFollowDictionaryEncoding(): void
    {
        $searcher = FileTrieLoader::forBuild()->load($this->createDictionaryFile());

        $this->assertSame(2, $searcher->size());
        $this->assertSame(0, Searcher::ID(-1));
        $this->assertSame(1, Searcher::ID(-2));
    }

    /**
     * eachCommonPrefix が通常の終端ノードと tail 圧縮ノードの一致を短い順に通知することを確認する。
     *
     * Lazy（fallback）/ Resident（fast）の両経路で同一通知になることを検証する。
     *
     * @dataProvider provideSearchers
     */
    public function testEachCommonPrefixCallsCallbackForTerminalAndTailMatches(callable $loadSearcher): void
    {
        $searcher = $loadSearcher($this->createDictionaryFile());
        $callback = new CapturingCommonPrefixCallback();

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
     * eachCommonPrefix が指定開始位置から読み取り、そこに一致がなければ通知しないことを確認する。
     *
     * Lazy（fallback）/ Resident（fast）の両経路で同一通知になることを検証する。
     *
     * @dataProvider provideSearchers
     */
    public function testEachCommonPrefixUsesStartOffsetAndSkipsMissingPrefix(callable $loadSearcher): void
    {
        $searcher = $loadSearcher($this->createDictionaryFile());
        $callback = new CapturingCommonPrefixCallback();

        $searcher->eachCommonPrefix([99, 10, 20, 30], 1, $callback);
        $searcher->eachCommonPrefix([9, 88], 0, $callback);

        $this->assertSame(
            [
                ['start' => 1, 'offset' => 1, 'id' => 0],
                ['start' => 1, 'offset' => 3, 'id' => 1],
            ],
            $callback->matches,
        );
    }

    /**
     * tail 残り長が不足する場合に tail 一致を通知しないことを両経路で確認する。
     *
     * tail 比較の境界（残り長 < tail 長）で false を返すインライン化の挙動を検証する。
     *
     * @dataProvider provideSearchers
     */
    public function testEachCommonPrefixSkipsTailWhenRemainingKeyIsTooShort(callable $loadSearcher): void
    {
        $searcher = $loadSearcher($this->createDictionaryFile());
        $callback = new CapturingCommonPrefixCallback();

        // [10, 20] までは一致するが tail(=30) の照合に必要な続きがないため tail ノードは通知されない。
        $searcher->eachCommonPrefix([10, 20], 0, $callback);

        $this->assertSame(
            [
                ['start' => 0, 'offset' => 1, 'id' => 0],
            ],
            $callback->matches,
        );
    }

    /**
     * Lazy 経路の Searcher が tail を PHP 配列へ展開せず dynamic reader として保持することを確認する。
     */
    public function testTailIsReadDynamically(): void
    {
        $searcher = FileTrieLoader::forBuild()->load($this->createDictionaryFile());
        $tailProperty = new ReflectionProperty(Searcher::class, 'tail');
        $tailProperty->setAccessible(true);

        $this->assertInstanceOf(CharDynamicArray::class, $tailProperty->getValue($searcher));
    }

    /**
     * Resident 経路の Searcher が tail を常駐メモリ配列として保持し fast 経路を選べることを確認する。
     */
    public function testTailIsResidentForFastPath(): void
    {
        $searcher = $this->loadResidentSearcher($this->createDictionaryFile());
        $tailProperty = new ReflectionProperty(Searcher::class, 'tail');
        $tailProperty->setAccessible(true);

        $this->assertInstanceOf(CharMemoryArray::class, $tailProperty->getValue($searcher));
    }

    /**
     * eachCommonPrefix を Lazy / Resident の両ローダーで試すためのプロバイダ。
     *
     * @return array<string, array{callable(string):Searcher}>
     */
    public static function provideSearchers(): array
    {
        return [
            'lazy(fallback)' => [static fn(string $file): Searcher => FileTrieLoader::forBuild()->load($file)],
            'resident(fast)' => [
                static function (string $file): Searcher {
                    $loader = new FileTrieLoader(FileInputStreamFactory::resident(new PagedByteReaderFactory()));

                    return $loader->load($file);
                },
            ],
        ];
    }

    /**
     * 常駐メモリ実体化で trie を読み込み、fast 経路を通る Searcher を作る。
     */
    private function loadResidentSearcher(string $file): Searcher
    {
        $loader = new FileTrieLoader(FileInputStreamFactory::resident(new PagedByteReaderFactory()));

        return $loader->load($file);
    }

    /**
     * 2 語だけを含む小さな double-array trie 辞書をバイナリ形式で作成する。
     */
    private function createDictionaryFile(): string
    {
        $nodeSize = 41;
        $keySetSize = 2;
        $tailSize = 1;
        $begs = [0, 0];
        $base = array_fill(0, $nodeSize, 0);
        $lens = [0, 1];
        $chck = array_fill(0, $nodeSize, 0);
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
        $fileName = tempnam(sys_get_temp_dir(), 'igo-searcher-');
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
 * Searcher から通知された一致結果をテスト検証用に蓄積する。
 */
class CapturingCommonPrefixCallback implements CommonPrefixCallback
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
