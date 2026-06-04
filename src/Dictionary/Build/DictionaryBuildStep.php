<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

/**
 * 辞書生成の各段階を同じ引数契約で DictionaryBuilder から呼び出せるようにする。
 */
interface DictionaryBuildStep
{
    /**
     * 指定された入力辞書から出力ディレクトリへ担当ファイル群を生成する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void;
}
