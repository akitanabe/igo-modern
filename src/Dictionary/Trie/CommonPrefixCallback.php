<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Trie;

/**
 * Searcher が見つけた共通接頭辞の範囲と語 ID を受け取る境界を表す。
 */
interface CommonPrefixCallback
{
    /**
     * 見つかった一致範囲の開始位置、長さ、語 ID を受け取る。
     */
    public function call(int $start, int $offset, int $id): void;
}
