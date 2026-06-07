<?php

declare(strict_types=1);

namespace IgoModern\Tests;

use IgoModern\Analysis\Tagger;
use IgoModern\Igo;
use IgoModern\Morpheme;
use IgoModern\Storage\FileStorage;
use IgoModern\Storage\MemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $igo = Igo::fromStorage(FileStorage::fromDataDir($this->createDictionaryDirectory(2)), null);

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
        $igo = Igo::fromStorage(FileStorage::fromDataDir($this->createDictionaryDirectory(1)), null);

        $this->assertSame(['A', 'B'], $igo->wakati('A B'));
    }

    /**
     * FileStorage と MemoryStorage は配列の実体化方式だけが異なり、解析結果は同一であることを確認する。
     */
    public function testFileStorageAndMemoryStorageProduceSameResult(): void
    {
        $directory = $this->createDictionaryDirectory(2);

        $fileResult = Igo::fromStorage(FileStorage::fromDataDir($directory))->parse('AB');
        $memoryResult = Igo::fromStorage(MemoryStorage::fromDataDir($directory))->parse('AB');

        $this->assertEquals($fileResult, $memoryResult);
        $this->assertSame('AB', $memoryResult[0]->surface);
        $this->assertSame('ALPHA', $memoryResult[0]->feature);
    }

    /**
     * 構築入口が Storage に一本化されたため、読み込み失敗は FileStorage::fromDataDir が例外で表すことを確認する。
     */
    public function testFileStorageThrowsWhenDictionaryCannotBeLoaded(): void
    {
        $this->expectException(RuntimeException::class);

        // 欠損辞書では fopen が警告を出すため、警告を捨てて辞書読み込み失敗の例外だけを検証する。
        set_error_handler(static fn(): bool => true);

        try {
            FileStorage::fromDataDir(__DIR__ . '/missing-dictionary');
        } finally {
            restore_error_handler();
        }
    }

    /**
     * tryParse が解析成功時に parse と同じ形態素列を返すことを確認する。
     */
    public function testTryParseReturnsMorphemesWhenParsingSucceeds(): void
    {
        $igo = Igo::fromStorage(FileStorage::fromDataDir($this->createDictionaryDirectory(2)), null);

        $result = $igo->tryParse('AB');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertContainsOnlyInstancesOf(Morpheme::class, $result);
        $this->assertSame('AB', $result[0]->surface);
    }

    /**
     * tryParse が解析失敗時に null を返し、呼び出し側で例外処理を省けることを確認する。
     */
    public function testTryParseReturnsNullWhenParsingFails(): void
    {
        $tagger = $this->createMock(Tagger::class);
        $tagger->method('parse')->willThrowException(new RuntimeException('parse failed.'));
        $igo = new Igo($tagger);

        $this->assertNull($igo->tryParse('AB'));
    }

    /**
     * tryWakati が分かち書き成功時に wakati と同じ表層形リストを返すことを確認する。
     */
    public function testTryWakatiReturnsSurfacesWhenParsingSucceeds(): void
    {
        $igo = Igo::fromStorage(FileStorage::fromDataDir($this->createDictionaryDirectory(1)), null);

        $this->assertSame(['A', 'B'], $igo->tryWakati('A B'));
    }

    /**
     * tryWakati が分かち書き失敗時に null を返し、通常の wakati と失敗経路を分離することを確認する。
     */
    public function testTryWakatiReturnsNullWhenParsingFails(): void
    {
        $tagger = $this->createMock(Tagger::class);
        $tagger->method('wakati')->willThrowException(new RuntimeException('wakati failed.'));
        $igo = new Igo($tagger);

        $this->assertNull($igo->tryWakati('A B'));
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
