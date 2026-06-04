<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Binary\IntDynamicArray;
use IgoModern\Dictionary\WordDic;
use IgoModern\Dictionary\WordDicCallback;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * WordDic が単語辞書ファイル群から候補ノードと素性データを復元する挙動を検証するテスト。
 */
class WordDicTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テストで作成した辞書ディレクトリと構成ファイルを削除して状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (['word2id', 'word.dat', 'word.ary.idx', 'word.inf'] as $fileName) {
                $path = $directory . '/' . $fileName;

                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * search が trie の共通接頭辞 ID から複数の単語 ID を展開して候補ノードを通知することを確認する。
     */
    public function testSearchExpandsTrieMatchesIntoWordNodes(): void
    {
        $wordDic = new WordDic($this->createDictionaryDirectory());
        $callback = new CapturingWordDicCallback();

        $wordDic->search([10, 20, 30, 99], 0, $callback);

        $this->assertNodeSummaries(
            [
                [0, 0, 1, 100, 1, 2, false],
                [1, 0, 1, -50, 3, 4, false],
                [2, 0, 3, 25, 5, 6, false],
            ],
            $callback->nodeSummaries(),
        );
    }

    /**
     * searchFromTrieId が指定された trie ID の単語範囲を未知語用の長さと空白フラグで通知することを確認する。
     */
    public function testSearchFromTrieIdUsesGivenRangeAndSpaceFlag(): void
    {
        $wordDic = new WordDic($this->createDictionaryDirectory());
        $callback = new CapturingWordDicCallback();

        $wordDic->searchFromTrieId(0, 5, 4, true, $callback);

        $this->assertNodeSummaries(
            [
                [0, 5, 4, 100, 1, 2, true],
                [1, 5, 4, -50, 3, 4, true],
            ],
            $callback->nodeSummaries(),
        );
    }

    /**
     * wordData が word.dat 内の UTF-16 相当バイト列を word.inf のオフセット範囲で切り出すことを確認する。
     */
    public function testWordDataReturnsFeatureBytesByWordOffsets(): void
    {
        $wordDic = new WordDic($this->createDictionaryDirectory());

        $this->assertSame($this->packValues('S', [1000, 1001]), $wordDic->wordData(0));
        $this->assertSame($this->packValues('S', [2000]), $wordDic->wordData(1));
        $this->assertSame($this->packValues('S', [3000, 3001, 3002]), $wordDic->wordData(2));
    }

    /**
     * WordDic が trie ID から単語 ID 範囲を引く indices を PHP 配列ではなく dynamic reader として保持することを確認する。
     */
    public function testWordRangeIndicesAreReadDynamically(): void
    {
        $wordDic = new WordDic($this->createDictionaryDirectory());
        $indicesProperty = new ReflectionProperty(WordDic::class, 'indices');
        $indicesProperty->setAccessible(true);

        $this->assertInstanceOf(IntDynamicArray::class, $indicesProperty->getValue($wordDic));
    }

    /**
     * テスト用の辞書ディレクトリを作り、WordDic が読む 4 ファイルを旧実装と同じ形式で配置する。
     */
    private function createDictionaryDirectory(): string
    {
        $baseName = tempnam(sys_get_temp_dir(), 'igo-worddic-');
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        $this->writeBinaryFile($baseName . '/word2id', $this->createTrieDictionary());
        $this->writeBinaryFile($baseName . '/word.dat', $this->packValues('S', [1000, 1001, 2000, 3000, 3001, 3002]));
        $this->writeBinaryFile($baseName . '/word.ary.idx', $this->packValues('l', [0, 2, 3]));
        $this->writeBinaryFile($baseName . '/word.inf', $this->packValues('l', [0, 2, 3, 6])
            . $this->packValues('s', [1, 3, 5, 0])
            . $this->packValues('s', [2, 4, 6, 0])
            . $this->packValues('s', [100, -50, 25, 0]));

        return $baseName;
    }

    /**
     * 2 つの trie ID を返す小さな double-array trie 辞書をバイナリ形式で作成する。
     */
    private function createTrieDictionary(): string
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

        return (
            $this->packValues('l', [$nodeSize, $keySetSize, $tailSize])
            . $this->packValues('l', $begs)
            . $this->packValues('l', $base)
            . $this->packValues('s', $lens)
            . $this->packValues('S', $chck)
            . $this->packValues('S', $tail)
        );
    }

    /**
     * 指定パスにバイナリファイルを書き込み、全バイトが保存されたことを確認する。
     */
    private function writeBinaryFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);
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

    /**
     * ViterbiNode の主要属性だけを比較し、経路情報に依存せず候補展開を検証する。
     *
     * @param list<array{int, int, int, int, int, int, bool}> $expected
     * @param list<array{int, int, int, int, int, int, bool}> $actual
     */
    private function assertNodeSummaries(array $expected, array $actual): void
    {
        $this->assertSame($expected, $actual);
    }
}

/**
 * WordDic から通知された ViterbiNode を検証しやすい配列形式で蓄積する。
 */
class CapturingWordDicCallback implements WordDicCallback
{
    /** @var list<ViterbiNode> 通知された候補ノードを順序付きで保持する。 */
    private array $nodes = [];

    /**
     * WordDic が見つけた候補ノードを記録する。
     */
    public function call(ViterbiNode $node): void
    {
        $this->nodes[] = $node;
    }

    /**
     * WordDic 単体テストでは候補通知の有無をそのまま空状態として返す。
     */
    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /**
     * 候補ノードの主要属性をテスト比較用の配列へ変換する。
     *
     * @return list<array{int, int, int, int, int, int, bool}>
     */
    public function nodeSummaries(): array
    {
        return array_map(static fn(ViterbiNode $node): array => [
            $node->wordId,
            $node->start,
            $node->length,
            $node->cost,
            $node->leftId,
            $node->rightId,
            $node->isSpace,
        ], $this->nodes);
    }
}
