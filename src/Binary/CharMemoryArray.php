<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\CharArray;
use IgoModern\Binary\Contract\CharArrayReader;
use RuntimeException;

/**
 * unsigned short の文字コード配列を reader から一括で読み込み、メモリ上から返す。
 */
class CharMemoryArray extends IntMemoryArray implements CharArray
{
    /**
     * reader から必要件数の unsigned short 値を読み込み、以後の参照用に保持する。
     */
    public static function fromReader(object $reader, int $count): self
    {
        if (!$reader instanceof CharArrayReader) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return new self(self::fixedArrayFromValues($reader->getCharArray($count)));
    }
}
