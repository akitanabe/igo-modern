<?php

declare(strict_types=1);

namespace IgoModern\Binary;

/**
 * unsigned short の文字コード配列を必要な添字だけファイルから読み込んで返す。
 */
class CharDynamicArray extends IntDynamicArray implements CharArray
{
    /**
     * 指定添字に対応する 2 バイト unsigned short をファイルから読む。
     */
    public function get(int $idx): int
    {
        return $this->readValue($idx, 2, 'S');
    }
}
