<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/**
 * ShortMemoryArray が必要な件数の signed short 値を読み込む入力元を表す。
 */
interface ShortArrayReader
{
    /**
     * 現在位置から指定件数の signed short 値を読み込む。
     *
     * @return list<int>
     */
    public function getShortArray(int $count): array;
}
