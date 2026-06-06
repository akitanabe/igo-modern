<?php

declare(strict_types=1);

namespace IgoModern\Tests\Analysis;

use IgoModern\Analysis\Tagger;
use IgoModern\Dictionary\Storage\FileStorage;
use IgoModern\Morpheme;
use PHPUnit\Framework\TestCase;

/**
 * Tagger が辞書候補と未知語候補から最小コスト経路を復元する挙動を検証するテスト。
 */
class TaggerTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テストで作成した最小辞書ファイルとディレクトリを削除して状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach ([
                'char.category',
                'code2category',
                'matrix.bin',
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
     * parse が未知語候補の最小コスト経路を Morpheme の配列として返すことを確認する。
     */
    public function testParseReturnsMorphemesFromBestPath(): void
    {
        $tagger = Tagger::fromStorage(FileStorage::fromDataDir($this->createDictionaryDirectory(2)), null);

        $result = $tagger->parse('AB');

        $this->assertCount(1, $result);
        $this->assertContainsOnlyInstancesOf(Morpheme::class, $result);
        $this->assertSame('AB', $result[0]->surface);
        $this->assertSame('ALPHA', $result[0]->feature);
        $this->assertSame(0, $result[0]->start);
    }

    /**
     * parse が既存の結果配列へ追記し、指定された出力エンコーディングへ変換することを確認する。
     */
    public function testParseAppendsToGivenResultAndUsesOutputEncoding(): void
    {
        $tagger = Tagger::fromStorage(FileStorage::fromDataDir($this->createDictionaryDirectory(1)), 'SJIS');
        $existing = [new Morpheme('seed', 'seed-feature', 99)];

        $result = $tagger->parse('A', $existing);

        $this->assertCount(2, $result);
        $this->assertSame('seed', $result[0]->surface);
        $this->assertSame(mb_convert_encoding('A', 'SJIS', 'UTF-8'), $result[1]->surface);
        $this->assertSame(mb_convert_encoding('ALPHA', 'SJIS', 'UTF-8'), $result[1]->feature);
        $this->assertSame(0, $result[1]->start);
    }

    /**
     * wakati が空白カテゴリを形態素として出力せず、非空白候補の表層形だけを返すことを確認する。
     */
    public function testWakatiSkipsSpaceNodesAndReturnsSurfaces(): void
    {
        $tagger = Tagger::fromStorage(FileStorage::fromDataDir($this->createDictionaryDirectory(1)), null);

        $this->assertSame(['A', 'B'], $tagger->wakati('A B'));
    }

    /**
     * テスト用の最小辞書ディレクトリを作り、Tagger の依存ファイルを旧形式で配置する。
     */
    private function createDictionaryDirectory(int $alphaLength): string
    {
        $baseName = tempnam(sys_get_temp_dir(), 'igo-tagger-');
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        $this->writeCategoryFiles($baseName, $alphaLength);
        $this->writeWordDicFiles($baseName);
        $this->writeMatrixFile($baseName);

        return $baseName;
    }

    /**
     * 空白カテゴリと英字カテゴリだけを持つ未知語カテゴリ辞書を書き込む。
     */
    private function writeCategoryFiles(string $directory, int $alphaLength): void
    {
        $maxCode = 66;
        $charToCategory = array_fill(0, $maxCode + 1, 0);
        $eqlMasks = array_fill(0, $maxCode + 1, 0);
        $charToCategory[65] = 1;
        $charToCategory[66] = 1;
        $eqlMasks[32] = 0b0001;
        $eqlMasks[65] = 0b0010;
        $eqlMasks[66] = 0b0010;

        $categories = [
            0,
            1,
            1,
            0,
            1,
            $alphaLength,
            1,
            0,
        ];

        $this->writeBinaryFile($directory . '/char.category', $this->packValues('l', $categories));
        $this->writeBinaryFile(
            $directory . '/code2category',
            $this->packValues('l', $charToCategory) . $this->packValues('l', $eqlMasks),
        );
    }

    /**
     * 未知語カテゴリ ID を trie ID として参照するための単語辞書ファイルを書き込む。
     */
    private function writeWordDicFiles(string $directory): void
    {
        $features = [
            mb_convert_encoding('SPACE', 'UTF-16LE', 'UTF-8'),
            mb_convert_encoding('ALPHA', 'UTF-16LE', 'UTF-8'),
        ];
        $wordData = implode('', $features);

        $this->writeBinaryFile($directory . '/word2id', $this->createEmptyTrieDictionary());
        $this->writeBinaryFile($directory . '/word.dat', $wordData);
        $this->writeBinaryFile($directory . '/word.ary.idx', $this->packValues('l', [0, 1, 2]));
        $this->writeBinaryFile(
            $directory . '/word.inf',
            $this->packValues('l', [0, 5, 10]) . $this->packValues('s', [0, 0, 0]) . $this->packValues('s', [0, 0, 0])
                . $this->packValues('s', [0, 0, 0]),
        );
    }

    /**
     * すべての文脈接続コストをゼロにした最小の行列ファイルを書き込む。
     */
    private function writeMatrixFile(string $directory): void
    {
        $this->writeBinaryFile(
            $directory . '/matrix.bin',
            $this->packValues('l', [1, 1]) . $this->packValues('s', [0]),
        );
    }

    /**
     * 通常辞書検索を空振りさせるため、キーを持たない double-array trie 辞書を作成する。
     */
    private function createEmptyTrieDictionary(): string
    {
        $nodeSize = 128;
        $base = array_fill(0, $nodeSize, 0);
        $chck = array_fill(0, $nodeSize, 0);
        $base[0] = 1;
        $chck[1] = 999;

        return (
            $this->packValues('l', [$nodeSize, 0, 0])
            . $this->packValues('l', $base)
            . $this->packValues('s', [])
            . $this->packValues('S', $chck)
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
}
