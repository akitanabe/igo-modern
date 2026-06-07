<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Binary\Contract\InputStreamFactory;
use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Dictionary\Trie\TrieLoader;

/**
 * ファイルから trie を読み込み Searcher へ復元するローダー。
 *
 * trie ファイル形式の知識（先頭 3 int ヘッダ＋各配列）を唯一ここへ集約する。
 * Build / runtime の両経路が共有するため、実体化方式（Lazy / Resident）は InputStreamFactory に委ねる。
 */
final class FileTrieLoader implements TrieLoader
{
    /**
     * trie ファイルの読み取りに使う stream factory を保持する。
     */
    public function __construct(
        private InputStreamFactory $streams,
    ) {}

    /**
     * Build 経路向けに Lazy 読み込みを内包した loader を組み立てる。
     *
     * runtime 経路では forBuild() を使わず FileBinaryDictionaryLoader が保持する $streams を引き継ぐ。
     */
    public static function forBuild(): self
    {
        return new self(FileInputStreamFactory::lazy(new PagedByteReaderFactory()));
    }

    /**
     * trie ファイルを開き、ヘッダと配列を読み取って Searcher を復元する。
     *
     * ファイル形式: nodeSize(int) tailIndexSize(int) tailSize(int) begs[] base[] lens[] chck[] tail[]
     */
    public function load(string $filePath): Searcher
    {
        $stream = $this->streams->open($filePath);

        try {
            $nodeSize = $stream->getInt();
            $tailIndexSize = $stream->getInt();
            $tailSize = $stream->getInt();

            return new Searcher(
                $tailIndexSize,
                $stream->getIntArrayInstance($tailIndexSize),
                $stream->getIntArrayInstance($nodeSize),
                $stream->getShortArrayInstance($tailIndexSize),
                $stream->getCharArrayInstance($nodeSize),
                $stream->getCharArrayInstance($tailSize),
            );
        } finally {
            $stream->close();
        }
    }
}
