<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Trie;

use IgoModern\Binary\Contract\CharArray;

/**
 * Searcher が double-array trie のキーを現在位置から順に読むためのストリーム。
 */
class KeyStream
{
    /** @var list<int> 読み取り対象のキー文字コード列を保持する。 */
    private array $s;

    /**
     * キー文字コード列と読み取り開始位置を保持する。
     *
     * @param list<int> $key
     */
    public function __construct(
        array $key,
        private int $cur = 0,
    ) {
        $this->s = $key;
    }

    /**
     * 現在位置から prefix の指定範囲が続いているかを判定する。
     */
    public function startsWith(CharArray $prefix, int $beg, int $len): bool
    {
        if ((count($this->s) - $this->cur) < $len) {
            return false;
        }

        for ($i = 0; $i < $len; $i++) {
            if ($this->s[$this->cur + $i] !== $prefix->get($beg + $i)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 現在位置の値を返してカーソルを進め、終端では trie 用の 0 を返す。
     */
    public function read(): int
    {
        return $this->eos() ? 0 : $this->s[$this->cur++];
    }

    /**
     * カーソルがキー文字コード列の終端に到達しているかを返す。
     */
    public function eos(): bool
    {
        return $this->cur === count($this->s);
    }
}
