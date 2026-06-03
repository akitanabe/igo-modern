<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/**
 * CharMemoryArray が必要な件数の unsigned short 値を読み込む入力元を表す。
 */
interface CharArrayReader
{
    /**
     * 現在位置から指定件数の unsigned short 値を読み込む。
     *
     * @return list<int>
     */
    public function getCharArray(int $count): array;
}
