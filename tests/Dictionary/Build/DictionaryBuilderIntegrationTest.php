<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\DictionaryBuilder;
use IgoModern\Igo;
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

        DictionaryBuilder::standard()->build($outputDirectory, $inputDirectory, 'UTF-8');

        $result = (new Igo($outputDirectory, 'UTF-8'))->parse('猫AB');

        $this->assertCount(2, $result);
        $this->assertSame('猫', $result[0]->surface);
        $this->assertSame('NOUN', $result[0]->feature);
        $this->assertSame(0, $result[0]->start);
        $this->assertSame('AB', $result[1]->surface);
        $this->assertSame('UNKNOWN_ALPHA', $result[1]->feature);
        $this->assertSame(1, $result[1]->start);
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
     * テスト入力ファイルを書き込み、期待バイト数が保存されたことを確認する。
     */
    private function writeTextFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);
    }
}
