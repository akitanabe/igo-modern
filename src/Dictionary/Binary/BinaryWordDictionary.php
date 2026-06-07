<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Binary;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Dictionary\Contract\WordDictionary;
use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Dictionary\WordDataReader;
use IgoModern\Dictionary\WordDicCallback;
use IgoModern\Dictionary\WordDicCallbackCaller;

/**
 * 単語辞書ファイル群を読み込み、表層形の一致から ViterbiNode と素性データを復元する。
 */
class BinaryWordDictionary implements WordDictionary
{
    /**
     * 事前に読み込まれた単語辞書の構成要素を保持する。
     */
    public function __construct(
        private Searcher $trie,
        private WordDataReader $data,
        private IntArray $indices,
        private IntArray $dataOffsets,
        private ShortArray $leftIds,
        private ShortArray $rightIds,
        private ShortArray $costs,
    ) {}

    /**
     * 指定位置から入力に一致する単語を trie で探し、単語候補ノードとして通知する。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void
    {
        $this->trie->eachCommonPrefix($text, $start, new WordDicCallbackCaller($this, $fn));
    }

    /**
     * 指定単語 ID の素性データを UTF-16 相当のバイト列のまま返す。
     */
    public function wordData(int $wordId): string
    {
        $start = $this->dataOffsets->get($wordId);
        $end = $this->dataOffsets->get($wordId + 1);

        return $this->data->readCodeUnitSlice($start, $end);
    }

    /**
     * trie ID に対応する単語 ID 範囲を走査し、各単語の辞書属性から候補ノードを作る。
     *
     * バイナリ辞書フォーマット内部の実装詳細であり、WordDictionary 契約には載せない。
     */
    public function callWordRange(int $trieId, int $start, int $wordLength, bool $isSpace, WordDicCallback $fn): void
    {
        $end = $this->indices->get($trieId + 1);

        for ($wordId = $this->indices->get($trieId); $wordId < $end; $wordId++) {
            $fn->call(
                new ViterbiNode(
                    $wordId,
                    $start,
                    $wordLength,
                    $this->costs->get($wordId),
                    $this->leftIds->get($wordId),
                    $this->rightIds->get($wordId),
                    $isSpace,
                ),
            );
        }
    }
}
