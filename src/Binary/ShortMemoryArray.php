<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Binary\Contract\ShortArrayReader;
use RuntimeException;

/**
 * signed short 値の配列を reader から一括で読み込み、メモリ上から返す。
 *
 * 内部表現は親クラス IntMemoryArray と同じく通常 PHP 配列（packed list）を使う。
 */
class ShortMemoryArray extends IntMemoryArray implements ShortArray
{
    /**
     * reader から必要件数の signed short 値を読み込み、以後の参照用に保持する。
     */
    public static function fromReader(object $reader, int $count): self
    {
        if (!$reader instanceof ShortArrayReader) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return new self($reader->getShortArray($count));
    }
}
