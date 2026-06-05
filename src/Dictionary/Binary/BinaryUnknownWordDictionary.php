<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Binary;

use IgoModern\Dictionary\CharCategory;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\WordDicCallback;

/**
 * 文字カテゴリ定義に従い、通常辞書にない文字列を未知語候補として検索する。
 */
class BinaryUnknownWordDictionary implements UnknownWordDictionary
{
    /** SPACE として予約された文字カテゴリの辞書 ID を保持する。 */
    private int $spaceId;

    /**
     * 文字カテゴリ辞書と、候補生成に使う姉妹の単語辞書を保持し、SPACE カテゴリ ID を確定する。
     *
     * 未知語候補の wordId が同一 storage で解決可能であるよう、具象 BinaryWordDictionary を保持する。
     */
    public function __construct(
        private CharCategory $category,
        private BinaryWordDictionary $wordDic,
    ) {
        $this->spaceId = $this->category->category(32)->id;
    }

    /**
     * 辞書ディレクトリから未知語カテゴリ辞書を読み込み、姉妹の単語辞書とともに保持する。
     *
     * $reduce は配列の実体化方式（true=遅延読み / false=常駐）を選ぶ内部限定の引数。
     */
    public static function fromDataDir(string $dataDir, BinaryWordDictionary $wordDic, bool $reduce = true): self
    {
        return new self(CharCategory::fromDataDir($dataDir, $reduce), $wordDic);
    }

    /**
     * 指定位置の文字カテゴリから未知語候補の長さを決め、単語辞書の範囲展開で候補ノードを通知する。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void
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
            $this->wordDic->callWordRange($category->id, $start, $position - $start + 1, $isSpace, $fn);

            if (($position + 1) !== $limit && !$this->category->isCompatible($firstCode, $text[$position + 1])) {
                return;
            }
        }

        if ($category->group && $position < $textLength) {
            $this->searchGroupedCandidate($text, $start, $position, $category->id, $isSpace, $fn);
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
        WordDicCallback $fn,
    ): void {
        $firstCode = $text[$start];
        $textLength = count($text);

        for (; $position < $textLength; $position++) {
            if ($this->category->isCompatible($firstCode, $text[$position])) {
                continue;
            }

            $this->wordDic->callWordRange($categoryId, $start, $position - $start, $isSpace, $fn);

            return;
        }

        $this->wordDic->callWordRange($categoryId, $start, $textLength - $start, $isSpace, $fn);
    }
}
