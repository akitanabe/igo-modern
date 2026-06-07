<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use RuntimeException;
use Throwable;

/**
 * matrix.def から runtime Matrix が読める matrix.bin を生成する。
 *
 * UniDic 級の巨大 matrix.def(数百 MB・数千万エントリ)でも破綻しないよう、入力は
 * fgets でストリーミング読みし、出力は matrix.bin と同じサイズ(数十 MB)のバイナリ
 * バッファだけをメモリに置く。中間に巨大 PHP 配列を作らない。
 */
class MatrixBuilder implements DictionaryBuildStep
{
    /**
     * matrix.def を読み込み、Matrix が読む native endian の matrix.bin を生成する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        // 巨大 PHP 配列を作らず、出力サイズ分のバイナリへ転置済みコストを直接組み立てる。
        $matrixBinary = $this->buildMatrixBinary($inputDirectory . '/matrix.def');
        $this->ensureOutputDirectory($outputDirectory);
        $this->writeBinaryFile($outputDirectory . '/matrix.bin', $matrixBinary['header'], $matrixBinary['costs']);
    }

    /**
     * matrix.def をストリーミング読みし、ヘッダ列と right-major のコストバッファへ変換する。
     *
     * @return array{header:string, costs:string}
     */
    private function buildMatrixBinary(string $fileName): array
    {
        $handle = fopen($fileName, 'r');

        if ($handle === false) {
            throw new RuntimeException(sprintf('failed to read matrix.def "%s".', $fileName));
        }

        try {
            return $this->readDefinition($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * ヘッダで確保したバッファへ、各コスト行を MeCab の left-major 順検証付きで right-major へ詰め替える。
     *
     * @param resource $handle
     * @return array{header:string, costs:string}
     */
    private function readDefinition($handle): array
    {
        $lineNumber = 0;
        [$leftSize, $rightSize] = $this->readHeader($handle, $lineNumber);

        $expectedEntryCount = $leftSize * $rightSize;
        // matrix.bin のコスト領域と同サイズの 0 埋めバッファを一度だけ確保する。
        $costs = str_repeat("\0", $expectedEntryCount * 2);
        $index = 0;

        while (($raw = fgets($handle)) !== false) {
            $lineNumber++;
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            // 余剰行は parse/順序検証より前に弾き、既存の entry count 不一致挙動を保つ。
            if ($index >= $expectedEntryCount) {
                throw new RuntimeException('matrix.def entry count does not match header sizes.');
            }

            [$leftId, $rightId, $cost] = $this->parseCostLine($line, $lineNumber);
            $expectedLeftId = intdiv($index, $rightSize);
            $expectedRightId = $index % $rightSize;

            if ($leftId !== $expectedLeftId || $rightId !== $expectedRightId) {
                throw new RuntimeException(sprintf('matrix.def line %d has unexpected context ids.', $lineNumber));
            }

            // right-major offset へ signed short を直接バイト代入する(syscall を伴わない O(1))。
            $offset = (($rightId * $leftSize) + $leftId) * 2;
            $short = pack('s', $cost);
            $costs[$offset] = $short[0];
            $costs[$offset + 1] = $short[1];

            $index++;
        }

        if ($index !== $expectedEntryCount) {
            throw new RuntimeException('matrix.def entry count does not match header sizes.');
        }

        return ['header' => pack('l', $leftSize) . pack('l', $rightSize), 'costs' => $costs];
    }

    /**
     * 空行を読み飛ばして最初の定義行をヘッダとして解釈し、行番号を呼び出し側へ進める。
     *
     * @param resource $handle
     * @return array{0:int, 1:int}
     */
    private function readHeader($handle, int &$lineNumber): array
    {
        while (($raw = fgets($handle)) !== false) {
            $lineNumber++;
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            return $this->parseHeader($line, $lineNumber);
        }

        throw new RuntimeException('matrix.def header is missing.');
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
     * matrix.bin を一時ファイルへ書き切ってから rename し、失敗時に壊れた出力を残さない。
     */
    private function writeBinaryFile(string $fileName, string $header, string $costs): void
    {
        $temporaryName = $fileName . '.tmp';
        $handle = fopen($temporaryName, 'w');

        if ($handle === false) {
            throw new RuntimeException(sprintf('failed to write matrix.bin "%s".', $temporaryName));
        }

        try {
            // ヘッダと本文を連結せず個別に書き、巨大 costs 文字列の複製を避ける。
            $this->writeAll($handle, $header, $temporaryName);
            $this->writeAll($handle, $costs, $temporaryName);
        } catch (Throwable $error) {
            fclose($handle);
            $this->removeIfExists($temporaryName);

            throw $error;
        }

        fclose($handle);

        if (!rename($temporaryName, $fileName)) {
            $this->removeIfExists($temporaryName);

            throw new RuntimeException(sprintf('failed to write matrix.bin "%s".', $fileName));
        }
    }

    /**
     * 短い書き込みに備え、要求バイトを全て書き切るまで fwrite を繰り返す。
     *
     * @param resource $handle
     */
    private function writeAll($handle, string $data, string $fileName): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            // 初回は元文字列をそのまま渡し、巨大データの不要な複製を避ける。
            $chunk = $written === 0 ? $data : substr($data, $written);
            $result = fwrite($handle, $chunk);

            if ($result === false || $result === 0) {
                throw new RuntimeException(sprintf('failed to write matrix.bin "%s".', $fileName));
            }

            $written += $result;
        }
    }

    /**
     * 書き込み失敗時の後始末として、作りかけの一時ファイルを残さないよう削除する。
     */
    private function removeIfExists(string $fileName): void
    {
        if (is_file($fileName)) {
            unlink($fileName);
        }
    }
}
