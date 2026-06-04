<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use RuntimeException;

/**
 * matrix.def から runtime Matrix が読める matrix.bin を生成する。
 */
class MatrixBuilder implements DictionaryBuildStep
{
    /**
     * matrix.def を読み込み、Matrix が読む native endian の matrix.bin を生成する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        $matrix = $this->readMatrixDefinition($inputDirectory . '/matrix.def');
        $this->ensureOutputDirectory($outputDirectory);
        $this->writeBinaryFile($outputDirectory . '/matrix.bin', $this->matrixBinary($matrix));
    }

    /**
     * matrix.def の数値定義を読み取り、ヘッダと順序検証済みコスト配列へ変換する。
     *
     * @return array{leftSize:int, rightSize:int, costs:list<int>}
     */
    private function readMatrixDefinition(string $fileName): array
    {
        $lines = file($fileName, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('failed to read matrix.def "%s".', $fileName));
        }

        $definitionLines = $this->definitionLines($lines);
        $header = array_shift($definitionLines);

        if ($header === null) {
            throw new RuntimeException('matrix.def header is missing.');
        }

        [$leftSize, $rightSize] = $this->parseHeader($header['line'], $header['number']);
        $costs = $this->parseCosts($definitionLines, $leftSize, $rightSize);

        return ['leftSize' => $leftSize, 'rightSize' => $rightSize, 'costs' => $costs];
    }

    /**
     * 空行を除外し、エラー表示に使う元の行番号を保った定義行へ整える。
     *
     * @param list<string> $lines
     * @return list<array{number:int, line:string}>
     */
    private function definitionLines(array $lines): array
    {
        $definitionLines = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $definitionLines[] = ['number' => $index + 1, 'line' => $trimmed];
        }

        return $definitionLines;
    }

    /**
     * 先頭行から left/right の文脈サイズを取得し、行列サイズとして妥当か検証する。
     *
     * @return array{0:int, 1:int}
     */
    private function parseHeader(string $line, int $lineNumber): array
    {
        $fields = $this->splitFields($line);

        if (count($fields) !== 2 || !$this->isInteger($fields[0]) || !$this->isInteger($fields[1])) {
            throw new RuntimeException(sprintf('matrix.def line %d must contain left and right sizes.', $lineNumber));
        }

        $leftSize = (int) $fields[0];
        $rightSize = (int) $fields[1];

        if ($leftSize < 1 || $rightSize < 1) {
            throw new RuntimeException(sprintf('matrix.def line %d must contain positive matrix sizes.', $lineNumber));
        }

        return [$leftSize, $rightSize];
    }

    /**
     * 各コスト行を MeCab の left-major 順で検証し、runtime 用 right-major 配列へ詰め替える。
     *
     * @param list<array{number:int, line:string}> $lines
     * @return list<int>
     */
    private function parseCosts(array $lines, int $leftSize, int $rightSize): array
    {
        $expectedEntryCount = $leftSize * $rightSize;

        if (count($lines) !== $expectedEntryCount) {
            throw new RuntimeException('matrix.def entry count does not match header sizes.');
        }

        $costs = array_fill(0, $expectedEntryCount, 0);

        foreach ($lines as $index => $line) {
            [$leftId, $rightId, $cost] = $this->parseCostLine($line['line'], $line['number']);
            $expectedLeftId = intdiv($index, $rightSize);
            $expectedRightId = $index % $rightSize;

            if ($leftId !== $expectedLeftId || $rightId !== $expectedRightId) {
                throw new RuntimeException(sprintf('matrix.def line %d has unexpected context ids.', $line['number']));
            }

            $costs[($rightId * $leftSize) + $leftId] = $cost;
        }

        return array_values($costs);
    }

    /**
     * コスト定義行から left ID、right ID、連接コストを取り出し、signed short 範囲を守らせる。
     *
     * @return array{0:int, 1:int, 2:int}
     */
    private function parseCostLine(string $line, int $lineNumber): array
    {
        $fields = $this->splitFields($line);

        if (
            count($fields) !== 3
            || !$this->isInteger($fields[0])
            || !$this->isInteger($fields[1])
            || !$this->isInteger($fields[2])
        ) {
            throw new RuntimeException(sprintf(
                'matrix.def line %d must contain left id, right id, and cost.',
                $lineNumber,
            ));
        }

        $cost = (int) $fields[2];

        if ($cost < -32_768 || $cost > 32_767) {
            throw new RuntimeException(sprintf('matrix.def line %d cost is outside signed short range.', $lineNumber));
        }

        return [(int) $fields[0], (int) $fields[1], $cost];
    }

    /**
     * matrix.def の空白区切りフィールドを、連続空白に依存しない形で分割する。
     *
     * @return list<string>
     */
    private function splitFields(string $line): array
    {
        $fields = preg_split('/\s+/', $line);

        if ($fields === false) {
            throw new RuntimeException('matrix.def parsing failed.');
        }

        return $fields;
    }

    /**
     * matrix.def の数値フィールドとして、符号付き 10 進整数だけを受け入れる。
     */
    private function isInteger(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }

    /**
     * 出力先が存在しない場合だけ作成し、ファイル書き込み前の前提を満たす。
     */
    private function ensureOutputDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('failed to create output directory "%s".', $directory));
        }
    }

    /**
     * Matrix reader と同じ pack 契約で、ヘッダ int と right-major の signed short 列を連結する。
     *
     * @param array{leftSize:int, rightSize:int, costs:list<int>} $matrix
     */
    private function matrixBinary(array $matrix): string
    {
        return pack('l', $matrix['leftSize']) . pack('l', $matrix['rightSize']) . $this->packShorts($matrix['costs']);
    }

    /**
     * signed short のコスト列を native endian の連続バイナリへ変換する。
     *
     * @param list<int> $values
     */
    private function packShorts(array $values): string
    {
        $binary = '';

        foreach ($values as $value) {
            $binary .= pack('s', $value);
        }

        return $binary;
    }

    /**
     * matrix.bin を一括で書き込み、短い書き込みを辞書生成失敗として扱う。
     */
    private function writeBinaryFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);

        if ($writtenBytes !== strlen($contents)) {
            throw new RuntimeException(sprintf('failed to write matrix.bin "%s".', $fileName));
        }
    }
}
