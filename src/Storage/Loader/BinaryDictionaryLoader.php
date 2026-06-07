<?php

declare(strict_types=1);

namespace IgoModern\Storage\Loader;

use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use IgoModern\Dictionary\Binary\BinaryUnknownWordDictionary;
use IgoModern\Dictionary\Binary\BinaryWordDictionary;

/**
 * runtime 解析で使う 3 種類のバイナリ辞書を構築する loader の契約。
 *
 * 辞書ディレクトリ構造・File/Memory の実体化方針・reader 生成手順を呼び出し側へ露出しない。
 * 契約の主目的は、辞書構築責務を Storage loader へ閉じることと、テスト時の factory 差し替えである。
 * 返り値は binary runtime 辞書の具象型なので、別フォーマット Storage への一般拡張点ではない。
 */
interface BinaryDictionaryLoader
{
    /** 単語辞書一式（trie・素性 reader・属性配列）を構築する。 */
    public function loadWordDictionary(): BinaryWordDictionary;

    /**
     * 未知語辞書を構築する。
     *
     * 未知語候補の wordId 解決を共有するため、構築済みの単語辞書を受け取る。
     */
    public function loadUnknownWordDictionary(BinaryWordDictionary $wordDictionary): BinaryUnknownWordDictionary;

    /** 連接コスト行列を構築する。 */
    public function loadConnectionMatrix(): BinaryConnectionMatrix;
}
