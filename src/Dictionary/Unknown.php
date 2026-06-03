<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

/**
 * 文字カテゴリ定義に従い、通常辞書にない文字列を未知語候補として検索する。
 */
class Unknown
{
    /** 文字コードから未知語カテゴリと連結互換性を引く辞書を保持する。 */
    private CharCategory $category;

    /** SPACE として予約された文字カテゴリの辞書 ID を保持する。 */
    private int $spaceId;

    /**
     * 辞書ディレクトリから未知語カテゴリ辞書を読み込み、SPACE カテゴリ ID を確定する。
     */
    public function __construct(string $dataDir)
    {
        $this->category = new CharCategory($dataDir);
        $this->spaceId = $this->category->category(32)->id;
    }

    /**
     * 指定位置の文字カテゴリから未知語候補の長さを決め、WordDic 経由で候補ノードを通知する。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDic $wordDic, WordDicCallback $fn): void
    {
        $firstCode = $text[$start];
        $category = $this->category->category($firstCode);

        if (!$fn->isEmpty() && !$category->invoke) {
            return;
        }

        $isSpace = $category->id === $this->spaceId;
        $textLength = count($text);
        $limit = min($textLength, $category->length + $start);
        $position = $start;

        for (; $position < $limit; $position++) {
            $wordDic->searchFromTrieId($category->id, $start, $position - $start + 1, $isSpace, $fn);

            if (($position + 1) !== $limit && !$this->category->isCompatible($firstCode, $text[$position + 1])) {
                return;
            }
        }

        if ($category->group && $position < $textLength) {
            $this->searchGroupedCandidate($text, $start, $position, $category->id, $isSpace, $wordDic, $fn);
        }
    }

    /**
     * group 指定カテゴリでは互換性が途切れる直前まで伸ばした 1 つの追加候補を通知する。
     *
     * @param list<int> $text
     */
    private function searchGroupedCandidate(
        array $text,
        int $start,
        int $position,
        int $categoryId,
        bool $isSpace,
        WordDic $wordDic,
        WordDicCallback $fn,
    ): void {
        $firstCode = $text[$start];
        $textLength = count($text);

        for (; $position < $textLength; $position++) {
            if ($this->category->isCompatible($firstCode, $text[$position])) {
                continue;
            }

            $wordDic->searchFromTrieId($categoryId, $start, $position - $start, $isSpace, $fn);

            return;
        }

        $wordDic->searchFromTrieId($categoryId, $start, $textLength - $start, $isSpace, $fn);
    }
}
