<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * Viterbi 探索中の単語候補と最小コスト経路へのリンクを保持するノード。
 */
class ViterbiNode
{
    /** 最小コスト経路で直前に接続するノードを保持する。 */
    public ?self $prev = null;

    /**
     * 経路探索に必要な単語候補の属性を初期状態として保持する。
     */
    public function __construct(
        /** 辞書内の単語 ID を保持する。 */
        public int $wordId,
        /** 入力テキスト内で形態素が始まる位置を保持する。 */
        public int $start,
        /** 形態素の表層形の長さを文字数で保持する。 */
        public int $length,
        /** 始点からこのノードまでの累積コストを保持する。 */
        public int $cost,
        /** 接続コスト計算に使う左文脈 ID を保持する。 */
        public int $leftId,
        /** 接続コスト計算に使う右文脈 ID を保持する。 */
        public int $rightId,
        /** 空白カテゴリの形態素かどうかを保持する。 */
        public bool $isSpace,
    ) {}

    /**
     * 文頭または文末を表すゼロ値の番兵ノードを生成する。
     */
    public static function makeBOSEOS(): self
    {
        return new self(0, 0, 0, 0, 0, 0, false);
    }
}
