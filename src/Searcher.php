<?php

declare(strict_types=1);

namespace IgoModern;

use RuntimeException;

/**
 * double-array trie 辞書から入力キーに一致する共通接頭辞を探索する。
 */
class Searcher
{
    /** 辞書に登録されているキー数を保持する。 */
    private int $keySetSize;

    /** double-array trie の base 配列を添字指定で読む。 */
    private IntArray $base;

    /** double-array trie の check 配列を添字指定で読む。 */
    private CharArray $chck;

    /** tail 配列内で各語の suffix が始まる位置を読む。 */
    private IntArray $begs;

    /** tail 配列に格納された各語の suffix 長を読む。 */
    private ShortArray $lens;

    /** @var list<int> tail 圧縮された suffix の文字コード列を保持する。 */
    private array $tail;

    /**
     * 辞書バイナリから double-array trie と tail 情報を復元する。
     */
    public function __construct(string $filePath)
    {
        $stream = new FileMappedInputStream($filePath);

        try {
            $nodeSize = $stream->getInt();
            $tailIndexSize = $stream->getInt();
            $tailSize = $stream->getInt();

            $this->keySetSize = $tailIndexSize;
            $this->begs = $stream->getIntArrayInstance($tailIndexSize);
            $this->base = $stream->getIntArrayInstance($nodeSize);
            $this->lens = $stream->getShortArrayInstance($tailIndexSize);
            $this->chck = $stream->getCharArrayInstance($nodeSize);
            $this->tail = $this->unpackTail($stream->getString($tailSize));
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

    /**
     * UTF-16 相当のバイト列から tail 用の unsigned short 文字コード列を復元する。
     *
     * @return list<int>
     */
    private function unpackTail(string $binary): array
    {
        if ($binary === '') {
            return [];
        }

        $values = unpack('S*', $binary);

        if ($values === false) {
            throw new RuntimeException('dictionary unpacking failed.');
        }

        return array_values(array_map(static fn(int $value): int => $value, $values));
    }
}
