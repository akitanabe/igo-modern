<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use RuntimeException;

/**
 * UTF-8 キーと trie ID の対応から、Searcher が読める word2id double-array trie を生成する。
 */
class DoubleArrayTrieBuilder
{
    /**
     * キー集合を tail 圧縮なしの double-array trie に配置し、word2id バイナリとして書き込む。
     *
     * @param array<string, int> $keys
     */
    public function build(array $keys, string $fileName): void
    {
        $this->assertContiguousIds($keys);

        $root = $this->buildTrie($keys);
        $usedIndexes = [];
        $this->assignBase($root, $usedIndexes);
        $nodeSize = $this->nodeSize($usedIndexes);
        $base = array_fill(0, $nodeSize, 0);
        $chck = array_fill(0, $nodeSize, 0);
        $base[0] = $this->assignedBase($root);
        [$base, $chck] = $this->emitNode($root, $base, $chck);
        $this->writeBinaryFile($fileName, $this->dictionaryBinary(
            array_values($base),
            array_values($chck),
            count($keys),
        ));
    }

    /**
     * Searcher の keySetSize と一致するように、trie ID が 0 から連続していることを保証する。
     *
     * @param array<string, int> $keys
     */
    private function assertContiguousIds(array $keys): void
    {
        $ids = array_values($keys);
        sort($ids);
        $expectedIds = count($keys) === 0 ? [] : range(0, count($keys) - 1);

        if ($ids !== $expectedIds) {
            throw new RuntimeException('trie ids must be contiguous from 0.');
        }
    }

    /**
     * 入力キーを通常の trie へ挿入し、double-array 配置前の木構造を作る。
     *
     * @param array<string, int> $keys
     */
    private function buildTrie(array $keys): TrieBuildNode
    {
        $root = new TrieBuildNode();

        foreach ($keys as $key => $id) {
            if ($key === '') {
                throw new RuntimeException('trie key must not be empty.');
            }

            if ($id < 0) {
                throw new RuntimeException('trie id must be non-negative.');
            }

            $node = $root;

            foreach ($this->utf16CodeUnits($key) as $code) {
                if (!isset($node->children[$code])) {
                    $node->children[$code] = new TrieBuildNode();
                }

                $node = $node->children[$code];
            }

            $node->id = $id;
        }

        return $root;
    }

    /**
     * 各 trie ノードに、終端スロットと子遷移が衝突しない最小の base 値を割り当てる。
     *
     * @param array<int, true> $usedIndexes
     */
    private function assignBase(TrieBuildNode $node, array &$usedIndexes): void
    {
        $base = $this->findBase($node, $usedIndexes);
        $node->base = $base;
        $usedIndexes[$base] = true;

        foreach (array_keys($node->children) as $code) {
            $usedIndexes[$base + $code] = true;
        }

        foreach ($node->children as $child) {
            $this->assignBase($child, $usedIndexes);
        }
    }

    /**
     * 既存配置済み index と衝突しない base 値を線形探索で見つける。
     *
     * @param array<int, true> $usedIndexes
     */
    private function findBase(TrieBuildNode $node, array $usedIndexes): int
    {
        for ($base = 1;; $base++) {
            if (isset($usedIndexes[$base])) {
                continue;
            }

            foreach (array_keys($node->children) as $code) {
                if (isset($usedIndexes[$base + $code])) {
                    continue 2;
                }
            }

            return $base;
        }
    }

    /**
     * 使用済み index の最大値から、base/check 配列に必要なサイズを算出する。
     *
     * @param array<int, true> $usedIndexes
     */
    private function nodeSize(array $usedIndexes): int
    {
        $maxIndex = 0;

        foreach (array_keys($usedIndexes) as $index) {
            $maxIndex = max($maxIndex, $index);
        }

        return $maxIndex + 1;
    }

    /**
     * 配置済みノードの base 値を取り出し、未配置ノードの混入を生成エラーとして扱う。
     */
    private function assignedBase(TrieBuildNode $node): int
    {
        if ($node->base === null) {
            throw new RuntimeException('trie node base is not assigned.');
        }

        return $node->base;
    }

    /**
     * 配置済み trie ノードを Searcher の base/check 配列へ展開する。
     *
     * @param array<int, int> $base
     * @param array<int, int> $chck
     * @return array{0:array<int, int>, 1:array<int, int>}
     */
    private function emitNode(TrieBuildNode $node, array $base, array $chck): array
    {
        $nodeBase = $this->assignedBase($node);

        if ($node->id === null) {
            $chck[$nodeBase] = 1;
        } else {
            $base[$nodeBase] = -($node->id + 1);
            $chck[$nodeBase] = 0;
        }

        foreach ($node->children as $code => $child) {
            $index = $nodeBase + $code;
            $base[$index] = $this->assignedBase($child);
            $chck[$index] = $code;
            [$base, $chck] = $this->emitNode($child, $base, $chck);
        }

        return [$base, $chck];
    }

    /**
     * Searcher が読む順序でヘッダ、tail index、base、lens、check を連結する。
     *
     * @param list<int> $base
     * @param list<int> $chck
     */
    private function dictionaryBinary(array $base, array $chck, int $keyCount): string
    {
        return (
            $this->packInts([count($base), $keyCount, 0])
            . $this->packInts(array_fill(0, $keyCount, 0))
            . $this->packInts($base)
            . $this->packShorts(array_fill(0, $keyCount, 0))
            . $this->packChars($chck)
        );
    }

    /**
     * UTF-8 キーを Searcher と同じ UTF-16LE code unit 配列へ変換する。
     *
     * @return list<int>
     */
    private function utf16CodeUnits(string $key): array
    {
        $binary = mb_convert_encoding($key, 'UTF-16LE', 'UTF-8');
        $values = unpack('S*', $binary);

        if ($values === false) {
            throw new RuntimeException('trie key encoding failed.');
        }

        return array_values($values);
    }

    /**
     * int 値の列を native endian の連続バイナリへ変換する。
     *
     * @param list<int> $values
     */
    private function packInts(array $values): string
    {
        $binary = '';

        foreach ($values as $value) {
            $binary .= pack('l', $value);
        }

        return $binary;
    }

    /**
     * signed short 値の列を native endian の連続バイナリへ変換する。
     *
     * @param list<int> $values
     */
    private function packShorts(array $values): string
    {
        $binary = '';

        foreach ($values as $value) {
            $binary .= pack('s', $value);
        }

        return $binary;
    }

    /**
     * unsigned short 値の列を native endian の連続バイナリへ変換する。
     *
     * @param list<int> $values
     */
    private function packChars(array $values): string
    {
        $binary = '';

        foreach ($values as $value) {
            $binary .= pack('S', $value);
        }

        return $binary;
    }

    /**
     * word2id を一括で書き込み、短い書き込みを辞書生成失敗として扱う。
     */
    private function writeBinaryFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);

        if ($writtenBytes !== strlen($contents)) {
            throw new RuntimeException(sprintf('failed to write word2id "%s".', $fileName));
        }
    }
}

/**
 * double-array 配置前の trie ノードとして、子遷移、終端 ID、base 値を保持する。
 */
class TrieBuildNode
{
    /** 完全一致するキーがある場合の trie ID を保持する。 */
    public ?int $id = null;

    /** double-array 内でこのノードの遷移基準になる base offset を保持する。 */
    public ?int $base = null;

    /** @var array<int, self> UTF-16 code unit ごとの子ノードを保持する。 */
    public array $children = [];
}
