<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use IgoModern\Dictionary\Binary\BinaryUnknownWordDictionary;
use IgoModern\Dictionary\Binary\BinaryWordDictionary;
use IgoModern\Dictionary\Contract\ConnectionMatrix;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\Contract\WordDictionary;

/**
 * runtime 辞書一式を loader 契約から受け取って保持する storage の共通基底。
 *
 * 3 種類の辞書の保持と getter を共有し、辞書ディレクトリ構造・ファイル名・実体化方式の知識は loader へ委ねる。
 * File/Memory の違いは、各サブクラスが渡す loader（実体化方式を内包）だけに閉じる。
 */
abstract class BinaryStorage implements DictionaryStorage
{
    /**
     * 構築済みの 3 種類の辞書を保持する。
     */
    final protected function __construct(
        private BinaryWordDictionary $wordDictionary,
        private BinaryUnknownWordDictionary $unknownWordDictionary,
        private BinaryConnectionMatrix $connectionMatrix,
    ) {}

    /**
     * 既知語を解決する単語辞書を返す。
     */
    public function wordDictionary(): WordDictionary
    {
        return $this->wordDictionary;
    }

    /**
     * 未知語候補を生成する未知語辞書を返す。
     */
    public function unknownWordDictionary(): UnknownWordDictionary
    {
        return $this->unknownWordDictionary;
    }

    /**
     * 形態素間の連接コストを提供する連接コスト行列を返す。
     */
    public function connectionMatrix(): ConnectionMatrix
    {
        return $this->connectionMatrix;
    }

    /**
     * loader 契約から 3 種類のバイナリ辞書を受け取って storage を構築する。
     *
     * 辞書ディレクトリ・ファイル名・実体化方式の知識は loader へ閉じ、storage はそれらを知らない。
     * 未知語辞書は wordId 解決の不変条件を満たすため、同一の単語辞書を共有させる。
     */
    final protected static function loadTrio(BinaryDictionaryLoader $loader): static
    {
        $word = $loader->loadWordDictionary();

        return new static($word, $loader->loadUnknownWordDictionary($word), $loader->loadConnectionMatrix());
    }
}
