<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Dictionary\Contract\ConnectionMatrix;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\Contract\WordDictionary;

/**
 * 解析に必要な 3 種類の辞書をまとめて提供する、辞書ストレージのファサード境界。
 */
interface DictionaryStorage
{
    /**
     * 既知語を解決する単語辞書を返す。
     */
    public function wordDictionary(): WordDictionary;

    /**
     * 未知語候補を生成する未知語辞書を返す。
     */
    public function unknownWordDictionary(): UnknownWordDictionary;

    /**
     * 形態素間の連接コストを提供する連接コスト行列を返す。
     */
    public function connectionMatrix(): ConnectionMatrix;
}
