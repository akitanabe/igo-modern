<?php

declare(strict_types=1);

namespace IgoModern\Tests\Analysis;

use IgoModern\Analysis\Tagger;
use IgoModern\Analysis\ViterbiNode;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\Contract\WordDictionary;
use IgoModern\Dictionary\WordDicCallback;
use IgoModern\Morpheme;
use IgoModern\Storage\FileStorage;
use IgoModern\Storage\Loader\FileBinaryDictionaryLoader;
use IgoModern\Storage\MemoryStorage;
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
        $this->assertSame(mb_convert_encoding('A', to_encoding: 'SJIS', from_encoding: 'UTF-8'), $result[1]->surface);
        $this->assertSame(
            mb_convert_encoding('ALPHA', to_encoding: 'SJIS', from_encoding: 'UTF-8'),
            $result[1]->feature,
        );
        $this->assertSame(0, $result[1]->start);
    }

    /**
     * inputEncoding='UTF-8' を固定指定した場合、検出ありと完全一致した解析結果を返すことを確認する。
     */
    public function testParseWithFixedUtf8EncodingMatchesDetectionResult(): void
    {
        $directory = $this->createDictionaryDirectory(2);
        $defaultTagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), null);
        $fixedTagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), null, 'UTF-8');

        $defaultResult = $defaultTagger->parse('AB');
        $fixedResult = $fixedTagger->parse('AB');

        $this->assertSame(
            array_map(static fn(Morpheme $m): array => [$m->surface, $m->feature, $m->start], $defaultResult),
            array_map(static fn(Morpheme $m): array => [$m->surface, $m->feature, $m->start], $fixedResult),
        );
    }

    /**
     * inputEncoding 固定時に wakati の結果も検出ありと完全一致することを確認する。
     */
    public function testWakatiWithFixedUtf8EncodingMatchesDetectionResult(): void
    {
        $directory = $this->createDictionaryDirectory(1);
        $defaultTagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), null);
        $fixedTagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), null, 'UTF-8');

        $this->assertSame($defaultTagger->wakati('A B'), $fixedTagger->wakati('A B'));
    }

    /**
     * EUC-JP 入力を固定エンコーディング指定で解析し、UTF-8 出力を明示した場合に正しく変換されることを確認する。
     */
    public function testParseWithFixedEucJpEncodingConvertsToUtf8Output(): void
    {
        $directory = $this->createDictionaryDirectory(2);
        $tagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), 'UTF-8', 'EUC-JP');

        $eucJpText = mb_convert_encoding('AB', to_encoding: 'EUC-JP', from_encoding: 'UTF-8');
        $result = $tagger->parse($eucJpText);

        $this->assertCount(1, $result);
        $this->assertSame('AB', $result[0]->surface);
        $this->assertSame('ALPHA', $result[0]->feature);
    }

    /**
     * SJIS 入力を固定エンコーディング指定で解析し、UTF-8 出力を明示した場合に正しく変換されることを確認する。
     */
    public function testParseWithFixedSjisEncodingConvertsToUtf8Output(): void
    {
        $directory = $this->createDictionaryDirectory(2);
        $tagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), 'UTF-8', 'SJIS');

        $sjisText = mb_convert_encoding('AB', to_encoding: 'SJIS', from_encoding: 'UTF-8');
        $result = $tagger->parse($sjisText);

        $this->assertCount(1, $result);
        $this->assertSame('AB', $result[0]->surface);
        $this->assertSame('ALPHA', $result[0]->feature);
    }

    /**
     * inputEncoding 未指定（null）では従来どおり mb_detect_encoding が走ることを確認する（後方互換）。
     */
    public function testParseDefaultBehaviorDetectsEncoding(): void
    {
        $directory = $this->createDictionaryDirectory(2);
        $tagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), null);

        $result = $tagger->parse('AB');

        $this->assertCount(1, $result);
        $this->assertSame('AB', $result[0]->surface);
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
     * MemoryStorage（fast 経路）と FileStorage（fallback 経路）で parse / wakati が完全に同一結果を返すことを確認する。
     *
     * 表層形・素性・開始位置・順序がずれてはならない不変条件を、両経路の等価性として固定する。
     */
    public function testFastAndFallbackParseAreEquivalent(): void
    {
        $directory = $this->createDictionaryDirectory(1);

        $memoryTagger = Tagger::fromStorage(MemoryStorage::fromDataDir($directory), null);
        $fileTagger = Tagger::fromStorage(FileStorage::fromDataDir($directory), null);

        $memoryResult = $memoryTagger->parse('A B');
        $fileResult = $fileTagger->parse('A B');

        $this->assertSame(
            array_map(static fn(Morpheme $m): array => [$m->surface, $m->feature, $m->start], $fileResult),
            array_map(static fn(Morpheme $m): array => [$m->surface, $m->feature, $m->start], $memoryResult),
        );
        $this->assertSame($fileTagger->wakati('A B'), $memoryTagger->wakati('A B'));
    }

    /**
     * 非対称な連接コスト行列で setMincostNode の fast（メモリ）と fallback（ファイル）が同一経路を選ぶことを確認する。
     *
     * 行と列を取り違えても全候補が同コストにならないよう非対称コストを与え、添字の行・列対応の誤りを検出する。
     */
    public function testSetMincostNodeFastAndFallbackChooseSamePrevForAsymmetricMatrix(): void
    {
        // leftSize=2, rightSize=2 の行列。node->leftId=1 で prev->rightId=0/1 を比較すると get(2)=0 < get(3)=50 で
        // 行（leftId）と列（rightId）を取り違えた誤添字（get(1)=100 vs get(3)=50）とは異なる prev を選ぶ非対称行列。
        $directory = $this->createMatrixOnlyDirectory(2, 2, [0, 100, 0, 50]);

        $memoryMatrix = FileBinaryDictionaryLoader::forMemoryStorage($directory)->loadConnectionMatrix();
        $fileMatrix = FileBinaryDictionaryLoader::forFileStorage($directory)->loadConnectionMatrix();

        $memoryTagger = new Tagger(new NullWordDictionary(), new NullUnknownWordDictionary(), $memoryMatrix);
        $fileTagger = new Tagger(new NullWordDictionary(), new NullUnknownWordDictionary(), $fileMatrix);

        // rightId が異なる 2 つの直前候補を用意し、leftId=1 のノードへ接続させる。
        $makePrevs = static fn(): array => [
            new ViterbiNode(0, 0, 1, 0, 0, 0, false),
            new ViterbiNode(1, 1, 1, 0, 0, 1, false),
        ];
        $memoryPrevs = $makePrevs();
        $filePrevs = $makePrevs();

        $memoryResult = $memoryTagger->setMincostNode(new ViterbiNode(2, 2, 1, 0, 1, 0, false), $memoryPrevs);
        $fileResult = $fileTagger->setMincostNode(new ViterbiNode(2, 2, 1, 0, 1, 0, false), $filePrevs);

        // 選ばれた直前ノードの rightId と累積コストが両経路で完全一致することを固定する。
        $this->assertNotNull($fileResult->prev);
        $this->assertNotNull($memoryResult->prev);
        $this->assertSame($fileResult->prev->rightId, $memoryResult->prev->rightId);
        $this->assertSame($fileResult->cost, $memoryResult->cost);
    }

    /**
     * matrix.bin だけを持つ辞書ディレクトリを作り、連接行列単体の読み戻し検証に使う。
     *
     * @param list<int> $costs
     */
    private function createMatrixOnlyDirectory(int $leftSize, int $rightSize, array $costs): string
    {
        $baseName = tempnam(sys_get_temp_dir(), prefix: 'igo-tagger-');
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        $this->writeBinaryFile(
            $baseName . '/matrix.bin',
            $this->packValues('l', [$leftSize, $rightSize]) . $this->packValues('s', $costs),
        );

        return $baseName;
    }

    /**
     * テスト用の最小辞書ディレクトリを作り、Tagger の依存ファイルを旧形式で配置する。
     */
    private function createDictionaryDirectory(int $alphaLength): string
    {
        $baseName = tempnam(sys_get_temp_dir(), prefix: 'igo-tagger-');
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
        $charToCategory = array_fill(0, count: $maxCode + 1, value: 0);
        $eqlMasks = array_fill(0, count: $maxCode + 1, value: 0);
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
            mb_convert_encoding('SPACE', to_encoding: 'UTF-16LE', from_encoding: 'UTF-8'),
            mb_convert_encoding('ALPHA', to_encoding: 'UTF-16LE', from_encoding: 'UTF-8'),
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
        $base = array_fill(0, count: $nodeSize, value: 0);
        $chck = array_fill(0, count: $nodeSize, value: 0);
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

/**
 * setMincostNode 単体検証用に、既知語候補を一切通知しない単語辞書 fake。
 */
final class NullWordDictionary implements WordDictionary
{
    /**
     * 候補を通知しない。setMincostNode の直接検証では search 経路は使わない。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void
    {
        // 候補なし。
    }

    /**
     * 素性解決は検証対象外のため空バイト列を返す。
     */
    public function wordData(int $wordId): string
    {
        return '';
    }
}

/**
 * setMincostNode 単体検証用に、未知語候補を一切通知しない未知語辞書 fake。
 */
final class NullUnknownWordDictionary implements UnknownWordDictionary
{
    /**
     * 候補を通知しない。setMincostNode の直接検証では search 経路は使わない。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void
    {
        // 候補なし。
    }
}
