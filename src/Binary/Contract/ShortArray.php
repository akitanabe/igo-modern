<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/**
 * signed short 値の配列をメモリまたはファイルから添字指定で読む境界を表す。
 */
interface ShortArray
{
    /**
     * 指定された添字に対応する signed short 値を int として返す。
     */
    public function get(int $idx): int;
}
