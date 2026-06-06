<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/**
 * 辞書ファイルを現在位置から順に読み、実体化方式に応じた配列を返す順次読み取り契約。
 *
 * consumer（辞書クラス）が実際に呼ぶメソッドのみを宣言し、ファイルシステムや実体化方式の知識は実装側へ閉じる。
 */
interface InputStream
{
    /** 現在位置から 4 バイト signed int を 1 件読み込む。 */
    public function getInt(): int;

    /**
     * 現在位置から指定件数の signed int を読み込む。
     *
     * @return list<int>
     */
    public function getIntArray(int $count): array;

    /** 設定された実体化方式に応じて int 配列の実装を作る。 */
    public function getIntArrayInstance(int $count): IntArray;

    /** 設定された実体化方式に応じて signed short 配列の実装を作る。 */
    public function getShortArrayInstance(int $count): ShortArray;

    /** 設定された実体化方式に応じて unsigned short 文字コード配列の実装を作る。 */
    public function getCharArrayInstance(int $count): CharArray;

    /** 読み取り対象ファイルのバイトサイズを返す。 */
    public function size(): int;

    /** 開いている読み取りリソースを解放する。 */
    public function close(): bool;
}
