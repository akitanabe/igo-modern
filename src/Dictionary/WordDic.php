<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Binary\FileMappedInputStream;
use IgoModern\Dictionary\Trie\Searcher;

/**
 * 単語辞書ファイル群を読み込み、表層形の一致から ViterbiNode と素性データを復元する。
 */
class WordDic
{
    /** 表層形の文字コード列から trie ID を引く double-array trie を保持する。 */
    private Searcher $trie;

    /** word.dat に格納された UTF-16 相当の素性バイト列を必要範囲だけ読む。 */
    private WordDataReader $data;

    /** trie ID から単語 ID 範囲へ変換する開始オフセット列を必要な添字だけ読む。 */
    private IntArray $indices;

    /** 単語 ID ごとの単語コストを参照する。 */
    private ShortArray $costs;

    /** 単語 ID ごとの左文脈 ID を参照する。 */
    private ShortArray $leftIds;

    /** 単語 ID ごとの右文脈 ID を参照する。 */
    private ShortArray $rightIds;

    /** 単語 ID ごとの素性データ開始位置を UTF-16 文字単位で参照する。 */
    private IntArray $dataOffsets;

    /**
     * 辞書ディレクトリ内の word2id, word.dat, word.ary.idx, word.inf を読み込む。
     */
    public function __construct(string $dataDir)
    {
        $this->trie = new Searcher($dataDir . '/word2id');
        $this->data = new WordDataReader($dataDir . '/word.dat');
        $this->indices = $this->readIndices($dataDir . '/word.ary.idx');

        $stream = new FileMappedInputStream($dataDir . '/word.inf');

        try {
            $wordCount = intdiv($stream->size(), 4 + 2 + 2 + 2);
            $this->dataOffsets = $stream->getIntArrayInstance($wordCount);
            $this->leftIds = $stream->getShortArrayInstance($wordCount);
            $this->rightIds = $stream->getShortArrayInstance($wordCount);
            $this->costs = $stream->getShortArrayInstance($wordCount);
        } finally {
            $stream->close();
        }
    }

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
     * trie ID に紐づく単語 ID 範囲を、未知語処理から渡された長さ・空白属性で候補化する。
     */
    public function searchFromTrieId(int $trieId, int $start, int $wordLength, bool $isSpace, WordDicCallback $fn): void
    {
        $this->callWordRange($trieId, $start, $wordLength, $isSpace, $fn);
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

    /**
     * word.ary.idx 全体を PHP 配列へ展開せず、trie ID 範囲の参照に必要な int 配列 reader を作る。
     */
    private function readIndices(string $fileName): IntArray
    {
        $stream = new FileMappedInputStream($fileName);

        try {
            return $stream->getIntArrayInstance(intdiv($stream->size(), 4));
        } finally {
            $stream->close();
        }
    }
}
