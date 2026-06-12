<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\DoubleArrayTrieBuilder;
use IgoModern\Dictionary\Trie\CommonPrefixCallback;
use IgoModern\Storage\Loader\FileTrieLoader;
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

        $searcher = FileTrieLoader::forBuild()->load($fileName);
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
     * 内部表現を変えても出力 word2id バイト列が不変であることを golden fixture で固定する。
     *
     * 分岐ノード(ca)・終端負値 base(cat/car)・非空 tail 圧縮(dog→suffix "og")を含む小 fixture を用い、
     * バイトバッファ化リファクタ前後で byte-for-byte 一致することを保証する（破損の早期検出）。
     */
    public function testBuildProducesByteIdenticalGoldenWord2Id(): void
    {
        $fileName = $this->createTemporaryFile();
        (new DoubleArrayTrieBuilder())->build(['cat' => 0, 'car' => 1, 'dog' => 2], $fileName);

        $actual = file_get_contents($fileName);
        $golden = file_get_contents(__DIR__ . '/fixtures/word2id-golden.bin');
        $this->assertIsString($actual);
        $this->assertIsString($golden);
        $this->assertSame(bin2hex($golden), bin2hex($actual));
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

        $searcher = FileTrieLoader::forBuild()->load($fileName);
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
     * PHP が数値文字列キーを整数へ自動変換しても、Searcher が正しく読めることを確認する。
     */
    public function testBuildHandlesNumericStringKeys(): void
    {
        $fileName = $this->createTemporaryFile();
        (new DoubleArrayTrieBuilder())->build([
            '1' => 0,
            '10' => 1,
            'A' => 2,
        ], $fileName);

        $searcher = FileTrieLoader::forBuild()->load($fileName);
        $one = new CapturingPrefixCallback();
        $ten = new CapturingPrefixCallback();

        $searcher->eachCommonPrefix($this->utf16CodeUnits('1'), 0, $one);
        $searcher->eachCommonPrefix($this->utf16CodeUnits('10'), 0, $ten);

        $this->assertSame([['start' => 0, 'offset' => 1, 'id' => 0]], $one->matches);
        $this->assertSame(
            [
                ['start' => 0, 'offset' => 1, 'id' => 0],
                ['start' => 0, 'offset' => 2, 'id' => 1],
            ],
            $ten->matches,
        );
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
     * 長い単一路キー（20000文字の 'a' 繰り返し）が tail に正しく圧縮され、Searcher で読めることを確認する。
     *
     * compressedTail() を再帰から反復実装へ書き換えても tail suffix の内容と
     * Searcher の検索結果が変わらないことを保証する。
     */
    public function testBuildCompressesVeryLongSinglePathKeyIntoTail(): void
    {
        $longKey = str_repeat('a', 20_000);
        $fileName = $this->createTemporaryFile();
        (new DoubleArrayTrieBuilder())->build([
            $longKey => 0,
            'b' => 1,
        ], $fileName);

        $searcher = FileTrieLoader::forBuild()->load($fileName);
        $longMatch = new CapturingPrefixCallback();
        $shortMatch = new CapturingPrefixCallback();

        $searcher->eachCommonPrefix($this->utf16CodeUnits($longKey), 0, $longMatch);
        $searcher->eachCommonPrefix($this->utf16CodeUnits('b'), 0, $shortMatch);

        // tail には 20000 文字（UTF-16LE code unit = 20000 個）分の suffix が入るはず
        $this->assertGreaterThan(0, $this->tailSize($fileName));
        $this->assertSame([['start' => 0, 'offset' => 20_000, 'id' => 0]], $longMatch->matches);
        $this->assertSame([['start' => 0, 'offset' => 1, 'id' => 1]], $shortMatch->matches);
    }

    /**
     * 分岐と単一路が混在するキー集合の word2id バイナリが、旧実装との byte 一致を保つことを確認する。
     *
     * nodesNeedingBase() を反復実装へ書き換えても DFS 前順の訪問順が維持され、
     * 同じ base 割り当て・同じバイナリが生成されることを保証する。
     */
    public function testBuildMixedBranchAndSinglePathProducesSameBinaryAsReference(): void
    {
        // 分岐あり (ca→cat/car) + 単一路 (do→dog) を含む既存 golden fixture と同じキー集合
        $keys = ['cat' => 0, 'car' => 1, 'dog' => 2];

        $fileName = $this->createTemporaryFile();
        (new DoubleArrayTrieBuilder())->build($keys, $fileName);

        $actual = file_get_contents($fileName);
        $golden = file_get_contents(__DIR__ . '/fixtures/word2id-golden.bin');
        $this->assertIsString($actual);
        $this->assertIsString($golden);
        // リファクタ前後で byte-for-byte 一致することを保証する
        $this->assertSame(md5($golden), md5($actual));
    }

    /**
     * 単一路の途中ノードが id を持つ場合（分岐なしの終端ノード）は tail 圧縮される、
     * 途中ノードが id を持ちかつ子もある場合は tail 圧縮されないことを確認する。
     *
     * compressedTail() の戻り値セマンティクスが反復実装でも正確に保たれることを検証する。
     */
    public function testBuildHandlesIdNodeWithAndWithoutChildren(): void
    {
        // 'ab' が終端、'abc' も終端 → 'ab' は id+children あり → tail 圧縮不可 → DA ノード
        // 'a' で分岐する場合: root→a→b(id=0, children=[c])→c(id=1, children=[])
        $fileName = $this->createTemporaryFile();
        (new DoubleArrayTrieBuilder())->build([
            'ab' => 0,
            'abc' => 1,
        ], $fileName);

        $searcher = FileTrieLoader::forBuild()->load($fileName);
        $ab = new CapturingPrefixCallback();
        $abc = new CapturingPrefixCallback();

        $searcher->eachCommonPrefix($this->utf16CodeUnits('abcd'), 0, $ab);
        $searcher->eachCommonPrefix($this->utf16CodeUnits('abc'), 0, $abc);

        $this->assertSame(
            [
                ['start' => 0, 'offset' => 2, 'id' => 0],
                ['start' => 0, 'offset' => 3, 'id' => 1],
            ],
            $ab->matches,
        );
        $this->assertSame(
            [
                ['start' => 0, 'offset' => 2, 'id' => 0],
                ['start' => 0, 'offset' => 3, 'id' => 1],
            ],
            $abc->matches,
        );
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
