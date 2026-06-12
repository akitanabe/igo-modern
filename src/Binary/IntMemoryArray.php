<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\IntArrayReader;
use RuntimeException;

/**
 * int 値の配列を reader から一括で読み込み、メモリ上から返す。
 *
 * 内部表現は 0 始まり連続添字の通常 PHP 配列（packed list）とする。
 * SplFixedArray の ArrayAccess 経由アクセスより直接添字参照が高速なため、解析ホットパスの最適化を目的に変更した。
 */
class IntMemoryArray implements IntArray
{
    /** @var list<int> 添字指定で返す固定件数の int 値を 0 始まり連続添字の配列で保持する。 */
    protected array $array;

    /**
     * 読み込み済みの固定長 int 値列を、添字参照用に保持する。
     *
     * @param list<int> $array 0 始まり連続添字の int 値列。unpack 由来で要素は必ず int であることが前提。
     */
    public function __construct(array $array)
    {
        $this->array = $array;
    }

    /**
     * reader から必要件数の int 値を読み込み、メモリ配列を作る。
     */
    public static function fromReader(object $reader, int $count): self
    {
        if (!$reader instanceof IntArrayReader) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return new self($reader->getIntArray($count));
    }

    /**
     * メモリ上に保持した int 値を指定添字で返す。
     *
     * unpack 由来の配列は要素が int であることが保証されているため is_int 検証は省略する。
     * 存在しない添字の場合は RuntimeException を投げ、呼び出し元に範囲外アクセスを知らせる。
     */
    public function get(int $idx): int
    {
        // 要素は unpack 由来の int のみで null は存在しないため、?? の 1 回参照で範囲外を判定できる。
        $value = $this->array[$idx] ?? null;

        if ($value === null) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $value;
    }
}
