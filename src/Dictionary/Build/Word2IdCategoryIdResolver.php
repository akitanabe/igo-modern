<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use IgoModern\Dictionary\Trie\TrieLoader;
use RuntimeException;

/**
 * word2id の未知語カテゴリキーから char.category に保存する trie ID を解決する。
 */
class Word2IdCategoryIdResolver implements CategoryIdResolver
{
    /**
     * trie ファイルを復元する loader を必須依存として受け取り、Storage 具象を直接生成しない。
     */
    public function __construct(
        private TrieLoader $trieLoader,
    ) {}

    /**
     * outputDirectory の word2id から "\002" prefix 付きカテゴリ名を探し、完全一致した trie ID を返す。
     */
    public function resolve(string $outputDirectory, string $encoding, string $categoryName): int
    {
        $key = $this->utf16CodeUnits("\002" . $categoryName);
        $callback = new ExactCategoryKeyCallback(count($key));

        $this->trieLoader->load($outputDirectory . '/word2id')->eachCommonPrefix($key, 0, $callback);

        $id = $callback->id();

        if ($id === null) {
            throw new RuntimeException(sprintf('unknown category "%s" is not registered in word2id.', $categoryName));
        }

        return $id;
    }

    /**
     * ASCII カテゴリキーを Searcher と同じ UTF-16LE code unit 配列へ変換する。
     *
     * @return list<int>
     */
    private function utf16CodeUnits(string $key): array
    {
        $binary = mb_convert_encoding($key, 'UTF-16LE', 'UTF-8');
        $values = unpack('S*', $binary);

        if ($values === false) {
            throw new RuntimeException('category key encoding failed.');
        }

        return array_values($values);
    }
}
