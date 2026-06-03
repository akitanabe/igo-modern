<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * unsigned short の文字コード配列をメモリまたはファイルから添字指定で読む境界を表す。
 */
interface CharArray
{
    /**
     * 指定された添字に対応する unsigned short 値を int として返す。
     */
    public function get(int $idx): int;
}
