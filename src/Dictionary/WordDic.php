<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Binary\FileMappedInputStream;
use IgoModern\Dictionary\Trie\CommonPrefixCallback;
use IgoModern\Dictionary\Trie\Searcher;

/**
 * 単語辞書ファイル群を読み込み、表層形の一致から ViterbiNode と素性データを復元する。
 */
class WordDic
{
    /** 表層形の文字コード列から trie ID を引く double-array trie を保持する。 */
    private Searcher $trie;

    /** word.dat に格納された UTF-16 相当の素性バイト列全体を保持する。 */
    private string $data;

    /** @var list<int> trie ID から単語 ID 範囲へ変換する開始オフセット列を保持する。 */
    private array $indices;

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
        $this->data = FileMappedInputStream::getStringFromFile($dataDir . '/word.dat');
        $this->indices = FileMappedInputStream::getIntArrayFromFile($dataDir . '/word.ary.idx');

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

        return substr($this->data, $start << 1, ($end - $start) << 1);
    }

    /**
     * trie ID に対応する単語 ID 範囲を走査し、各単語の辞書属性から候補ノードを作る。
     */
    public function callWordRange(int $trieId, int $start, int $wordLength, bool $isSpace, WordDicCallback $fn): void
    {
        $end = $this->indices[$trieId + 1];

        for ($wordId = $this->indices[$trieId]; $wordId < $end; $wordId++) {
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

/**
 * Searcher の共通接頭辞通知を WordDic の単語候補通知へ変換する。
 */
class WordDicCallbackCaller implements CommonPrefixCallback
{
    /**
     * 変換先の辞書と呼び出し先 callback を保持する。
     */
    public function __construct(
        private WordDic $wordDic,
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
