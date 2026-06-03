<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * 未知語処理で使う文字カテゴリの ID、最大長、探索条件を保持する値オブジェクト。
 */
class Category
{
    /**
     * 文字カテゴリの辞書 ID と未知語探索時の制御フラグを保持する。
     */
    public function __construct(
        public int $id,
        public int $length,
        public bool $invoke,
        public bool $group,
    ) {}
}
