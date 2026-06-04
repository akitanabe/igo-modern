<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\IntArrayReader;
use RuntimeException;
use SplFixedArray;

/**
 * int 値の配列を reader から一括で読み込み、メモリ上から返す。
 */
class IntMemoryArray implements IntArray
{
    /** @var SplFixedArray<int> 添字指定で返す固定件数の int 値を保持する。 */
    protected SplFixedArray $array;

    /**
     * reader から必要件数の int 値を読み込み、以後の参照用に保持する。
     */
    public function __construct(IntArrayReader $reader, int $count)
    {
        $this->array = self::fixedArrayFromValues($reader->getIntArray($count));
    }

    /**
     * メモリ上に保持した int 値を指定添字で返す。
     */
    public function get(int $idx): int
    {
        $value = $this->array[$idx];

        if (!is_int($value)) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $value;
    }

    /**
     * reader が返した固定長の int 値列を SplFixedArray へ詰め替える。
     *
     * @param list<int> $values
     * @return SplFixedArray<int>
     */
    protected static function fixedArrayFromValues(array $values): SplFixedArray
    {
        return SplFixedArray::fromArray($values, false);
    }
}
