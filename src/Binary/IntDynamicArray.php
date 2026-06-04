<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\IntArray;
use RuntimeException;

/**
 * int 値の配列を必要な添字だけファイルから読み込んで返す。
 */
class IntDynamicArray implements IntArray
{
    /** バイナリ辞書をページ単位で読み、近接アクセスの再読み込みを減らす。 */
    protected PagedBinaryReader $reader;

    /**
     * バイナリ辞書内の配列開始位置を保持し、読み取り用ファイルを開く。
     */
    public function __construct(
        protected string $fileName,
        protected int $start,
    ) {
        $this->reader = new PagedBinaryReader($this->fileName);
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
