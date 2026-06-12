<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Binary;

use IgoModern\Analysis\ViterbiNode;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\RawIntValues;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Dictionary\Contract\WordDictionary;
use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Dictionary\WordDataReader;
use IgoModern\Dictionary\WordDicCallback;
use IgoModern\Dictionary\WordDicCallbackCaller;

/**
 * 単語辞書ファイル群を読み込み、表層形の一致から ViterbiNode と素性データを復元する。
 *
 * 常駐メモリ（RawIntValues）の場合は callWordRange のホットパスで使う配列を生配列として保持し、
 * 候補 1 件ごとの get() メソッド呼び出しを排して直接添字参照へインライン化する。
 */
class BinaryWordDictionary implements WordDictionary
{
    /**
     * callWordRange の fast 版で使う indices の生配列。常駐メモリ時のみ非 null。
     *
     * @var list<int>|null
     */
    private ?array $rawIndices;

    /**
     * fast 版で使う costs の生配列。fast 版採用時は indices/costs/leftIds/rightIds が揃って非 null。
     *
     * @var list<int>|null
     */
    private ?array $rawCosts;

    /**
     * fast 版で使う leftIds の生配列。
     *
     * @var list<int>|null
     */
    private ?array $rawLeftIds;

    /**
     * fast 版で使う rightIds の生配列。
     *
     * @var list<int>|null
     */
    private ?array $rawRightIds;

    /**
     * 共通接頭辞通知を単語候補通知へ変換する caller を 1 つだけ保持し、search ごとに使い回す。
     *
     * 開始位置ごとの new を避けるため、search では setCallback で $fn を差し替えるだけにする。
     */
    private WordDicCallbackCaller $callbackCaller;

    /**
     * 事前に読み込まれた単語辞書の構成要素を保持する。
     */
    public function __construct(
        private Searcher $trie,
        private WordDataReader $data,
        private IntArray $indices,
        private IntArray $dataOffsets,
        private ShortArray $leftIds,
        private ShortArray $rightIds,
        private ShortArray $costs,
    ) {
        // ホットパスの 4 配列がすべて常駐メモリのときだけ fast 版に必要な生配列を取り出す。
        // 1 つでも Lazy なら fast 版は使えないため、すべて null のまま fallback 経路を維持する。
        if (
            $indices instanceof RawIntValues
            && $costs instanceof RawIntValues
            && $leftIds instanceof RawIntValues
            && $rightIds instanceof RawIntValues
        ) {
            $this->rawIndices = $indices->values();
            $this->rawCosts = $costs->values();
            $this->rawLeftIds = $leftIds->values();
            $this->rawRightIds = $rightIds->values();
        } else {
            $this->rawIndices = null;
            $this->rawCosts = null;
            $this->rawLeftIds = null;
            $this->rawRightIds = null;
        }

        // 再利用する caller を 1 つだけ生成しておき、以後は setCallback で $fn を差し替える。
        $this->callbackCaller = new WordDicCallbackCaller($this);
    }

    /**
     * 指定位置から入力に一致する単語を trie で探し、単語候補ノードとして通知する。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void
    {
        // 開始位置ごとに caller を new せず、保持済みインスタンスへ $fn を差し替えて再利用する。
        $this->callbackCaller->setCallback($fn);
        $this->trie->eachCommonPrefix($text, $start, $this->callbackCaller);
    }

    /**
     * 指定単語 ID の素性データを UTF-16 相当のバイト列のまま返す。
     */
    public function wordData(int $wordId): string
    {
        $start = $this->dataOffsets->get($wordId);
        $end = $this->dataOffsets->get($wordId + 1);

        return $this->data->readCodeUnitSlice($start, $end);
    }

    /**
     * trie ID に対応する単語 ID 範囲を走査し、各単語の辞書属性から候補ノードを作る。
     *
     * 常駐メモリなら生配列直接参照の fast 版、Lazy なら get() 経由の fallback 版へ分岐する。
     * 分岐は呼び出しごとに 1 回だけ行い、候補ごとのループ内には instanceof / null 比較を持ち込まない。
     *
     * バイナリ辞書フォーマット内部の実装詳細であり、WordDictionary 契約には載せない。
     */
    public function callWordRange(int $trieId, int $start, int $wordLength, bool $isSpace, WordDicCallback $fn): void
    {
        // 4 配列は常駐メモリ時に揃って非 null になる。揃って非 null の組だけを fast 版へ渡す。
        if (
            $this->rawIndices !== null
            && $this->rawCosts !== null
            && $this->rawLeftIds !== null
            && $this->rawRightIds !== null
        ) {
            $this->callWordRangeFast(
                $trieId,
                $start,
                $wordLength,
                $isSpace,
                $fn,
                $this->rawIndices,
                $this->rawCosts,
                $this->rawLeftIds,
                $this->rawRightIds,
            );

            return;
        }

        $this->callWordRangeFallback($trieId, $start, $wordLength, $isSpace, $fn);
    }

    /**
     * indices/costs/leftIds/rightIds を生配列で直接添字参照して候補ノードを通知する fast 版。
     *
     * fallback 版と完全に同一の ViterbiNode 列（属性・順序）を通知することを不変条件とする。
     *
     * @param list<int> $indices
     * @param list<int> $costs
     * @param list<int> $leftIds
     * @param list<int> $rightIds
     */
    private function callWordRangeFast(
        int $trieId,
        int $start,
        int $wordLength,
        bool $isSpace,
        WordDicCallback $fn,
        array $indices,
        array $costs,
        array $leftIds,
        array $rightIds,
    ): void {
        $end = $indices[$trieId + 1];

        for ($wordId = $indices[$trieId]; $wordId < $end; $wordId++) {
            $fn->call(
                new ViterbiNode(
                    $wordId,
                    $start,
                    $wordLength,
                    $costs[$wordId],
                    $leftIds[$wordId],
                    $rightIds[$wordId],
                    $isSpace,
                ),
            );
        }
    }

    /**
     * indices/costs/leftIds/rightIds を get() 経由で参照する fallback 版（FileStorage / Lazy 経路）。
     */
    private function callWordRangeFallback(
        int $trieId,
        int $start,
        int $wordLength,
        bool $isSpace,
        WordDicCallback $fn,
    ): void {
        $end = $this->indices->get($trieId + 1);

        for ($wordId = $this->indices->get($trieId); $wordId < $end; $wordId++) {
            $fn->call(
                new ViterbiNode(
                    $wordId,
                    $start,
                    $wordLength,
                    $this->costs->get($wordId),
                    $this->leftIds->get($wordId),
                    $this->rightIds->get($wordId),
                    $isSpace,
                ),
            );
        }
    }
}
