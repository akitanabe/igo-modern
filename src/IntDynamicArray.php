<?php

declare(strict_types=1);

namespace IgoModern;

use RuntimeException;

/**
 * int 値の配列を必要な添字だけファイルから読み込んで返す。
 */
class IntDynamicArray implements IntArray
{
    /** @var resource バイナリ辞書を読むためのファイルハンドルを保持する。 */
    protected $fp;

    /**
     * バイナリ辞書内の配列開始位置を保持し、読み取り用ファイルを開く。
     */
    public function __construct(
        protected string $fileName,
        protected int $start,
    ) {
        $fp = fopen($this->fileName, 'rb');

        if ($fp === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $this->fp = $fp;
    }

    /**
     * 開いているファイルハンドルを閉じ、dynamic 読み取りのリソースを解放する。
     */
    public function __destruct()
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
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
        $seekResult = fseek($this->fp, $this->start + ($idx * $byteWidth));

        if ($seekResult !== 0) {
            throw new RuntimeException('dictionary seeking failed.');
        }

        $bytes = fread($this->fp, $byteWidth);

        if ($bytes === false || strlen($bytes) !== $byteWidth) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $data = unpack($unpackFormat, $bytes);

        if ($data === false) {
            throw new RuntimeException('dictionary unpacking failed.');
        }

        return (int) $data[1];
    }
}
