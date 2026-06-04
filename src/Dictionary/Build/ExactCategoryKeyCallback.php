<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use IgoModern\Dictionary\Trie\CommonPrefixCallback;

/**
 * Searcher の共通接頭辞通知から、カテゴリキーの完全一致 ID だけを保持する。
 */
class ExactCategoryKeyCallback implements CommonPrefixCallback
{
    /** 完全一致した trie ID を保持し、一致がない場合は null のままにする。 */
    private ?int $id = null;

    /**
     * 探索キーの長さを保持し、短い接頭辞一致を無視できるようにする。
     */
    public function __construct(
        private int $keyLength,
    ) {}

    /**
     * Searcher から通知された一致のうち、探索キー全体と同じ長さの ID だけを記録する。
     */
    public function call(int $start, int $offset, int $id): void
    {
        if ($start === 0 && $offset === $this->keyLength) {
            $this->id = $id;
        }
    }

    /**
     * 完全一致で解決できた trie ID を返す。
     */
    public function id(): ?int
    {
        return $this->id;
    }
}
