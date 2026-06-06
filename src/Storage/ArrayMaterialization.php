<?php

declare(strict_types=1);

namespace IgoModern\Storage;

/**
 * 辞書バイナリ配列を「ファイル遅延読み」と「メモリ常駐」のどちらで実体化するかを表す方式。
 *
 * 旧来 magic bool だった $reduce を型へ置き換え、各層を貫通する意味を明示する。
 *
 * PHP 8.0 を要求対象に含むため言語の enum は使えないが、将来 8.1+ の純粋 enum へ
 * 機械的に移行できるよう、enum と同形の API（ケースごとの静的アクセサと `===` 同一性比較）に揃える。
 * 移行時は「`final class` を `enum` にし、各アクセサ `Lazy()/Resident()` を case 宣言へ置換、
 * 呼び出し側の `::Lazy()` から `()` を外す」だけで済む。
 */
final class ArrayMaterialization
{
    /** ファイル遅延読み方式の共有インスタンスを一度だけ生成して保持する（enum の単一 case 相当）。 */
    private static ?self $lazyCase = null;

    /** メモリ常駐方式の共有インスタンスを一度だけ生成して保持する（enum の単一 case 相当）。 */
    private static ?self $residentCase = null;

    /**
     * 外部からの直接生成を禁じ、ケースを共有インスタンスに限定する。
     */
    private function __construct() {}

    /**
     * ファイルを必要時に読む DynamicArray として実体化する方式（旧 reduce=true、常駐メモリを抑える）。
     *
     * enum 移行後は `case Lazy;` に置き換わり、呼び出しは `ArrayMaterialization::Lazy` になる。
     */
    public static function Lazy(): self
    {
        return self::$lazyCase ??= new self();
    }

    /**
     * 全要素をメモリへ読み込んだ MemoryArray として実体化する方式（旧 reduce=false、ファイルアクセスを避ける）。
     *
     * enum 移行後は `case Resident;` に置き換わり、呼び出しは `ArrayMaterialization::Resident` になる。
     */
    public static function Resident(): self
    {
        return self::$residentCase ??= new self();
    }
}
