<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Analysis\ViterbiNode;

/**
 * WordDic が復元した単語候補ノードを受け取る境界を表す。
 */
interface WordDicCallback
{
    /**
     * 辞書検索で見つかった ViterbiNode を受け取る。
     */
    public function call(ViterbiNode $node): void;

    /**
     * 現在の開始位置で候補がまだ通知されていないかを返す。
     */
    public function isEmpty(): bool;
}
