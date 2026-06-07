<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Dictionary\Binary\BinaryWordDictionary;
use IgoModern\Dictionary\Trie\CommonPrefixCallback;

/**
 * Searcher の共通接頭辞通知を BinaryWordDictionary の単語候補通知へ変換する。
 */
class WordDicCallbackCaller implements CommonPrefixCallback
{
    /**
     * 変換先の辞書と呼び出し先 callback を保持する。
     *
     * callWordRange を呼ぶため、interface ではなく具象 BinaryWordDictionary に依存する。
     */
    public function __construct(
        private BinaryWordDictionary $wordDic,
        private WordDicCallback $fn,
    ) {}

    /**
     * trie ID に紐づく単語候補を通常単語として展開する。
     */
    public function call(int $start, int $offset, int $id): void
    {
        $this->wordDic->callWordRange($id, $start, $offset, false, $this->fn);
    }
}
