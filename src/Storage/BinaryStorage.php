<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Binary\ArrayMaterialization;
use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use IgoModern\Dictionary\Binary\BinaryUnknownWordDictionary;
use IgoModern\Dictionary\Binary\BinaryWordDictionary;
use IgoModern\Dictionary\Contract\ConnectionMatrix;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\Contract\WordDictionary;

/**
 * バイナリ辞書フォーマットを共有リーダで読み込む storage の共通基底。
 *
 * 3 実装の構築と getter を共有し、File/Memory の違いは配列の実体化方式（ArrayMaterialization）だけに閉じる。
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
     * 辞書ディレクトリから 3 種類のバイナリ辞書を、指定の実体化方式で構築する。
     *
     * 未知語辞書は wordId 解決の不変条件を満たすため、同一の単語辞書を共有させる。
     */
    final protected static function loadTrio(string $dir, ArrayMaterialization $materialization): static
    {
        // ファイル reader の生成点は storage に閉じ、各辞書へ materialization と並走で注入する。
        $byteReaderFactory = new PagedByteReaderFactory();
        $word = BinaryWordDictionary::fromDataDir($dir, $materialization, $byteReaderFactory);

        return new static(
            $word,
            BinaryUnknownWordDictionary::fromDataDir($dir, $word, $materialization, $byteReaderFactory),
            BinaryConnectionMatrix::fromDataDir($dir, $materialization, $byteReaderFactory),
        );
    }
}
