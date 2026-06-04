<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Binary\PagedBinaryReader;
use RuntimeException;

/**
 * word.dat の UTF-16 相当バイト列を必要な範囲だけファイルから読み込む。
 */
class WordDataReader
{
    /**
     * 事前に用意されたページ読み込み reader を保持する。
     */
    public function __construct(
        private PagedBinaryReader $reader,
    ) {}

    /**
     * 素性データの読み取り対象ファイルを開く。
     */
    public static function fromFile(string $fileName): self
    {
        return new self(PagedBinaryReader::fromFile($fileName));
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

        return $this->reader->readBytes($start * 2, $byteLength);
    }
}
