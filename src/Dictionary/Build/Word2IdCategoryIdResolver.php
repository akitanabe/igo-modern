<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use IgoModern\Dictionary\Trie\CommonPrefixCallback;
use IgoModern\Dictionary\Trie\Searcher;
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

        (new Searcher($outputDirectory . '/word2id'))->eachCommonPrefix($key, 0, $callback);

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

/**
 * Searcher の共通接頭辞通知から、カテゴリキーの完全一致 ID だけを保持する。
 */
class ExactCategoryKeyCallback implements CommonPrefixCallback
{
    /** 完全一致した trie ID を保持し、一致がない場合は null のままにする。 */
    private ?int $id = null;

    /**
     * 探索キーの長さを保持し、短い接頭辞一致を無視できるようにする。
     */
    public function __construct(
        private int $keyLength,
    ) {}

    /**
     * Searcher から通知された一致のうち、探索キー全体と同じ長さの ID だけを記録する。
     */
    public function call(int $start, int $offset, int $id): void
    {
        if ($start === 0 && $offset === $this->keyLength) {
            $this->id = $id;
        }
    }

    /**
     * 完全一致で解決できた trie ID を返す。
     */
    public function id(): ?int
    {
        return $this->id;
    }
}
