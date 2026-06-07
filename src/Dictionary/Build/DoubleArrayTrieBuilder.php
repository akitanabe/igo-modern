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
     * キーは概念上 UTF-8 文字列だが、PHP は "1" 等の数値文字列キーを int へ自動変換するため、
     * 受け取り型は array-key（int|string）とし、内部で (string) キャストして扱う。
     *
     * @param array<array-key, int> $keys
     */
    public function build(array $keys, string $fileName): void
    {
        $this->assertContiguousIds($keys);

        $root = $this->buildTrie($keys);
        $usedIndexes = [];
        $nextBaseCandidate = 1;
        $this->assignBases($root, $usedIndexes, $nextBaseCandidate);
        $nodeSize = $this->nodeSize($usedIndexes);
        // base/chck 確保前に配置済み index 集合を解放し、ピークの重なりを避ける。
        unset($usedIndexes);

        $keySetSize = count($keys);
        // base/chck/begs/lens は最終ファイル形式そのままのネイティブバイナリバッファとして確保する。
        // UniDic 規模では PHP 配列(1要素≒80B)が peak を押し上げるため、要素数ぶんのバイト列へ
        // オフセット直接代入する。未書込スロットは \0(=0) のままで従来の array_fill(...,0) と等価。
        $base = str_repeat("\0", $nodeSize * 4);
        $chck = str_repeat("\0", $nodeSize * 2);
        $begs = str_repeat("\0", $keySetSize * 4);
        $lens = str_repeat("\0", $keySetSize * 2);
        $tail = '';

        $this->writeInt32($base, 0, $this->assignedBase($root));
        $this->emitNode($root, $base, $chck, $begs, $lens, $tail);
        $this->writeBinaryFile($fileName, $this->dictionaryBinary($base, $chck, $begs, $lens, $tail));
    }

    /**
     * Searcher の keySetSize と一致するように、trie ID が 0 から連続していることを保証する。
     *
     * @param array<array-key, int> $keys
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
     * @param array<array-key, int> $keys
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
     * 配置済み trie ノードを Searcher の base/check バッファと tail バッファへ展開する。
     *
     * 各バッファは最終ファイル形式そのままのネイティブバイナリ。再帰ごとのコピーを避けるため参照渡しし、
     * base/chck/begs/lens へはオフセット直接代入、tail へは末尾 append のみ行う（バッファを read しない）。
     */
    private function emitNode(
        TrieBuildNode $node,
        string &$base,
        string &$chck,
        string &$begs,
        string &$lens,
        string &$tail,
    ): void {
        $nodeBase = $this->assignedBase($node);

        if ($node->id === null) {
            $this->writeUint16($chck, $nodeBase * 2, 1);
        } else {
            $this->writeInt32($base, $nodeBase * 4, -($node->id + 1));
            $this->writeUint16($chck, $nodeBase * 2, 0);
        }

        foreach ($node->children as $code => $child) {
            $index = $nodeBase + $code;
            $this->writeUint16($chck, $index * 2, $code);
            $compressed = $this->compressedTail($child);

            if ($compressed !== null) {
                $id = $compressed['id'];
                $this->writeInt32($base, $index * 4, -($id + 1));
                // begs は append 前の tail code unit 数（= strlen/2）を記録する。
                $this->writeInt32($begs, $id * 4, intdiv(strlen($tail), 2));
                $this->writeInt16($lens, $id * 2, count($compressed['suffix']));

                foreach ($compressed['suffix'] as $unit) {
                    $tail .= pack('S', $unit);
                }

                continue;
            }

            $this->writeInt32($base, $index * 4, $this->assignedBase($child));
            $this->emitNode($child, $base, $chck, $begs, $lens, $tail);
        }
    }

    /**
     * 確保済みバッファの指定オフセットへ signed int32 を native endian で上書きする。
     */
    private function writeInt32(string &$buffer, int $offset, int $value): void
    {
        if ($value < -2_147_483_648 || $value > 2_147_483_647) {
            throw new RuntimeException('trie int32 value out of range.');
        }

        $this->writeBytes($buffer, $offset, pack('l', $value), 4);
    }

    /**
     * 確保済みバッファの指定オフセットへ signed short を native endian で上書きする。
     */
    private function writeInt16(string &$buffer, int $offset, int $value): void
    {
        if ($value < -32_768 || $value > 32_767) {
            throw new RuntimeException('trie int16 value out of range.');
        }

        $this->writeBytes($buffer, $offset, pack('s', $value), 2);
    }

    /**
     * 確保済みバッファの指定オフセットへ unsigned short を native endian で上書きする。
     */
    private function writeUint16(string &$buffer, int $offset, int $value): void
    {
        if ($value < 0 || $value > 65_535) {
            throw new RuntimeException('trie uint16 value out of range.');
        }

        $this->writeBytes($buffer, $offset, pack('S', $value), 2);
    }

    /**
     * 確保済み範囲だけをバイト単位で上書きする。範囲外は文字列自動拡張による
     * padding 混入(=辞書破損)を招くため RuntimeException で弾く。
     */
    private function writeBytes(string &$buffer, int $offset, string $bytes, int $width): void
    {
        if ($offset < 0 || ($offset + $width) > strlen($buffer)) {
            throw new RuntimeException('trie buffer write out of bounds.');
        }

        for ($i = 0; $i < $width; $i++) {
            $buffer[$offset + $i] = $bytes[$i];
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
     * Searcher が読む順序でヘッダ、begs、base、lens、chck、tail を連結する。
     *
     * 各引数は既にネイティブバイナリのバッファなので再パックは不要。ヘッダ件数は要素数を別管理せず
     * バッファ byte 長から算出する（tailSize は code unit 数 = strlen/2。byte 長のままだと即破損）。
     */
    private function dictionaryBinary(string $base, string $chck, string $begs, string $lens, string $tail): string
    {
        $nodeSize = intdiv(strlen($base), 4);
        $keySetSize = intdiv(strlen($begs), 4);
        $tailSize = intdiv(strlen($tail), 2);

        return (
            pack('l', $nodeSize) . pack('l', $keySetSize) . pack('l', $tailSize) . $begs . $base . $lens . $chck . $tail
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
