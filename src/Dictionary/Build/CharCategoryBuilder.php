<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use RuntimeException;

/**
 * char.def から runtime CharCategory が読める文字カテゴリ辞書を生成する。
 */
class CharCategoryBuilder implements DictionaryBuildStep
{
    /**
     * 文字カテゴリ生成は後続プロセスで実装するため、現時点では未実装状態を明示する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        throw new RuntimeException('char category build is not implemented yet.');
    }
}
