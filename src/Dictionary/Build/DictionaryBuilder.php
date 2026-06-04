<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

/**
 * Igo 形式辞書の生成順序を制御し、個別 builder へ同じ入力条件を伝播する。
 */
class DictionaryBuilder
{
    /**
     * 辞書生成の依存ステップを保持し、コンストラクタでは I/O を発生させない。
     */
    public function __construct(
        private DictionaryBuildStep $wordDictionaryBuilder,
        private DictionaryBuildStep $matrixBuilder,
        private DictionaryBuildStep $charCategoryBuilder,
    ) {}

    /**
     * CLI から利用する標準の辞書生成構成を組み立てる。
     */
    public static function standard(): self
    {
        return new self(new WordDictionaryBuilder(), new MatrixBuilder(), new CharCategoryBuilder());
    }

    /**
     * Java 版 BuildDic と同じ生成順序で、後段が前段の成果物を参照できるようにする。
     */
    public function build(
        string $outputDirectory,
        string $inputDirectory,
        string $encoding,
        string $delimiter = ',',
    ): void {
        $this->wordDictionaryBuilder->build($outputDirectory, $inputDirectory, $encoding, $delimiter);
        $this->matrixBuilder->build($outputDirectory, $inputDirectory, $encoding, $delimiter);
        $this->charCategoryBuilder->build($outputDirectory, $inputDirectory, $encoding, $delimiter);
    }
}
