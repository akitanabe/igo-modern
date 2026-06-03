<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * IntMemoryArray が必要な件数の int 値を読み込む入力元を表す。
 */
interface IntArrayReader
{
    /**
     * 現在位置から指定件数の int 値を読み込む。
     *
     * @return list<int>
     */
    public function getIntArray(int $count): array;
}
