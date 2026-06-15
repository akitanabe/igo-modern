<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Dictionary\Build\DictionaryBuilder;
use IgoModern\Dictionary\Trie\CommonPrefixCallback;
use IgoModern\Dictionary\WordDicCallback;
use IgoModern\Igo;
use IgoModern\Storage\FileStorage;
use IgoModern\Storage\Loader\FileBinaryDictionaryLoader;
use IgoModern\Storage\Loader\FileTrieLoader;
use PHPUnit\Framework\TestCase;

/**
 * DictionaryBuilder が小型 MeCab 互換 fixture から Igo が読める辞書一式を生成することを検証するテスト。
 */
class DictionaryBuilderIntegrationTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テスト用に作成した入力・出力ディレクトリと辞書ファイルを削除し、状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            foreach ([
                'char.category',
                'char.def',
                'code2category',
                'matrix.bin',
                'matrix.def',
                'noun.csv',
                'unk.def',
                'word.ary.idx',
                'word.dat',
                'word.inf',
                'word2id',
            ] as $fileName) {
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
     * 標準 builder で生成した辞書を Igo が通常語と未知語カテゴリとして解析できることを確認する。
     */
    public function testBuildCreatesDictionaryUsableByIgo(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-build-input-');
        $outputDirectory = $this->createTemporaryDirectory('igo-build-output-');
        $this->writeFixture($inputDirectory);

        DictionaryBuilder::standard(FileTrieLoader::forBuild())->build($outputDirectory, $inputDirectory, 'UTF-8');

        $result = Igo::fromStorage(FileStorage::fromDataDir($outputDirectory), 'UTF-8')->parse('猫AB');

        $this->assertCount(2, $result);
        $this->assertSame('猫', $result[0]->surface);
        $this->assertSame('NOUN', $result[0]->feature);
        $this->assertSame(0, $result[0]->start);
        $this->assertSame('AB', $result[1]->surface);
        $this->assertSame('UNKNOWN_ALPHA', $result[1]->feature);
        $this->assertSame(1, $result[1]->start);
    }

    /**
     * 標準 builder の生成ファイル群を runtime の各 reader が直接読み込めることを確認する。
     */
    public function testBuildCreatesDictionaryFilesReadableByRuntimeReaders(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-build-input-');
        $outputDirectory = $this->createTemporaryDirectory('igo-build-output-');
        $this->writeFixture($inputDirectory);

        DictionaryBuilder::standard(FileTrieLoader::forBuild())->build($outputDirectory, $inputDirectory, 'UTF-8');

        $this->assertDictionaryFilesExist($outputDirectory);

        $loader = FileBinaryDictionaryLoader::forFileStorage($outputDirectory);

        $wordCallback = new CapturingIntegrationWordCallback();
        $loader->loadWordDictionary()->search($this->utf16CodeUnits('猫AB'), 0, $wordCallback);

        $prefixCallback = new CapturingIntegrationPrefixCallback();
        FileTrieLoader::forBuild()
            ->load($outputDirectory . '/word2id')
            ->eachCommonPrefix($this->utf16CodeUnits("\002ALPHA"), 0, $prefixCallback);

        $matrix = $loader->loadConnectionMatrix();
        $category = $loader->loadCharCategory();

        $this->assertSame([[3, 0, 1, -100, 0, 0, false]], $wordCallback->nodeSummaries());
        $this->assertSame([['start' => 0, 'offset' => 6]], $prefixCallback->ranges());
        $this->assertSame(0, $matrix->linkCost(0, 0));
        $this->assertSame(4, $category->category(0x0041)->length);
        $this->assertTrue($category->isCompatible(0x0041, 0x0042));
    }

    /**
     * 指定 prefix の一時ディレクトリを作成し、後片付け対象として記録する。
     */
    private function createTemporaryDirectory(string $prefix): string
    {
        $baseName = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        return $baseName;
    }

    /**
     * 辞書生成の公開成果物がすべて出力されていることを確認する。
     */
    private function assertDictionaryFilesExist(string $directory): void
    {
        foreach ([
            'word2id',
            'word.inf',
            'word.dat',
            'word.ary.idx',
            'matrix.bin',
            'char.category',
            'code2category',
        ] as $fileName) {
            $this->assertFileExists($directory . '/' . $fileName);
        }
    }

    /**
     * 統合テスト用の最小 MeCab 互換辞書定義ファイル群を書き込む。
     */
    private function writeFixture(string $directory): void
    {
        $this->writeTextFile(
            $directory . '/char.def',
            "DEFAULT 1 0 1\n"
            . "SPACE 0 1 2\n"
            . "ALPHA 1 1 4\n"
            . "0x0020 SPACE\n"
            . "0x0041..0x005A ALPHA\n"
            . "0x0061..0x007A ALPHA\n",
        );
        $this->writeTextFile(
            $directory . '/unk.def',
            "DEFAULT,0,0,1000,UNKNOWN_DEFAULT\nSPACE,0,0,0,SPACE\nALPHA,0,0,10,UNKNOWN_ALPHA\n",
        );
        $this->writeTextFile($directory . '/noun.csv', "猫,0,0,-100,NOUN\n");
        $this->writeTextFile($directory . '/matrix.def', "1 1\n0 0 0\n");
    }

    /**
     * UTF-8 文字列を runtime reader と同じ UTF-16LE code unit 配列へ変換する。
     *
     * @return list<int>
     */
    private function utf16CodeUnits(string $text): array
    {
        $values = unpack('S*', mb_convert_encoding($text, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8'));
        $this->assertIsArray($values);

        return array_values($values);
    }

    /**
     * テスト入力ファイルを書き込み、期待バイト数が保存されたことを確認する。
     */
    private function writeTextFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);
    }
}

/**
 * 統合テストで WordDic から通知された候補ノードを検証しやすい形で保持する。
 */
class CapturingIntegrationWordCallback implements WordDicCallback
{
    /** @var list<ViterbiNode> 生成辞書から見つかった候補ノードを順序付きで保持する。 */
    private array $nodes = [];

    /**
     * WordDic が見つけた候補ノードを記録する。
     */
    public function call(ViterbiNode $node): void
    {
        $this->nodes[] = $node;
    }

    /**
     * 統合テストでは候補通知の有無をそのまま空状態として返す。
     */
    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /**
     * 候補ノードの主要属性を比較しやすい配列へ変換する。
     *
     * @return list<array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int, 6:bool}>
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

/**
 * 統合テストで Searcher から通知された共通接頭辞範囲を保持する。
 */
class CapturingIntegrationPrefixCallback implements CommonPrefixCallback
{
    /** @var list<array{start:int, offset:int, id:int}> Searcher が見つけた一致を順序付きで保持する。 */
    private array $matches = [];

    /**
     * Searcher から通知された一致範囲と trie ID を記録する。
     */
    public function call(int $start, int $offset, int $id): void
    {
        $this->matches[] = ['start' => $start, 'offset' => $offset, 'id' => $id];
    }

    /**
     * trie ID に依存しない reader 検証用に一致範囲だけを返す。
     *
     * @return list<array{start:int, offset:int}>
     */
    public function ranges(): array
    {
        return array_map(static fn(array $match): array => [
            'start' => $match['start'],
            'offset' => $match['offset'],
        ], $this->matches);
    }
}
