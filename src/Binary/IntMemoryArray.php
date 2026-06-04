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
     * 読み込み済みの固定長 int 値列を、添字参照用に保持する。
     *
     * @param SplFixedArray<int> $array
     */
    public function __construct(SplFixedArray $array)
    {
        $this->array = $array;
    }

    /**
     * reader から必要件数の int 値を読み込み、メモリ配列を作る。
     */
    public static function fromReader(object $reader, int $count): self
    {
        if (!$reader instanceof IntArrayReader) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return new self(self::fixedArrayFromValues($reader->getIntArray($count)));
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
