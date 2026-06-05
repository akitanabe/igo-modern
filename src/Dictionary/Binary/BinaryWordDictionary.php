<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Binary;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Binary\FileMappedInputStream;
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
     * 辞書ディレクトリ内の word2id, word.dat, word.ary.idx, word.inf を読み込む。
     *
     * $reduce は配列の実体化方式（true=遅延読み / false=常駐）を選ぶ内部限定の引数。
     */
    public static function fromDataDir(string $dataDir, bool $reduce = true): self
    {
        $stream = FileMappedInputStream::fromFile($dataDir . '/word.inf', $reduce);

        try {
            $wordCount = intdiv($stream->size(), 4 + 2 + 2 + 2);

            return new self(
                Searcher::fromFile($dataDir . '/word2id', $reduce),
                WordDataReader::fromFile($dataDir . '/word.dat'),
                self::readIndices($dataDir . '/word.ary.idx', $reduce),
                $stream->getIntArrayInstance($wordCount),
                $stream->getShortArrayInstance($wordCount),
                $stream->getShortArrayInstance($wordCount),
                $stream->getShortArrayInstance($wordCount),
            );
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

    /**
     * word.ary.idx 全体を PHP 配列へ展開せず、trie ID 範囲の参照に必要な int 配列 reader を作る。
     */
    private static function readIndices(string $fileName, bool $reduce): IntArray
    {
        $stream = FileMappedInputStream::fromFile($fileName, $reduce);

        try {
            return $stream->getIntArrayInstance(intdiv($stream->size(), 4));
        } finally {
            $stream->close();
        }
    }
}
