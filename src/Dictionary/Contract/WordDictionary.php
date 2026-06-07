<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Contract;

use IgoModern\Dictionary\WordDicCallback;

/**
 * 既知語の検索と素性データ解決を提供する、ストレージ非依存の単語辞書境界。
 */
interface WordDictionary
{
    /**
     * 既知語の共通接頭辞探索を行い、一致した語を ViterbiNode として callback へ通知する。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void;

    /**
     * wordId に対応する素性データを返す。
     */
    public function wordData(int $wordId): string;
}
