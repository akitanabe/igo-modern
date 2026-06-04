<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use RuntimeException;

/**
 * matrix.def から runtime Matrix が読める matrix.bin を生成する。
 */
class MatrixBuilder implements DictionaryBuildStep
{
    /**
     * 連接コスト生成は後続プロセスで実装するため、現時点では未実装状態を明示する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        throw new RuntimeException('matrix build is not implemented yet.');
    }
}
