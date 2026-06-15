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
        $handle = fopen($fileName, mode: 'r');

        if ($handle === false) {
            throw new RuntimeException(sprintf('failed to read matrix.def "%s".', $fileName));
        }

        try {
            return $this->readDefinition($handle);
        } finally {
            fclose($handle);
        }
    }

    /** チャンク読みの単位サイズ(4MiB)。fgets を行ごとに呼ぶ syscall/関数呼び出しコストを償却する。 */
    private const READ_CHUNK_SIZE = 4 * 1024 * 1024;

    /**
     * ヘッダで確保したバッファへ、各コスト行を MeCab の left-major 順検証付きで right-major へ詰め替える。
     *
     * 入力は fread によるチャンク読み + explode("\n") の行分割で処理し、fgets の行ごと呼び出しを避ける。
     * fast path は (expectedLeft, expectedRight) の連続性を利用した無アロケーションのプレフィックス比較で判定する。
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

        // ID 順序検証は intdiv/mod を毎行行わず、インクリメンタルなカウンタで等価に判定する。
        $expectedLeftId = 0;
        $expectedRightId = 0;

        // pack('s') 経路を避けられる LE 環境かを一度だけ判定し、ホットパスの分岐コストを定数化する。
        $littleEndian = pack('S', 1) === "\x01\x00";

        // fast path のプレフィックス比較に使う事前計算: "<right> " の文字列とその長さを right ごとに保持する。
        $rightTokens = [];
        $rightTokenLengths = [];

        for ($r = 0; $r < $rightSize; $r++) {
            $token = $r . ' ';
            $rightTokens[$r] = $token;
            $rightTokenLengths[$r] = strlen($token);
        }

        // left が繰り上がるたびに再計算する "<expectedLeft> " プレフィックスとその長さ・offset 基点。
        $leftPrefix = $expectedLeftId . ' ';
        $leftPrefixLen = strlen($leftPrefix);
        // offset は right が +1 されるごとに leftSize*2 だけ進む。left 繰り上がり時のみ基点を再設定する。
        $leftSizeTimes2 = $leftSize * 2;
        $offset = $expectedLeftId * 2;

        $carry = '';

        while (($chunk = fread($handle, self::READ_CHUNK_SIZE)) !== false && $chunk !== '') {
            // チャンク末尾の未完行を carry に持ち越し、次チャンク先頭と連結して 1 行に復元する。
            $lines = explode("\n", $carry . $chunk);
            $carry = array_pop($lines);

            foreach ($lines as $line) {
                $lineNumber++;

                // fast path: "<expectedLeft> " と "<expectedRight> " のプレフィックス一致を無アロケーションで判定する。
                if (
                    strncmp($line, $leftPrefix, $leftPrefixLen) === 0
                    && substr_compare(
                        $line,
                        $rightTokens[$expectedRightId],
                        $leftPrefixLen,
                        $rightTokenLengths[$expectedRightId],
                    ) === 0
                ) {
                    $costStr = substr($line, $leftPrefixLen + $rightTokenLengths[$expectedRightId]);
                    $costLen = strlen($costStr);
                    $costStart = $costLen > 0 && $costStr[0] === '-' ? 1 : 0;

                    // /^-?\d+$/ と同じ受理言語を strspn で無アロケーション判定する。"007" もここで受理してよい。
                    if (
                        ($costLen - $costStart) > 0
                        && strspn($costStr, characters: '0123456789', offset: $costStart) === ($costLen - $costStart)
                    ) {
                        // 余剰行は順序検証より前に弾き、既存の entry count 不一致挙動を保つ。
                        if ($index >= $expectedEntryCount) {
                            throw new RuntimeException('matrix.def entry count does not match header sizes.');
                        }

                        $cost = (int) $costStr;

                        if ($cost < -32_768 || $cost > 32_767) {
                            throw new RuntimeException(sprintf(
                                'matrix.def line %d cost is outside signed short range.',
                                $lineNumber,
                            ));
                        }

                        // right-major offset へ signed short を直接バイト代入する(syscall を伴わない O(1))。
                        if ($littleEndian) {
                            // LE 環境では pack('s') を介さず下位/上位バイトを直接代入し、関数呼び出しを省く。
                            $costs[$offset] = chr($cost & 0xFF);
                            $costs[$offset + 1] = chr(($cost >> 8) & 0xFF);
                        }

                        if (!$littleEndian) {
                            // LE 以外では native endian 出力を保つため従来どおり pack('s') の結果を代入する。
                            $short = pack('s', $cost);
                            $costs[$offset] = $short[0];
                            $costs[$offset + 1] = $short[1];
                        }

                        $index++;

                        // 次に期待する文脈 ID と offset を進める。right が rightSize に達したら left を繰り上げる。
                        $expectedRightId++;
                        $offset += $leftSizeTimes2;

                        if ($expectedRightId === $rightSize) {
                            $expectedRightId = 0;
                            $expectedLeftId++;
                            $leftPrefix = $expectedLeftId . ' ';
                            $leftPrefixLen = strlen($leftPrefix);
                            $offset = $expectedLeftId * 2;
                        }

                        continue;
                    }
                }

                // fast path 不採用行は現行の trim + preg ロジックそのままで処理し、挙動を完全維持する。
                $this->consumeFallbackLine(
                    $line,
                    $lineNumber,
                    $expectedEntryCount,
                    $leftSize,
                    $rightSize,
                    $littleEndian,
                    $costs,
                    $index,
                    $expectedLeftId,
                    $expectedRightId,
                    $leftPrefix,
                    $leftPrefixLen,
                    $offset,
                );
            }
        }

        // 末尾改行なしファイルでは最終行が carry に残る。fgets 挙動と一致させて最終行として処理する。
        if ($carry !== '') {
            $lineNumber++;
            $this->consumeFallbackLine(
                $carry,
                $lineNumber,
                $expectedEntryCount,
                $leftSize,
                $rightSize,
                $littleEndian,
                $costs,
                $index,
                $expectedLeftId,
                $expectedRightId,
                $leftPrefix,
                $leftPrefixLen,
                $offset,
            );
        }

        if ($index !== $expectedEntryCount) {
            throw new RuntimeException('matrix.def entry count does not match header sizes.');
        }

        return ['header' => pack('l', $leftSize) . pack('l', $rightSize), 'costs' => $costs];
    }

    /**
     * fast path を外れた 1 行を、現行の trim + preg ロジックで処理し挙動を完全維持する fallback。
     *
     * 空行は skip し、それ以外は parse・順序検証・範囲検証を経て right-major offset へ書き込む。
     * 受理時はカウンタ群($index/$expectedLeftId/$expectedRightId)と offset 基点を fast path と整合する形で更新する。
     */
    private function consumeFallbackLine(
        string $rawLine,
        int $lineNumber,
        int $expectedEntryCount,
        int $leftSize,
        int $rightSize,
        bool $littleEndian,
        string &$costs,
        int &$index,
        int &$expectedLeftId,
        int &$expectedRightId,
        string &$leftPrefix,
        int &$leftPrefixLen,
        int &$offset,
    ): void {
        $line = trim($rawLine);

        if ($line === '') {
            return;
        }

        // 余剰行は parse/順序検証より前に弾き、既存の entry count 不一致挙動を保つ。
        if ($index >= $expectedEntryCount) {
            throw new RuntimeException('matrix.def entry count does not match header sizes.');
        }

        [$leftId, $rightId, $cost] = $this->parseCostLine($line, $lineNumber);

        if ($leftId !== $expectedLeftId || $rightId !== $expectedRightId) {
            throw new RuntimeException(sprintf('matrix.def line %d has unexpected context ids.', $lineNumber));
        }

        // fallback 受理行も expected と同値なので、fast path と同じインクリメンタル offset へそのまま書き込む。
        if ($littleEndian) {
            $costs[$offset] = chr($cost & 0xFF);
            $costs[$offset + 1] = chr(($cost >> 8) & 0xFF);
        }

        if (!$littleEndian) {
            $short = pack('s', $cost);
            $costs[$offset] = $short[0];
            $costs[$offset + 1] = $short[1];
        }

        $index++;

        // 次に期待する文脈 ID と offset を進める。right が rightSize に達したら left を繰り上げる。
        $expectedRightId++;
        $offset += $leftSize * 2;

        if ($expectedRightId === $rightSize) {
            $expectedRightId = 0;
            $expectedLeftId++;
            $leftPrefix = $expectedLeftId . ' ';
            $leftPrefixLen = strlen($leftPrefix);
            $offset = $expectedLeftId * 2;
        }
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

        if (!mkdir($directory, permissions: 0777, recursive: true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('failed to create output directory "%s".', $directory));
        }
    }

    /**
     * matrix.bin を一時ファイルへ書き切ってから rename し、失敗時に壊れた出力を残さない。
     */
    private function writeBinaryFile(string $fileName, string $header, string $costs): void
    {
        $temporaryName = $fileName . '.tmp';
        $handle = fopen($temporaryName, mode: 'w');

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
