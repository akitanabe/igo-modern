<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Trie;

use IgoModern\Binary\Contract\CharArray;
use IgoModern\Binary\Contract\InputStreamFactory;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\ShortArray;

/**
 * double-array trie 辞書から入力キーに一致する共通接頭辞を探索する。
 */
class Searcher
{
    /**
     * 事前に読み込まれた double-array trie と tail 情報を保持する。
     */
    public function __construct(
        private int $keySetSize,
        private IntArray $begs,
        private IntArray $base,
        private ShortArray $lens,
        private CharArray $chck,
        private CharArray $tail,
    ) {}

    /**
     * 辞書バイナリから double-array trie と tail 情報を復元する。
     *
     * 公開構築点は Storage 実装のみ。$streams は実体化方式を内包した stream ファクトリ（Storage が提供）で、
     * 「ファイルを開いて順次読み、実体化方式に応じた配列を返す」プリミティブだけを契約として受け取る。
     */
    public static function fromFile(string $filePath, InputStreamFactory $streams): self
    {
        $stream = $streams->open($filePath);

        try {
            $nodeSize = $stream->getInt();
            $tailIndexSize = $stream->getInt();
            $tailSize = $stream->getInt();

            return new self(
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

    /**
     * 辞書に登録されているキー数を返す。
     */
    public function size(): int
    {
        return $this->keySetSize;
    }

    /**
     * double-array trie 内の負数表現から語 ID を復元する。
     */
    public static function ID(int $id): int
    {
        return ($id * -1) - 1;
    }

    /**
     * 指定開始位置から一致する辞書キーを短い順にコールバックへ通知する。
     *
     * @param list<int> $key
     */
    public function eachCommonPrefix(array $key, int $start, CommonPrefixCallback $fn): void
    {
        $node = $this->base->get(0);
        $offset = 0;
        $input = new KeyStream($key, $start);

        for ($code = $input->read();; $code = $input->read(), $offset++) {
            $terminalIndex = $node;

            if ($this->chck->get($terminalIndex) === 0) {
                $fn->call($start, $offset, self::ID($this->base->get($terminalIndex)));

                if ($code === 0) {
                    return;
                }
            }

            $index = $node + $code;
            $node = $this->base->get($index);

            if ($this->chck->get($index) !== $code) {
                return;
            }

            if ($node >= 0) {
                continue;
            }

            $this->callIfKeyIncluding($input, $node, $start, $offset, $fn);

            return;
        }
    }

    /**
     * tail 圧縮された suffix が入力の続きと一致する場合だけコールバックへ通知する。
     */
    private function callIfKeyIncluding(
        KeyStream $input,
        int $node,
        int $start,
        int $offset,
        CommonPrefixCallback $fn,
    ): void {
        $id = self::ID($node);
        $length = $this->lens->get($id);

        if ($input->startsWith($this->tail, $this->begs->get($id), $length)) {
            $fn->call($start, $offset + $length + 1, $id);
        }
    }
}
