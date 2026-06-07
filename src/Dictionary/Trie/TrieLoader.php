<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Trie;

/**
 * 単一 trie ファイルから Searcher を復元する契約。
 *
 * Dictionary 層に置くことで Storage 具象から Build / runtime の両消費者を切り離す。
 * load() はパスを毎回受け取る（dataDir に束縛する BinaryDictionaryLoader とは前提が異なる）。
 */
interface TrieLoader
{
    /**
     * 指定された単一 trie ファイルから探索器を復元する。
     */
    public function load(string $filePath): Searcher;
}
