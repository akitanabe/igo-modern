<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

/**
 * char.def のカテゴリ名を、未知語用エントリに対応する trie ID へ解決する。
 */
interface CategoryIdResolver
{
    /**
     * 出力済み辞書とカテゴリ名を使い、char.category に保存する未知語カテゴリ ID を返す。
     */
    public function resolve(string $outputDirectory, string $encoding, string $categoryName): int;
}
