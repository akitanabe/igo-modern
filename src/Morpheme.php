<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * 形態素の解析結果を表層形・素性・開始位置として保持する値オブジェクト。
 */
class Morpheme
{
    /**
     * 解析結果として渡された値を公開プロパティとして保持する。
     */
    public function __construct(
        /** 形態素の表層形を保持する。 */
        public string $surface,
        /** 形態素に対応する辞書素性を保持する。 */
        public string $feature,
        /** 入力テキスト内で形態素が始まる位置を保持する。 */
        public int $start,
    ) {}
}
