<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Contract;

/**
 * 形態素同士の連接コストを提供する、ストレージ非依存の連接コスト行列境界。
 */
interface ConnectionMatrix
{
    /**
     * 左文脈 ID と右文脈 ID の組に対応する連接コストを返す。
     */
    public function linkCost(int $leftId, int $rightId): int;
}
