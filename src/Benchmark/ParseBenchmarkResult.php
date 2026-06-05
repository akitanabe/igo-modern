<?php

declare(strict_types=1);

namespace IgoModern\Benchmark;

/**
 * 解析ベンチマークの入力情報と測定結果をまとめて保持する。
 */
class ParseBenchmarkResult
{
    /**
     * レポート出力と解析結果比較に必要な入力サイズ、形態素列、時間分布、メモリ使用量を保持する。
     *
     * peakMemoryBytes は OS から確保した割当ピーク、peakMemoryRealBytes は実使用ピーク、
     * dictionaryResidentBytes は辞書ロードで増えた常駐メモリを表し、辞書コストと一時コストを分離する。
     *
     * @param list<string> $morphemeOutputLines
     */
    public function __construct(
        public ParseBenchmarkConfig $config,
        public string $text,
        public int $characters,
        public int $bytes,
        public int $lines,
        public int $morphemes,
        public DurationSummary $duration,
        public int $peakMemoryBytes,
        public int $peakMemoryRealBytes,
        public int $dictionaryResidentBytes,
        public array $morphemeOutputLines,
    ) {}
}
