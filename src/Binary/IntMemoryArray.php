<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\IntArrayReader;

/**
 * int 値の配列を reader から一括で読み込み、メモリ上から返す。
 */
class IntMemoryArray implements IntArray
{
    /** @var list<int> 添字指定で返す int 値を保持する。 */
    protected array $array;

    /**
     * reader から必要件数の int 値を読み込み、以後の参照用に保持する。
     */
    public function __construct(IntArrayReader $reader, int $count)
    {
        $this->array = $reader->getIntArray($count);
    }

    /**
     * メモリ上に保持した int 値を指定添字で返す。
     */
    public function get(int $idx): int
    {
        return $this->array[$idx];
    }
}
