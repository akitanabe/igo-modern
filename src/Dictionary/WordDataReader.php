<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use RuntimeException;

/**
 * word.dat の UTF-16 相当バイト列を必要な範囲だけファイルから読み込む。
 */
class WordDataReader
{
    /** @var resource word.dat を範囲読み込みするためのファイルハンドルを保持する。 */
    private $file;

    /**
     * 素性データの読み取り対象ファイルを開く。
     */
    public function __construct(string $fileName)
    {
        $file = fopen($fileName, 'rb');

        if ($file === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $this->file = $file;
    }

    /**
     * 開いているファイルハンドルを閉じ、範囲読み込みのリソースを解放する。
     */
    public function __destruct()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    /**
     * UTF-16 code unit の開始・終了オフセットに対応する素性バイト列を返す。
     */
    public function readCodeUnitSlice(int $start, int $end): string
    {
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $codeUnitLength = $end - $start;

        if ($codeUnitLength === 0) {
            return '';
        }

        $byteLength = $codeUnitLength * 2;

        if ($byteLength < 1) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $seekResult = fseek($this->file, $start * 2);

        if ($seekResult !== 0) {
            throw new RuntimeException('dictionary seeking failed.');
        }

        $bytes = fread($this->file, $byteLength);

        if ($bytes === false || strlen($bytes) !== $byteLength) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $bytes;
    }
}
