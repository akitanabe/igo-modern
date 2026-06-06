<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Dictionary\WordDicCallback;
use IgoModern\Storage\FileBinaryDictionaryLoader;
use PHPUnit\Framework\TestCase;

/**
 * BinaryUnknownWordDictionary が文字カテゴリ定義に従って未知語候補を展開する挙動を検証するテスト。
 */
class UnknownTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テストで作成した辞書ディレクトリと構成ファイルを削除して状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach ([
                'char.category',
                'code2category',
                'word2id',
                'word.dat',
                'word.ary.idx',
                'word.inf',
            ] as $fileName) {
                $filePath = $directory . '/' . $fileName;

                if (is_file($filePath)) {
                    unlink($filePath);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * search がカテゴリの最大長まで互換文字を伸ばし、各長さの未知語候補を通知することを確認する。
     */
    public function testSearchEmitsCandidatesUpToCategoryLengthWhileCharactersAreCompatible(): void
    {
        $directory = $this->createDictionaryDirectory(
            [
                ['id' => 0, 'length' => 1, 'invoke' => true, 'group' => false],
                ['id' => 1, 'length' => 3, 'invoke' => true, 'group' => false],
            ],
            [32 => 0, 65 => 1, 66 => 1, 67 => 1],
            [65 => 0b0001, 66 => 0b0001, 67 => 0b0010],
        );
        $loader = FileBinaryDictionaryLoader::forFileStorage($directory);
        $wordDic = $loader->loadWordDictionary();
        $unknown = $loader->loadUnknownWordDictionary($wordDic);
        $callback = new CapturingUnknownCallback();

        $unknown->search([65, 66, 67], 0, $callback);

        $this->assertNodeSummaries(
            [
                [1, 0, 1, false],
                [1, 0, 2, false],
            ],
            $callback->nodeSummaries(),
        );
    }

    /**
     * group が有効なカテゴリでは最大長を越えて互換文字列全体を未知語候補にすることを確認する。
     */
    public function testSearchEmitsGroupedCandidateBeyondCategoryLength(): void
    {
        $directory = $this->createDictionaryDirectory(
            [
                ['id' => 0, 'length' => 1, 'invoke' => true, 'group' => false],
                ['id' => 2, 'length' => 2, 'invoke' => true, 'group' => true],
            ],
            [32 => 0, 70 => 1, 71 => 1, 72 => 1, 73 => 1],
            [70 => 0b0100, 71 => 0b0100, 72 => 0b0100, 73 => 0b1000],
        );
        $loader = FileBinaryDictionaryLoader::forFileStorage($directory);
        $wordDic = $loader->loadWordDictionary();
        $unknown = $loader->loadUnknownWordDictionary($wordDic);
        $callback = new CapturingUnknownCallback();

        $unknown->search([70, 71, 72, 73], 0, $callback);

        $this->assertNodeSummaries(
            [
                [2, 0, 1, false],
                [2, 0, 2, false],
                [2, 0, 3, false],
            ],
            $callback->nodeSummaries(),
        );
    }

    /**
     * 通常単語候補が既にあり invoke が無効なカテゴリでは未知語候補を追加しないことを確認する。
     */
    public function testSearchSkipsNonInvokeCategoryWhenCallbackAlreadyHasCandidates(): void
    {
        $directory = $this->createDictionaryDirectory(
            [
                ['id' => 0, 'length' => 1, 'invoke' => true, 'group' => false],
                ['id' => 3, 'length' => 2, 'invoke' => false, 'group' => false],
            ],
            [32 => 0, 80 => 1],
            [80 => 0b0001],
        );
        $loader = FileBinaryDictionaryLoader::forFileStorage($directory);
        $wordDic = $loader->loadWordDictionary();
        $unknown = $loader->loadUnknownWordDictionary($wordDic);
        $callback = new CapturingUnknownCallback(false);

        $unknown->search([80], 0, $callback);

        $this->assertSame([], $callback->nodeSummaries());
    }

    /**
     * SPACE カテゴリ ID の候補は空白ノードとして通知されることを確認する。
     */
    public function testSearchMarksSpaceCategoryCandidatesAsSpace(): void
    {
        $directory = $this->createDictionaryDirectory(
            [
                ['id' => 4, 'length' => 2, 'invoke' => true, 'group' => false],
            ],
            [32 => 0],
            [32 => 0b0001],
        );
        $loader = FileBinaryDictionaryLoader::forFileStorage($directory);
        $wordDic = $loader->loadWordDictionary();
        $unknown = $loader->loadUnknownWordDictionary($wordDic);
        $callback = new CapturingUnknownCallback();

        $unknown->search([32, 32], 0, $callback);

        $this->assertNodeSummaries(
            [
                [4, 0, 1, true],
                [4, 0, 2, true],
            ],
            $callback->nodeSummaries(),
        );
    }

    /**
     * テスト用の辞書ディレクトリを作り、Unknown と WordDic が読むファイルを旧形式で配置する。
     *
     * @param list<array{id:int, length:int, invoke:bool, group:bool}> $categories
     * @param array<int, int> $charToCategoryIds
     * @param array<int, int> $eqlMasks
     */
    private function createDictionaryDirectory(array $categories, array $charToCategoryIds, array $eqlMasks): string
    {
        $baseName = tempnam(sys_get_temp_dir(), 'igo-unknown-');
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        $this->writeCategoryFiles($baseName, $categories, $charToCategoryIds, $eqlMasks);
        $this->writeWordDicFiles($baseName, $categories);

        return $baseName;
    }

    /**
     * 文字コードからカテゴリと互換性マスクを引くためのカテゴリ辞書ファイルを書き込む。
     *
     * @param list<array{id:int, length:int, invoke:bool, group:bool}> $categories
     * @param array<int, int> $charToCategoryIds
     * @param array<int, int> $eqlMasks
     */
    private function writeCategoryFiles(
        string $directory,
        array $categories,
        array $charToCategoryIds,
        array $eqlMasks,
    ): void {
        $maxCode = max(array_merge([32], array_keys($charToCategoryIds), array_keys($eqlMasks)));
        $charToCategory = array_fill(0, $maxCode + 1, 0);
        $masks = array_fill(0, $maxCode + 1, 0);

        foreach ($charToCategoryIds as $code => $categoryIndex) {
            $charToCategory[$code] = $categoryIndex;
        }

        foreach ($eqlMasks as $code => $mask) {
            $masks[$code] = $mask;
        }

        $categoryValues = [];
        foreach ($categories as $category) {
            $categoryValues[] = $category['id'];
            $categoryValues[] = $category['length'];
            $categoryValues[] = $category['invoke'] ? 1 : 0;
            $categoryValues[] = $category['group'] ? 1 : 0;
        }

        $this->writeBinaryFile($directory . '/char.category', $this->packValues('l', $categoryValues));
        $this->writeBinaryFile(
            $directory . '/code2category',
            $this->packValues('l', array_values($charToCategory)) . $this->packValues('l', array_values($masks)),
        );
    }

    /**
     * 各カテゴリ ID を trie ID として参照できる最小限の単語辞書ファイルを書き込む。
     *
     * @param list<array{id:int, length:int, invoke:bool, group:bool}> $categories
     */
    private function writeWordDicFiles(string $directory, array $categories): void
    {
        $maxTrieId = 0;
        foreach ($categories as $category) {
            $maxTrieId = max($maxTrieId, $category['id']);
        }

        $indices = range(0, $maxTrieId + 1);
        $wordCount = $maxTrieId + 1;

        $this->writeBinaryFile($directory . '/word2id', $this->createEmptyTrieDictionary());
        $this->writeBinaryFile($directory . '/word.dat', str_repeat("\0", ($wordCount + 1) * 2));
        $this->writeBinaryFile($directory . '/word.ary.idx', $this->packValues('l', $indices));
        $this->writeBinaryFile(
            $directory . '/word.inf',
            $this->packValues('l', range(0, $wordCount))
                . $this->packValues('s', range(0, $wordCount - 1))
                . $this->packValues('s', range(0, $wordCount - 1))
                . $this->packValues('s', range(0, $wordCount - 1)),
        );
    }

    /**
     * Unknown のテストでは trie 検索を使わないため、空の double-array trie 辞書を作成する。
     */
    private function createEmptyTrieDictionary(): string
    {
        return (
            $this->packValues('l', [1, 0, 0])
            . $this->packValues('l', [0])
            . $this->packValues('s', [])
            . $this->packValues('S', [0])
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
     * ViterbiNode の未知語判定に関係する属性だけを比較する。
     *
     * @param list<array{int, int, int, bool}> $expected
     * @param list<array{int, int, int, bool}> $actual
     */
    private function assertNodeSummaries(array $expected, array $actual): void
    {
        $this->assertSame($expected, $actual);
    }
}

/**
 * Unknown から通知された候補ノードと探索前の空状態をテスト用に保持する。
 */
class CapturingUnknownCallback implements WordDicCallback
{
    /** @var list<ViterbiNode> 通知された候補ノードを順序付きで保持する。 */
    private array $nodes = [];

    /**
     * 探索開始時点で通常単語候補が空かどうかを返せるよう初期状態を保持する。
     */
    public function __construct(
        private bool $empty = true,
    ) {}

    /**
     * Unknown が見つけた候補ノードを記録し、以後は空でない状態として扱う。
     */
    public function call(ViterbiNode $node): void
    {
        $this->empty = false;
        $this->nodes[] = $node;
    }

    /**
     * その開始位置で既に候補が見つかっているかを Unknown の invoke 判定へ返す。
     */
    public function isEmpty(): bool
    {
        return $this->empty;
    }

    /**
     * 候補ノードの未知語探索に関係する属性をテスト比較用の配列へ変換する。
     *
     * @return list<array{int, int, int, bool}>
     */
    public function nodeSummaries(): array
    {
        return array_map(static fn(ViterbiNode $node): array => [
            $node->wordId,
            $node->start,
            $node->length,
            $node->isSpace,
        ], $this->nodes);
    }
}
