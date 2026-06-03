<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * CLI などの利用層が形態素解析器へ依存するための最小インターフェイス。
 */
interface Parser
{
    /**
     * 入力テキストを解析し、表層形・素性・開始位置を持つ形態素列を返す。
     *
     * @return list<Morpheme>
     */
    public function parse(string $text): array;
}
