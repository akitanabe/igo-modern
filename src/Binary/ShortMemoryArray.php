<?php

declare(strict_types=1);

namespace IgoModern\Binary;

/**
 * signed short 値の配列を reader から一括で読み込み、メモリ上から返す。
 */
class ShortMemoryArray extends IntMemoryArray implements ShortArray
{
    /**
     * reader から必要件数の signed short 値を読み込み、以後の参照用に保持する。
     */
    public function __construct(ShortArrayReader $reader, int $count)
    {
        $this->array = $reader->getShortArray($count);
    }
}
