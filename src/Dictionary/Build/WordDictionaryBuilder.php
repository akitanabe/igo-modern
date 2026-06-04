<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use RuntimeException;

/**
 * MeCab 互換の単語定義から word2id、word.inf、word.dat、word.ary.idx を生成する。
 */
class WordDictionaryBuilder implements DictionaryBuildStep
{
    /**
     * 単語辞書生成は後続プロセスで実装するため、現時点では未実装状態を明示する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        throw new RuntimeException('word dictionary build is not implemented yet.');
    }
}
