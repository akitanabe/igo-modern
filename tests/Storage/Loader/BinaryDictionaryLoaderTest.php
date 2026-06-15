<?php

declare(strict_types=1);

namespace IgoModern\Tests\Storage\Loader;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Binary\IntDynamicArray;
use IgoModern\Binary\IntMemoryArray;
use IgoModern\Dictionary\Build\DictionaryBuilder;
use IgoModern\Dictionary\WordDicCallback;
use IgoModern\Storage\File\FileInputStreamFactory;
use IgoModern\Storage\Loader\FileBinaryDictionaryLoader;
use IgoModern\Storage\Loader\FileTrieLoader;
use IgoModern\Tests\Support\RecordingByteReaderFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * FileBinaryDictionaryLoader が辞書ディレクトリ構造を内部に閉じ、word / unknown / matrix 一式を構築する挙動を検証するテスト。
 */
class BinaryDictionaryLoaderTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テストで生成した入力・出力ディレクトリと辞書ファイルを削除して状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            foreach ([
                'char.def',
                'char.category',
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
     * forFileStorage が Lazy stream と byte reader factory を使い、word / unknown / matrix を構築できることを確認する。
     */
    public function testForFileStorageBuildsRuntimeDictionaryTrio(): void
    {
        $directory = $this->buildDictionaryDirectory();
        $loader = FileBinaryDictionaryLoader::forFileStorage($directory);

        $wordDictionary = $loader->loadWordDictionary();
        $unknownWordDictionary = $loader->loadUnknownWordDictionary($wordDictionary);
        $connectionMatrix = $loader->loadConnectionMatrix();

        // word.dat / word.ary.idx / word.inf が読めていれば、既知語の候補展開が成立する。
        $wordCallback = new CapturingLoaderCallback();
        $wordDictionary->search($this->utf16CodeUnits('猫'), 0, $wordCallback);
        $this->assertSame([[3, 0, 1, -100, 0, 0, false]], $wordCallback->nodeSummaries());

        // code2category / char.category が読めていれば、未知語カテゴリの候補展開が成立する。
        $unknownCallback = new CapturingLoaderCallback();
        $unknownWordDictionary->search($this->utf16CodeUnits('AB'), 0, $unknownCallback);
        $this->assertNotSame([], $unknownCallback->nodeSummaries());

        // matrix.bin が読めていれば、連接コストが期待値を返す。
        $this->assertSame(0, $connectionMatrix->linkCost(0, 0));
    }

    /**
     * forFileStorage は Lazy 実体化、forMemoryStorage は Resident 実体化で word 辞書の配列を作ることを確認する。
     */
    public function testNamedConstructorsSelectArrayMaterialization(): void
    {
        $directory = $this->buildDictionaryDirectory();
        $indicesProperty = new ReflectionProperty(\IgoModern\Dictionary\Binary\BinaryWordDictionary::class, 'indices');
        $indicesProperty->setAccessible(true);

        $lazyWord = FileBinaryDictionaryLoader::forFileStorage($directory)->loadWordDictionary();
        $residentWord = FileBinaryDictionaryLoader::forMemoryStorage($directory)->loadWordDictionary();

        $this->assertInstanceOf(IntDynamicArray::class, $indicesProperty->getValue($lazyWord));
        $this->assertInstanceOf(IntMemoryArray::class, $indicesProperty->getValue($residentWord));
    }

    /**
     * loader は constructor で受け取った辞書ディレクトリへ束縛され、load*() 呼び出し側は $dataDir を渡さないことを確認する。
     */
    public function testLoaderIsBoundToDirectoryGivenAtConstruction(): void
    {
        $directory = $this->buildDictionaryDirectory();
        $factory = new RecordingByteReaderFactory();
        $loader = new FileBinaryDictionaryLoader($directory, FileInputStreamFactory::lazy($factory), $factory);

        $loader->loadWordDictionary();

        // constructor 指定のディレクトリ配下のファイルだけが開かれることを、open 済みパスで確認する。
        foreach ($factory->openedFiles as $openedFile) {
            $this->assertStringStartsWith($directory . '/', $openedFile);
        }
        $openedBaseNames = array_values(array_unique(array_map('basename', $factory->openedFiles)));
        sort($openedBaseNames);
        $this->assertSame(['word.ary.idx', 'word.dat', 'word.inf', 'word2id'], $openedBaseNames);
    }

    /**
     * loadCharCategory が code2category / char.category から文字カテゴリ辞書を直接構築できることを確認する。
     */
    public function testLoadCharCategoryReadsCategoryFiles(): void
    {
        $directory = $this->buildDictionaryDirectory();
        $loader = FileBinaryDictionaryLoader::forFileStorage($directory);

        $category = $loader->loadCharCategory();

        // ALPHA カテゴリの長さと互換性判定が読めていることで、両カテゴリファイルの読み込みを検知する。
        $this->assertSame(4, $category->category(0x0041)->length);
        $this->assertTrue($category->isCompatible(0x0041, 0x0042));
    }

    /**
     * DictionaryBuilder で小型の MeCab 互換辞書を生成し、loader が読む辞書ディレクトリを返す。
     */
    private function buildDictionaryDirectory(): string
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-loader-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-loader-out-');

        $this->writeTextFile(
            $inputDirectory . '/char.def',
            "DEFAULT 1 0 1\nSPACE 0 1 2\nALPHA 1 1 4\n0x0020 SPACE\n0x0041..0x005A ALPHA\n0x0061..0x007A ALPHA\n",
        );
        $this->writeTextFile(
            $inputDirectory . '/unk.def',
            "DEFAULT,0,0,1000,UNKNOWN_DEFAULT\nSPACE,0,0,0,SPACE\nALPHA,0,0,10,UNKNOWN_ALPHA\n",
        );
        $this->writeTextFile($inputDirectory . '/noun.csv', "猫,0,0,-100,NOUN\n");
        $this->writeTextFile($inputDirectory . '/matrix.def', "1 1\n0 0 0\n");

        DictionaryBuilder::standard(FileTrieLoader::forBuild())->build($outputDirectory, $inputDirectory, 'UTF-8');

        return $outputDirectory;
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
     * テスト入力ファイルを書き込み、期待バイト数が保存されたことを確認する。
     */
    private function writeTextFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);
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
}

/**
 * loader テストで通知された候補ノードを検証しやすい配列形式で蓄積する。
 */
class CapturingLoaderCallback implements WordDicCallback
{
    /** @var list<ViterbiNode> 通知された候補ノードを順序付きで保持する。 */
    private array $nodes = [];

    /**
     * loader が構築した辞書から見つかった候補ノードを記録する。
     */
    public function call(ViterbiNode $node): void
    {
        $this->nodes[] = $node;
    }

    /**
     * 探索開始時点で候補が空かどうかを返す。
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
