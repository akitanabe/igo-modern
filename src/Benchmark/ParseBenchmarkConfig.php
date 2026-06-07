<?php

declare(strict_types=1);

namespace IgoModern\Benchmark;

/**
 * 解析ベンチマークの入力条件を、CLI 引数から独立した値として保持する。
 */
class ParseBenchmarkConfig
{
    public const DEFAULT_ITERATIONS = 3;

    /**
     * 辞書、反復回数、入力ソースを保持し、runner が同じ条件で測定できるようにする。
     */
    public function __construct(
        public string $dictionary,
        public int $iterations = self::DEFAULT_ITERATIONS,
        public int $warmup = 0,
        public string $sample = 'mixed',
        public ?string $text = null,
        public ?string $file = null,
        public string $storage = 'file',
    ) {}

    /**
     * レポート表示で入力ソースを区別しやすい短い名前を返す。
     */
    public function sampleLabel(): string
    {
        if ($this->text !== null) {
            return 'custom';
        }

        if ($this->file !== null) {
            return 'file';
        }

        return $this->sample;
    }
}
