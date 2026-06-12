<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Binary;

use IgoModern\Binary\Contract\RawIntValues;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Dictionary\Contract\ConnectionMatrix;

/**
 * 形態素同士の連接コストを matrix.bin から読み込み、左・右 ID の組で参照する。
 *
 * 常駐メモリ（RawIntValues）の場合は生配列を rawCosts() で公開し、Tagger のホットパスから
 * linkCost() のメソッド呼び出しを排して直接添字参照へインライン化できるようにする。
 */
class BinaryConnectionMatrix implements ConnectionMatrix
{
    /**
     * 事前に読み込まれた行幅と連接コスト表を保持する。
     */
    public function __construct(
        private int $leftSize,
        private ShortArray $matrix,
    ) {}

    /**
     * 右 ID を行、左 ID を列として平坦化された連接コストを返す。
     */
    public function linkCost(int $leftId, int $rightId): int
    {
        return $this->matrix->get(($rightId * $this->leftSize) + $leftId);
    }

    /**
     * 連接コスト表が常駐メモリなら生配列を、ファイル遅延読みなら null を返す。
     *
     * 呼び出し元はこの戻り値の有無でホットパスを fast / fallback に分岐する。
     *
     * @return list<int>|null
     */
    public function rawCosts(): ?array
    {
        return $this->matrix instanceof RawIntValues ? $this->matrix->values() : null;
    }

    /**
     * 生配列から添字を算出するために必要な行幅（左文脈 ID の総数）を返す。
     */
    public function leftSize(): int
    {
        return $this->leftSize;
    }
}
