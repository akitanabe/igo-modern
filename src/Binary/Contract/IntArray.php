<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/**
 * int 値の配列をメモリまたはファイルから添字指定で読む境界を表す。
 */
interface IntArray
{
    /**
     * 指定された添字に対応する int 値を返す。
     */
    public function get(int $idx): int;
}
