<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Dictionary\Binary\BinaryWordDictionary;
use IgoModern\Dictionary\Trie\CommonPrefixCallback;

/**
 * Searcher の共通接頭辞通知を BinaryWordDictionary の単語候補通知へ変換する。
 *
 * 状態は変換先 callback だけで、再帰・並行利用はないため単一インスタンスを使い回す。
 * BinaryWordDictionary が 1 つ保持し、search ごとに setCallback で $fn だけ差し替えて
 * 開始位置ごとの new を避ける（parse 1 回あたり高々 0 回の追加割り当て）。
 */
class WordDicCallbackCaller implements CommonPrefixCallback
{
    /** 現在の変換先 callback を保持する。search ごとに差し替えられる。 */
    private WordDicCallback $fn;

    /**
     * 変換先の辞書を保持する。
     *
     * callWordRange を呼ぶため、interface ではなく具象 BinaryWordDictionary に依存する。
     */
    public function __construct(
        private BinaryWordDictionary $wordDic,
    ) {}

    /**
     * 次の探索で使う変換先 callback を差し替え、インスタンスを再利用可能にする。
     */
    public function setCallback(WordDicCallback $fn): void
    {
        $this->fn = $fn;
    }

    /**
     * trie ID に紐づく単語候補を通常単語として展開する。
     */
    public function call(int $start, int $offset, int $id): void
    {
        $this->wordDic->callWordRange($id, $start, $offset, false, $this->fn);
    }
}
