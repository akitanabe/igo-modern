<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Dictionary\Build\Word2IdCategoryIdResolver;
use IgoModern\Dictionary\Build\WordDictionaryBuilder;
use IgoModern\Dictionary\WordDic;
use IgoModern\Dictionary\WordDicCallback;
use PHPUnit\Framework\TestCase;

/**
 * WordDictionaryBuilder が MeCab 互換定義から runtime WordDic 互換の単語辞書を生成することを検証するテスト。
 */
class WordDictionaryBuilderTest extends TestCase
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
                'char.def',
                'unk.def',
                'noun.csv',
                'word2id',
                'word.dat',
                'word.ary.idx',
                'word.inf',
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
     * unk.def と CSV から生成した単語辞書を WordDic が通常語・未知語カテゴリとして読めることを確認する。
     */
    public function testBuildWritesWordDictionaryFilesReadableByRuntimeWordDic(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-word-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-word-out-');
        $this->writeTextFile($inputDirectory . '/char.def', "DEFAULT 1 0 1\nSPACE 0 1 2\n0x0020 SPACE\n");
        $this->writeTextFile(
            $inputDirectory . '/unk.def',
            "DEFAULT,5,6,70,DEFAULT_FEATURE\nSPACE,7,8,90,SPACE_FEATURE\n",
        );
        $this->writeTextFile(
            $inputDirectory . '/noun.csv',
            "猫,1,2,300,名詞,一般\n猫,3,4,-50,名詞,固有\n猫語,9,10,400,名詞,複合\n",
        );

        (new WordDictionaryBuilder())->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $wordDic = new WordDic($outputDirectory);
        $normalCallback = new CapturingBuiltWordCallback();
        $wordDic->search($this->utf16CodeUnits('猫語です'), 0, $normalCallback);

        $spaceTrieId = (new Word2IdCategoryIdResolver())->resolve($outputDirectory, 'UTF-8', 'SPACE');
        $unknownCallback = new CapturingBuiltWordCallback();
        $wordDic->searchFromTrieId($spaceTrieId, 4, 2, true, $unknownCallback);

        $this->assertSame(
            [
                [2, 0, 1, 300, 1, 2, false],
                [3, 0, 1, -50, 3, 4, false],
                [4, 0, 2, 400, 9, 10, false],
            ],
            $normalCallback->nodeSummaries(),
        );
        $this->assertSame([[1, 4, 2, 90, 7, 8, true]], $unknownCallback->nodeSummaries());
        $this->assertSame($this->featureBytes('SPACE_FEATURE'), $wordDic->wordData(1));
        $this->assertSame($this->featureBytes('名詞,固有'), $wordDic->wordData(3));
    }

    /**
     * 出力ディレクトリが未作成でも build 呼び出し時に作成し、コンストラクタでは I/O しないことを確認する。
     */
    public function testBuildCreatesOutputDirectoryOnlyWhenBuildIsCalled(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-word-in-');
        $outputDirectory = $this->createMissingTemporaryDirectory('igo-word-out-');
        $this->writeTextFile($inputDirectory . '/char.def', "DEFAULT 1 0 1\nSPACE 0 1 2\n0x0020 SPACE\n");
        $this->writeTextFile($inputDirectory . '/unk.def', "DEFAULT,1,1,1,DEFAULT\nSPACE,1,1,1,SPACE\n");

        $builder = new WordDictionaryBuilder();
        $this->assertDirectoryDoesNotExist($outputDirectory);

        $builder->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $this->assertDirectoryExists($outputDirectory);
        $this->assertFileExists($outputDirectory . '/word2id');
        $this->assertFileExists($outputDirectory . '/word.dat');
        $this->assertFileExists($outputDirectory . '/word.ary.idx');
        $this->assertFileExists($outputDirectory . '/word.inf');
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
     * 存在しない一時ディレクトリパスを確保し、build が作成する対象として記録する。
     */
    private function createMissingTemporaryDirectory(string $prefix): string
    {
        $baseName = $this->createTemporaryDirectory($prefix);
        rmdir($baseName);

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
     * UTF-8 文字列を WordDic と同じ UTF-16LE code unit 配列へ変換する。
     *
     * @return list<int>
     */
    private function utf16CodeUnits(string $text): array
    {
        $values = unpack('S*', mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));
        $this->assertIsArray($values);

        return array_values($values);
    }

    /**
     * 素性文字列を word.dat と同じ UTF-16LE バイト列へ変換する。
     */
    private function featureBytes(string $feature): string
    {
        return mb_convert_encoding($feature, 'UTF-16LE', 'UTF-8');
    }
}

/**
 * 生成済み WordDic から通知された ViterbiNode を検証しやすい配列形式で蓄積する。
 */
class CapturingBuiltWordCallback implements WordDicCallback
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
     * WordDic 単体の検証では候補通知の有無をそのまま空状態として返す。
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
