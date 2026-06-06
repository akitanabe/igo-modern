<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Storage\FileInputStreamFactory;
use IgoModern\Storage\PagedByteReaderFactory;
use RuntimeException;

/**
 * word2id の未知語カテゴリキーから char.category に保存する trie ID を解決する。
 */
class Word2IdCategoryIdResolver implements CategoryIdResolver
{
    /**
     * outputDirectory の word2id から "\002" prefix 付きカテゴリ名を探し、完全一致した trie ID を返す。
     */
    public function resolve(string $outputDirectory, string $encoding, string $categoryName): int
    {
        $key = $this->utf16CodeUnits("\002" . $categoryName);
        $callback = new ExactCategoryKeyCallback(count($key));

        // build 経路でも Lazy 読み込みを維持するため、実体化方式を内包した stream ファクトリを渡す。
        Searcher::fromFile(
            $outputDirectory . '/word2id',
            FileInputStreamFactory::lazy(new PagedByteReaderFactory()),
        )->eachCommonPrefix($key, 0, $callback);

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
