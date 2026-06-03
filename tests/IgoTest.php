<?php

declare(strict_types=1);

use IgoModern\Igo;
use IgoModern\Morpheme;
use PHPUnit\Framework\TestCase;

/**
 * Igo が公開ファサードとして Tagger の parse/wakati 挙動を提供することを検証するテスト。
 */
class IgoTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テスト用辞書ファイルを削除し、ファイルシステム上の状態を戻す。
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
     * parse が Tagger の結果を Igo の公開 API として返すことを確認する。
     */
    public function testParseReturnsMorphemes(): void
    {
        $igo = new Igo($this->createDictionaryDirectory(2), null);

        $result = $igo->parse('AB');

        $this->assertCount(1, $result);
        $this->assertContainsOnlyInstancesOf(Morpheme::class, $result);
        $this->assertSame('AB', $result[0]->surface);
        $this->assertSame('ALPHA', $result[0]->feature);
        $this->assertSame(0, $result[0]->start);
    }

    /**
     * wakati が Tagger の分かち書き結果を Igo の公開 API として返すことを確認する。
     */
    public function testWakatiReturnsSurfaces(): void
    {
        $igo = new Igo($this->createDictionaryDirectory(1), null);

        $this->assertSame(['A', 'B'], $igo->wakati('A B'));
    }

    /**
     * テスト用の最小辞書ディレクトリを作り、Igo が Tagger を初期化できる状態にする。
     */
    private function createDictionaryDirectory(int $alphaLength): string
    {
        $baseName = tempnam(sys_get_temp_dir(), 'igo-facade-');
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
