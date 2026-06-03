<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use RuntimeException;

/**
 * 辞書バイナリを現在位置から順に読み、配列実装の入力元にもなるストリーム。
 */
class FileMappedInputStream implements IntArrayReader, ShortArrayReader, CharArrayReader
{
    /** @var resource バイナリ辞書を順次読むためのファイルハンドルを保持する。 */
    private $file;

    /** 配列インスタンスに渡す開始位置を追跡するため、数値読み取りのカーソルを保持する。 */
    private int $cur = 0;

    /**
     * 読み取り対象ファイルを開き、配列インスタンスの読み取り方式を保持する。
     */
    public function __construct(
        private string $fileName,
        private bool $reduce = true,
    ) {
        $file = fopen($this->fileName, 'rb');

        if ($file === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $this->file = $file;
    }

    /**
     * 現在位置から 4 バイト signed int を 1 件読み込む。
     */
    public function getInt(): int
    {
        $this->cur += 4;

        return $this->readUnpackedValue(4, 'l');
    }

    /**
     * 現在位置から指定件数の signed int を読み込む。
     *
     * @return list<int>
     */
    public function getIntArray(int $count): array
    {
        $this->cur += $count * 4;

        return $this->readUnpackedValues($count, 4, 'l');
    }

    /**
     * 設定された読み取り方式に応じて int 配列の実装を作る。
     */
    public function getIntArrayInstance(int $count): IntArray
    {
        if ($this->reduce) {
            $array = new IntDynamicArray($this->fileName, $this->cur);
            $this->skipBytes($count * 4);

            return $array;
        }

        return new IntMemoryArray($this, $count);
    }

    /**
     * ファイル全体を signed int 配列として読み込む。
     *
     * @return list<int>
     */
    public static function getIntArrayFromFile(string $fileName): array
    {
        $stream = new self($fileName);

        try {
            return $stream->getIntArray(intdiv($stream->size(), 4));
        } finally {
            $stream->close();
        }
    }

    /**
     * 旧実装の static helper 名で、ファイル全体を signed int 配列として読み込む。
     *
     * @return list<int>
     */
    public static function _getIntArray(string $fileName): array
    {
        return self::getIntArrayFromFile($fileName);
    }

    /**
     * 現在位置から指定件数の signed short を読み込む。
     *
     * @return list<int>
     */
    public function getShortArray(int $count): array
    {
        $this->cur += $count * 2;

        return $this->readUnpackedValues($count, 2, 's');
    }

    /**
     * 設定された読み取り方式に応じて signed short 配列の実装を作る。
     */
    public function getShortArrayInstance(int $count): ShortArray
    {
        if ($this->reduce) {
            $array = new ShortDynamicArray($this->fileName, $this->cur);
            $this->skipBytes($count * 2);

            return $array;
        }

        return new ShortMemoryArray($this, $count);
    }

    /**
     * 設定された読み取り方式に応じて unsigned short 文字コード配列の実装を作る。
     */
    public function getCharArrayInstance(int $count): CharArray
    {
        if ($this->reduce) {
            $array = new CharDynamicArray($this->fileName, $this->cur);
            $this->skipBytes($count * 2);

            return $array;
        }

        return new CharMemoryArray($this, $count);
    }

    /**
     * 現在位置から指定件数の unsigned short を読み込む。
     *
     * @return list<int>
     */
    public function getCharArray(int $count): array
    {
        $this->cur += $count * 2;

        return $this->readUnpackedValues($count, 2, 'S');
    }

    /**
     * UTF-16 相当の文字数として指定された件数を 2 バイト単位で読み込む。
     */
    public function getString(int $count): string
    {
        return $this->readBytes($count * 2);
    }

    /**
     * ファイル全体を UTF-16 相当の文字列バイト列として読み込む。
     */
    public static function getStringFromFile(string $fileName): string
    {
        $stream = new self($fileName);

        try {
            return $stream->getString(intdiv($stream->size(), 2));
        } finally {
            $stream->close();
        }
    }

    /**
     * 旧実装の static helper 名で、ファイル全体を UTF-16 相当の文字列バイト列として読み込む。
     */
    public static function _getString(string $fileName): string
    {
        return self::getStringFromFile($fileName);
    }

    /**
     * 読み取り対象ファイルのバイトサイズを返す。
     */
    public function size(): int
    {
        $size = filesize($this->fileName);

        if ($size === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $size;
    }

    /**
     * 開いているファイルハンドルを閉じ、読み取りリソースを解放する。
     */
    public function close(): bool
    {
        if (!is_resource($this->file)) {
            return true;
        }

        return fclose($this->file);
    }

    /**
     * dynamic 配列用に、数値カーソルとファイルポインタを同じバイト数だけ進める。
     */
    private function skipBytes(int $byteCount): void
    {
        $this->cur += $byteCount;
        $seekResult = fseek($this->file, $this->cur);

        if ($seekResult !== 0) {
            throw new RuntimeException('dictionary seeking failed.');
        }
    }

    /**
     * 現在位置から固定幅のバイト列を読み、unpack 形式で 1 件の値に変換する。
     */
    private function readUnpackedValue(int $byteWidth, string $unpackFormat): int
    {
        $data = unpack($unpackFormat, $this->readBytes($byteWidth));

        if ($data === false) {
            throw new RuntimeException('dictionary unpacking failed.');
        }

        return (int) $data[1];
    }

    /**
     * 現在位置から固定幅の値を指定件数だけ読み、list<int> として返す。
     *
     * @return list<int>
     */
    private function readUnpackedValues(int $count, int $byteWidth, string $unpackFormat): array
    {
        if ($count === 0) {
            return [];
        }

        $data = unpack($unpackFormat . '*', $this->readBytes($count * $byteWidth));

        if ($data === false) {
            throw new RuntimeException('dictionary unpacking failed.');
        }

        return array_values(array_map(static fn(int $value): int => $value, $data));
    }

    /**
     * ファイルポインタから指定バイト数を読み、不足があれば辞書読み込み失敗として扱う。
     */
    private function readBytes(int $byteCount): string
    {
        if ($byteCount < 0) {
            throw new RuntimeException('dictionary reading failed.');
        }

        if ($byteCount === 0) {
            return '';
        }

        $bytes = fread($this->file, $byteCount);

        if ($bytes === false || strlen($bytes) !== $byteCount) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $bytes;
    }
}
