<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/**
 * 常駐メモリ上の数値配列が、内部の生 PHP 配列をそのまま公開できることを表す境界。
 *
 * 解析ホットパスから get() のメソッド呼び出しを排し、直接添字参照へインライン化するために使う。
 * PHP 配列は copy-on-write のため、values() の戻り値を保持してもコピーは発生しない。
 * ファイル遅延読み（*DynamicArray）はこの契約を実装せず、従来どおり get() 経路で動作する。
 */
interface RawIntValues
{
    /**
     * 内部に保持した 0 始まり連続添字の int 値列をそのまま返す。
     *
     * @return list<int>
     */
    public function values(): array;
}
