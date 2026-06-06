<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Binary\Contract\IntArray;

/**
 * 文字コードから未知語カテゴリを引き、同じ未知語として連結できる文字種か判定する。
 */
class CharCategory
{
    /**
     * 事前に読み込まれたカテゴリ定義と文字コード表を保持する。
     *
     * @param list<Category> $categories
     */
    public function __construct(
        private array $categories,
        private IntArray $char2id,
        private IntArray $eqlMasks,
    ) {}

    /**
     * 指定された文字コードに割り当てられた未知語カテゴリ定義を返す。
     */
    public function category(int $code): Category
    {
        return $this->categories[$this->char2id->get($code)];
    }

    /**
     * 2 つの文字コードの互換性マスクに共通ビットがある場合に同じ未知語へ連結可能とみなす。
     */
    public function isCompatible(int $code1, int $code2): bool
    {
        return ($this->eqlMasks->get($code1) & $this->eqlMasks->get($code2)) !== 0;
    }
}
