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
     * キー集合を tail 圧縮付きの double-array trie に配置し、word2id バイナリとして書き込む。
     *
     * @param array<string, int> $keys
     */
    public function build(array $keys, string $fileName): void
    {
        $this->assertContiguousIds($keys);

        $root = $this->buildTrie($keys);
        $usedIndexes = [];
        $nextBaseCandidate = 1;
        $this->assignBases($root, $usedIndexes, $nextBaseCandidate);
        $nodeSize = $this->nodeSize($usedIndexes);
        $base = array_fill(0, $nodeSize, 0);
        $chck = array_fill(0, $nodeSize, 0);
        $base[0] = $this->assignedBase($root);
        $begs = array_fill(0, count($keys), 0);
        $lens = array_fill(0, count($keys), 0);
        $tail = [];
        $this->emitNode($root, $base, $chck, $begs, $lens, $tail);
        $this->writeBinaryFile($fileName, $this->dictionaryBinary(
            array_values($base),
            array_values($chck),
            $begs,
            $lens,
            $tail,
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

            foreach ($this->utf16CodeUnits((string) $key) as $code) {
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
     * 分岐の多い trie ノードから順に、衝突しない base 値を割り当てる。
     *
     * @param array<int, true> $usedIndexes
     */
    private function assignBases(TrieBuildNode $root, array &$usedIndexes, int &$nextBaseCandidate): void
    {
        $nodes = $this->nodesNeedingBase($root);
        usort(
            $nodes,
            static fn(TrieBuildNode $left, TrieBuildNode $right): int => (
                count($right->children) <=> count($left->children)
            ),
        );

        foreach ($nodes as $node) {
            $base = $this->findBase($node, $usedIndexes, $nextBaseCandidate);
            $node->base = $base;
            $usedIndexes[$base] = true;

            foreach (array_keys($node->children) as $code) {
                $usedIndexes[$base + $code] = true;
            }
        }
    }

    /**
     * tail 圧縮されず double-array 上に配置が必要な trie ノードだけを集める。
     *
     * @return list<TrieBuildNode>
     */
    private function nodesNeedingBase(TrieBuildNode $node): array
    {
        $nodes = [$node];

        foreach ($node->children as $child) {
            if ($this->compressedTail($child) !== null) {
                continue;
            }

            array_push($nodes, ...$this->nodesNeedingBase($child));
        }

        return $nodes;
    }

    /**
     * 既存配置済み index と衝突しない base 値を、前回の候補位置から前進して見つける。
     *
     * @param array<int, true> $usedIndexes
     */
    private function findBase(TrieBuildNode $node, array &$usedIndexes, int &$nextBaseCandidate): int
    {
        $childCodes = array_keys($node->children);

        for ($base = $nextBaseCandidate;; $base++) {
            if (isset($usedIndexes[$base])) {
                continue;
            }

            foreach ($childCodes as $code) {
                if (isset($usedIndexes[$base + $code])) {
                    continue 2;
                }
            }

            $nextBaseCandidate = $base + 1;

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
     * 配置済み trie ノードを Searcher の base/check 配列と tail 配列へ展開する。
     *
     * ipadic 規模の辞書では各配列が巨大になるため、再帰ごとの配列コピーを避けて同じバッファを更新する。
     *
     * @param array<int, int> $base
     * @param array<int, int> $chck
     * @param array<int, int> $begs
     * @param array<int, int> $lens
     * @param list<int> $tail
     */
    private function emitNode(
        TrieBuildNode $node,
        array &$base,
        array &$chck,
        array &$begs,
        array &$lens,
        array &$tail,
    ): void {
        $nodeBase = $this->assignedBase($node);

        if ($node->id === null) {
            $chck[$nodeBase] = 1;
        } else {
            $base[$nodeBase] = -($node->id + 1);
            $chck[$nodeBase] = 0;
        }

        foreach ($node->children as $code => $child) {
            $index = $nodeBase + $code;
            $chck[$index] = $code;
            $compressed = $this->compressedTail($child);

            if ($compressed !== null) {
                $id = $compressed['id'];
                $base[$index] = -($id + 1);
                $begs[$id] = count($tail);
                $lens[$id] = count($compressed['suffix']);
                array_push($tail, ...$compressed['suffix']);

                continue;
            }

            $base[$index] = $this->assignedBase($child);
            $this->emitNode($child, $base, $chck, $begs, $lens, $tail);
        }
    }

    /**
     * 途中に分岐や終端がない単一路なら、現在ノード以降を tail suffix として表現する。
     *
     * @return array{id:int, suffix:list<int>}|null
     */
    private function compressedTail(TrieBuildNode $node): ?array
    {
        if ($node->id !== null) {
            if ($node->children !== []) {
                return null;
            }

            return ['id' => $node->id, 'suffix' => []];
        }

        if (count($node->children) !== 1) {
            return null;
        }

        $code = array_key_first($node->children);
        $child = $node->children[$code];
        $compressed = $this->compressedTail($child);

        if ($compressed === null) {
            return null;
        }

        array_unshift($compressed['suffix'], $code);

        return $compressed;
    }

    /**
     * Searcher が読む順序でヘッダ、tail index、base、lens、check、tail を連結する。
     *
     * @param list<int> $base
     * @param list<int> $chck
     * @param array<int, int> $begs
     * @param array<int, int> $lens
     * @param list<int> $tail
     */
    private function dictionaryBinary(array $base, array $chck, array $begs, array $lens, array $tail): string
    {
        return (
            $this->packInts([count($base), count($begs), count($tail)])
            . $this->packInts(array_values($begs))
            . $this->packInts($base)
            . $this->packShorts(array_values($lens))
            . $this->packChars($chck)
            . $this->packChars($tail)
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
