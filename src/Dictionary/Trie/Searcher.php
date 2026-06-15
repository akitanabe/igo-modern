<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Trie;

use IgoModern\Binary\Contract\CharArray;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\RawIntValues;
use IgoModern\Binary\Contract\ShortArray;

/**
 * double-array trie 辞書から入力キーに一致する共通接頭辞を探索する純粋クラス。
 *
 * ファイル形式の知識は持たない。trie ファイルからの復元は FileTrieLoader が担う。
 * 常駐メモリ（RawIntValues）時は base/begs/lens/chck/tail の生配列を保持し、
 * 最内ループの get() メソッド呼び出しを排して直接添字参照する fast 経路へ分岐する。
 */
class Searcher
{
    /**
     * fast 経路で使う base の生配列。常駐メモリで全配列が揃ったときのみ非 null。
     *
     * @var list<int>|null
     */
    private ?array $rawBase;

    /**
     * fast 経路で使う chck の生配列。fast 採用時は base/begs/lens/chck/tail が揃って非 null。
     *
     * @var list<int>|null
     */
    private ?array $rawChck;

    /**
     * fast 経路で使う begs の生配列。
     *
     * @var list<int>|null
     */
    private ?array $rawBegs;

    /**
     * fast 経路で使う lens の生配列。
     *
     * @var list<int>|null
     */
    private ?array $rawLens;

    /**
     * fast 経路で使う tail の生配列。
     *
     * @var list<int>|null
     */
    private ?array $rawTail;

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
    ) {
        // 探索ホットパスで参照する 5 配列がすべて常駐メモリのときだけ fast 経路用の生配列を取り出す。
        // 1 つでも Lazy なら fast は使えないため、すべて null のまま fallback（get() 経路）を維持する。
        $this->rawBase = null;
        $this->rawChck = null;
        $this->rawBegs = null;
        $this->rawLens = null;
        $this->rawTail = null;

        if (
            $base instanceof RawIntValues
            && $chck instanceof RawIntValues
            && $begs instanceof RawIntValues
            && $lens instanceof RawIntValues
            && $tail instanceof RawIntValues
        ) {
            $this->rawBase = $base->values();
            $this->rawChck = $chck->values();
            $this->rawBegs = $begs->values();
            $this->rawLens = $lens->values();
            $this->rawTail = $tail->values();
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
        return -$id - 1;
    }

    /**
     * 指定開始位置から一致する辞書キーを短い順にコールバックへ通知する。
     *
     * 常駐メモリなら生配列直接参照の fast 版、Lazy なら get() 経由の fallback 版へ分岐する。
     * 分岐は呼び出しごとに 1 回だけ行い、最内ループには instanceof / null 比較を持ち込まない。
     *
     * @param list<int> $key
     */
    public function eachCommonPrefix(array $key, int $start, CommonPrefixCallback $fn): void
    {
        // 5 配列は常駐メモリ時に揃って非 null になる。揃って非 null の組だけを fast 版へ渡す。
        if (
            $this->rawBase !== null
            && $this->rawChck !== null
            && $this->rawBegs !== null
            && $this->rawLens !== null
            && $this->rawTail !== null
        ) {
            $this->eachCommonPrefixFast(
                $key,
                $start,
                $fn,
                $this->rawBase,
                $this->rawChck,
                $this->rawBegs,
                $this->rawLens,
                $this->rawTail,
            );

            return;
        }

        $this->eachCommonPrefixFallback($key, $start, $fn);
    }

    /**
     * base/chck/begs/lens/tail を生配列で直接添字参照して共通接頭辞を通知する fast 版。
     *
     * fallback 版と完全に同一の通知（順序・start/offset/id）を行うことを不変条件とする。
     * 中間ストリームを生成せず、$key と整数カーソルをローカル変数で直接操作する。
     *
     * @param list<int> $key
     * @param list<int> $base
     * @param list<int> $chck
     * @param list<int> $begs
     * @param list<int> $lens
     * @param list<int> $tail
     */
    private function eachCommonPrefixFast(
        array $key,
        int $start,
        CommonPrefixCallback $fn,
        array $base,
        array $chck,
        array $begs,
        array $lens,
        array $tail,
    ): void {
        $keyLength = count($key);
        $cur = $start;

        $node = $base[0];
        $offset = 0;

        // 終端では trie 規約どおり 0 を返す。read 相当をループ内へインライン化する。
        for ($code = $cur < $keyLength ? $key[$cur++] : 0;; $code = $cur < $keyLength ? $key[$cur++] : 0, $offset++) {
            if ($chck[$node] === 0) {
                $fn->call($start, $offset, self::ID($base[$node]));

                if ($code === 0) {
                    return;
                }
            }

            $index = $node + $code;
            $node = $base[$index];

            if ($chck[$index] !== $code) {
                return;
            }

            if ($node >= 0) {
                continue;
            }

            $id = self::ID($node);
            $length = $lens[$id];

            // startsWith 相当: 残り長を確認してから tail を 1 ユニットずつ生配列添字で比較する。
            if (self::tailMatchesFast($key, $keyLength, $cur, $tail, $begs[$id], $length)) {
                $fn->call($start, $offset + $length + 1, $id);
            }

            return;
        }
    }

    /**
     * 現在カーソルから tail の指定範囲が続いているかを生配列添字参照で判定する。
     *
     * 旧 startsWith と同一の結果（残り長不足なら false、全ユニット一致で true）を返す。
     *
     * @param list<int> $key
     * @param list<int> $tail
     */
    private static function tailMatchesFast(
        array $key,
        int $keyLength,
        int $cur,
        array $tail,
        int $beg,
        int $length,
    ): bool {
        if (($keyLength - $cur) < $length) {
            return false;
        }

        for ($i = 0; $i < $length; $i++) {
            if ($key[$cur + $i] !== $tail[$beg + $i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * base/chck/begs/lens/tail を get() 経由で参照する fallback 版（FileStorage / Lazy 経路）。
     *
     * 中間ストリームを生成せず、$key と整数カーソルをローカル変数で直接操作する点は fast 版と同じ。
     *
     * @param list<int> $key
     */
    private function eachCommonPrefixFallback(array $key, int $start, CommonPrefixCallback $fn): void
    {
        $keyLength = count($key);
        $cur = $start;

        $node = $this->base->get(0);
        $offset = 0;

        // 終端では trie 規約どおり 0 を返す。read 相当をループ内へインライン化する。
        for ($code = $cur < $keyLength ? $key[$cur++] : 0;; $code = $cur < $keyLength ? $key[$cur++] : 0, $offset++) {
            if ($this->chck->get($node) === 0) {
                $fn->call($start, $offset, self::ID($this->base->get($node)));

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

            $id = self::ID($node);
            $length = $this->lens->get($id);

            // startsWith 相当: 残り長を確認してから tail を 1 ユニットずつ get() で比較する。
            if ($this->tailMatchesFallback($key, $keyLength, $cur, $this->begs->get($id), $length)) {
                $fn->call($start, $offset + $length + 1, $id);
            }

            return;
        }
    }

    /**
     * 現在カーソルから tail の指定範囲が続いているかを tail->get() で判定する fallback 版。
     *
     * @param list<int> $key
     */
    private function tailMatchesFallback(array $key, int $keyLength, int $cur, int $beg, int $length): bool
    {
        if (($keyLength - $cur) < $length) {
            return false;
        }

        for ($i = 0; $i < $length; $i++) {
            if ($key[$cur + $i] !== $this->tail->get($beg + $i)) {
                return false;
            }
        }

        return true;
    }
}
