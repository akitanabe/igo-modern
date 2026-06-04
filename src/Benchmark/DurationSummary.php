<?php

declare(strict_types=1);

namespace IgoModern\Benchmark;

/**
 * ベンチマーク試行時間の分布を比較しやすい統計値として保持する。
 */
class DurationSummary
{
    /**
     * 経過時間の代表値を保持し、出力側が計算方法に依存しないようにする。
     */
    public function __construct(
        public float $min,
        public float $max,
        public float $mean,
        public float $median,
        public float $p95,
    ) {}

    /**
     * 個別試行の経過時間から、平均・中央値・p95 などの代表値を計算する。
     *
     * @param non-empty-list<float> $durations
     */
    public static function fromDurations(array $durations): self
    {
        $sorted = $durations;
        sort($sorted);
        $count = count($sorted);

        return new self(
            $sorted[0],
            $sorted[$count - 1],
            array_sum($sorted) / $count,
            self::percentile($sorted, 0.5),
            self::percentile($sorted, 0.95),
        );
    }

    /**
     * ソート済みの測定値から nearest-rank 方式で百分位値を取り出す。
     *
     * @param non-empty-list<float> $sortedValues
     */
    private static function percentile(array $sortedValues, float $percentile): float
    {
        $index = (int) ceil(count($sortedValues) * $percentile) - 1;

        return $sortedValues[max(0, min($index, count($sortedValues) - 1))];
    }
}
