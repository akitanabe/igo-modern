<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Contract;

use IgoModern\Dictionary\WordDicCallback;

/**
 * 未知語候補の生成までを自完結で行う、ストレージ非依存の未知語辞書境界。
 */
interface UnknownWordDictionary
{
    /**
     * 未知語候補の生成まで自完結で行い、ViterbiNode を callback へ通知する。
     *
     * 不変条件: 通知する ViterbiNode::$wordId は、同一 storage の
     * WordDictionary::wordData() で解決可能でなければならない。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void;
}
