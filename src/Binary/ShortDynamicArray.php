<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\ShortArray;

/**
 * signed short 値の配列を必要な添字だけファイルから読み込んで返す。
 */
class ShortDynamicArray extends IntDynamicArray implements ShortArray
{
    /**
     * 読み取り対象ファイルを開き、指定 offset から読む signed short 配列を作る。
     */
    public static function fromFile(string $fileName, int $start): self
    {
        return new self(PagedBinaryReader::fromFile($fileName), $start);
    }

    /**
     * 指定添字に対応する 2 バイト signed short をファイルから読む。
     */
    public function get(int $idx): int
    {
        return $this->readValue($idx, 2, 's');
    }
}
