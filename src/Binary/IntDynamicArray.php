<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\ByteReader;
use IgoModern\Binary\Contract\IntArray;
use RuntimeException;

/**
 * int 値の配列を必要な添字だけファイルから読み込んで返す。
 */
class IntDynamicArray implements IntArray
{
    /**
     * バイナリ辞書内の配列開始位置とページ reader を保持する。
     */
    public function __construct(
        protected ByteReader $reader,
        protected int $start,
    ) {}

    /**
     * 読み取り対象ファイルを開き、指定 offset から読む dynamic 配列を作る。
     */
    public static function fromFile(string $fileName, int $start): self
    {
        return new self(PagedBinaryReader::fromFile($fileName), $start);
    }

    /**
     * 指定添字に対応する 4 バイト signed int をファイルから読む。
     */
    public function get(int $idx): int
    {
        return $this->readValue($idx, 4, 'l');
    }

    /**
     * 指定幅と unpack 形式で、配列開始位置から目的の値だけを読み込む。
     *
     * @param positive-int $byteWidth
     */
    protected function readValue(int $idx, int $byteWidth, string $unpackFormat): int
    {
        $bytes = $this->reader->readBytes($this->start + ($idx * $byteWidth), $byteWidth);
        $data = unpack($unpackFormat, $bytes);

        if ($data === false) {
            throw new RuntimeException('dictionary unpacking failed.');
        }

        return (int) $data[1];
    }
}
