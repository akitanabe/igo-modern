<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\DoubleArrayTrieBuilder;
use IgoModern\Dictionary\Trie\CommonPrefixCallback;
use IgoModern\Dictionary\Trie\Searcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DoubleArrayTrieBuilder が Searcher 互換の word2id trie を生成することを検証するテスト。
 */
class DoubleArrayTrieBuilderTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ファイルの削除対象を保持する。 */
    private array $temporaryFiles = [];

    /**
     * テストで作成した word2id ファイルを削除し、ファイルシステム状態を戻す。
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
     * 共通接頭辞を持つキーと未知語カテゴリキーを Searcher が短い順に列挙できることを確認する。
     */
    public function testBuildWritesTrieReadableBySearcher(): void
    {
        $fileName = $this->createTemporaryFile();
        (new DoubleArrayTrieBuilder())->build([
            'A' => 0,
            'AB' => 1,
            "\002SPACE" => 2,
            '猫' => 3,
        ], $fileName);

        $searcher = new Searcher($fileName);
        $latin = new CapturingPrefixCallback();
        $category = new CapturingPrefixCallback();
        $japanese = new CapturingPrefixCallback();

        $searcher->eachCommonPrefix($this->utf16CodeUnits('ABC'), 0, $latin);
        $searcher->eachCommonPrefix($this->utf16CodeUnits("\002SPACE"), 0, $category);
        $searcher->eachCommonPrefix($this->utf16CodeUnits('x猫'), 1, $japanese);

        $this->assertSame(4, $searcher->size());
        $this->assertSame(
            [
                ['start' => 0, 'offset' => 1, 'id' => 0],
                ['start' => 0, 'offset' => 2, 'id' => 1],
            ],
            $latin->matches,
        );
        $this->assertSame([['start' => 0, 'offset' => 6, 'id' => 2]], $category->matches);
        $this->assertSame([['start' => 1, 'offset' => 1, 'id' => 3]], $japanese->matches);
    }

    /**
     * 分岐のない長い suffix は tail 領域へ圧縮し、Searcher 互換のまま読めることを確認する。
     */
    public function testBuildCompressesSinglePathSuffixIntoTail(): void
    {
        $fileName = $this->createTemporaryFile();
        (new DoubleArrayTrieBuilder())->build([
            "\002KANJI" => 0,
            '東京' => 1,
        ], $fileName);

        $searcher = new Searcher($fileName);
        $category = new CapturingPrefixCallback();
        $place = new CapturingPrefixCallback();

        $searcher->eachCommonPrefix($this->utf16CodeUnits("\002KANJI"), 0, $category);
        $searcher->eachCommonPrefix($this->utf16CodeUnits('東京都'), 0, $place);

        $this->assertGreaterThan(0, $this->tailSize($fileName));
        $this->assertSame([['start' => 0, 'offset' => 6, 'id' => 0]], $category->matches);
        $this->assertSame([['start' => 0, 'offset' => 2, 'id' => 1]], $place->matches);
    }

    /**
     * 大きな分岐を含むキー集合でも base 探索が実用的な時間で前進することを確認する。
     */
    public function testBuildPlacesLargeBranchingTrieWithinPracticalTime(): void
    {
        $fileName = $this->createTemporaryFile();
        $startedAt = microtime(true);

        (new DoubleArrayTrieBuilder())->build($this->largeBranchingKeys(), $fileName);

        $this->assertLessThan(3.0, microtime(true) - $startedAt);
    }

    /**
     * 空のキーは Searcher の空文字一致と衝突するため、生成前に拒否することを確認する。
     */
    public function testBuildFailsWhenKeyIsEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trie key must not be empty.');

        (new DoubleArrayTrieBuilder())->build(['' => 0], $this->createTemporaryFile());
    }

    /**
     * Searcher の keySetSize と一致しない飛び番 ID は、検索結果の範囲外参照を招くため拒否する。
     */
    public function testBuildFailsWhenTrieIdsAreNotContiguous(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trie ids must be contiguous from 0.');

        (new DoubleArrayTrieBuilder())->build(['A' => 0, 'B' => 2], $this->createTemporaryFile());
    }

    /**
     * word2id の読み取り元にする一時ファイルパスを確保する。
     */
    private function createTemporaryFile(): string
    {
        $fileName = tempnam(sys_get_temp_dir(), 'igo-word2id-');
        $this->assertIsString($fileName);
        $this->temporaryFiles[] = $fileName;

        return $fileName;
    }

    /**
     * 実辞書に近い広い分岐を持つキー集合を作り、配置探索の退行を観測しやすくする。
     *
     * @return array<string, int>
     */
    private function largeBranchingKeys(): array
    {
        $chars = [
            '亜',
            '唖',
            '娃',
            '阿',
            '哀',
            '愛',
            '挨',
            '姶',
            '逢',
            '葵',
            '茜',
            '穐',
            '悪',
            '握',
            '渥',
            '旭',
            '葦',
            '芦',
            '鯵',
            '梓',
            '圧',
            '斡',
            '扱',
            '宛',
            '姐',
            '虻',
            '飴',
            '絢',
            '綾',
            '鮎',
            '或',
            '粟',
            '袷',
            '安',
            '庵',
            '按',
            '暗',
            '案',
            '闇',
            '以',
        ];
        $keys = [];
        $id = 0;

        foreach ($chars as $first) {
            foreach ($chars as $second) {
                for ($suffix = 0; $suffix < 7; $suffix++) {
                    $keys[$first . $second . $suffix] = $id++;

                    if ($id >= 10_000) {
                        return $keys;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * word2id ヘッダから tail 領域の code unit 数を取り出す。
     */
    private function tailSize(string $fileName): int
    {
        $contents = file_get_contents($fileName);
        $this->assertIsString($contents);
        $header = unpack('lnodeSize/lkeySetSize/ltailSize', $contents);
        $this->assertIsArray($header);

        return $header['tailSize'];
    }

    /**
     * UTF-8 文字列を Searcher と同じ UTF-16LE code unit 配列へ変換する。
     *
     * @return list<int>
     */
    private function utf16CodeUnits(string $key): array
    {
        $values = unpack('S*', mb_convert_encoding($key, 'UTF-16LE', 'UTF-8'));
        $this->assertIsArray($values);

        return array_values($values);
    }
}

/**
 * Searcher から通知された一致結果をテスト検証用に蓄積する。
 */
class CapturingPrefixCallback implements CommonPrefixCallback
{
    /** @var list<array{start:int, offset:int, id:int}> 通知された一致結果を順序付きで保持する。 */
    public array $matches = [];

    /**
     * Searcher から通知された一致範囲と trie ID を記録する。
     */
    public function call(int $start, int $offset, int $id): void
    {
        $this->matches[] = ['start' => $start, 'offset' => $offset, 'id' => $id];
    }
}
