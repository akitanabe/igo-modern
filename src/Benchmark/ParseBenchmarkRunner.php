<?php

declare(strict_types=1);

namespace IgoModern\Benchmark;

use IgoModern\Igo;
use IgoModern\Morpheme;
use IgoModern\Parser;
use IgoModern\Storage\FileStorage;
use IgoModern\Storage\MemoryStorage;
use InvalidArgumentException;

/**
 * Igo の parse 処理を指定回数実行し、計測結果を収集する。
 */
class ParseBenchmarkRunner
{
    /** @var callable(string, string, ?string, ?int): Parser 辞書ディレクトリ、storage 種別、入力エンコーディング、ページキャッシュ上限から解析器を作るファクトリを保持する。 */
    private $parserFactory;

    /**
     * 解析器生成を必須依存として受け取り、CLI とテストで同じ測定処理を使えるようにする。
     *
     * @param callable(string, string, ?string, ?int): Parser $parserFactory
     */
    public function __construct(callable $parserFactory)
    {
        $this->parserFactory = $parserFactory;
    }

    /**
     * 通常利用向けに UTF-8 出力の Igo parser を生成する標準 runner を組み立てる。
     *
     * inputEncoding の既定値は null（検出あり）、maxCachedPages の既定値は null（PagedBinaryReader 既定値使用）とし、従来動作を維持する。
     */
    public static function createDefault(): self
    {
        return new self(static fn(
            string $dictionary,
            string $storage,
            ?string $inputEncoding,
            ?int $maxCachedPages,
        ): Parser => Igo::fromStorage(
            match ($storage) {
                'file' => FileStorage::fromDataDir($dictionary, $maxCachedPages),
                'memory' => MemoryStorage::fromDataDir($dictionary),
                default => throw new InvalidArgumentException('storage must be file or memory.'),
            },
            'UTF-8',
            $inputEncoding,
        ));
    }

    /**
     * 設定された入力を読み込み、ウォームアップ後に parse の経過時間分布を測定する。
     */
    public function run(ParseBenchmarkConfig $config): ParseBenchmarkResult
    {
        $this->validateConfig($config);

        $text = $this->benchmarkText($config);

        // 辞書ロードによる常駐メモリ増分を、ロード前後の使用量差として測り、辞書コストを独立指標にする。
        $beforeLoad = memory_get_usage();
        $parser = ($this->parserFactory)(
            $config->dictionary,
            $config->storage,
            $config->inputEncoding,
            $config->maxCachedPages,
        );
        $dictionaryResidentBytes = max(0, memory_get_usage() - $beforeLoad);

        $this->warmUpParser($parser, $text, $config->warmup);
        $measurement = $this->measureParser($parser, $text, $config->iterations);

        return new ParseBenchmarkResult(
            $config,
            $text,
            mb_strlen($text, 'UTF-8'),
            strlen($text),
            $this->countLines($text),
            $measurement['morphemes'],
            DurationSummary::fromDurations($measurement['durations']),
            memory_get_peak_usage(true),
            memory_get_peak_usage(false),
            $dictionaryResidentBytes,
            $measurement['morphemeOutputLines'],
        );
    }

    /**
     * CLI を経由しない利用でも測定条件が統計計算可能な範囲にあることを保証する。
     */
    private function validateConfig(ParseBenchmarkConfig $config): void
    {
        if ($config->iterations < 1) {
            throw new InvalidArgumentException('iterations must be greater than 0.');
        }

        if ($config->warmup < 0) {
            throw new InvalidArgumentException('warmup must be a non-negative integer.');
        }

        if (!in_array($config->storage, ['file', 'memory'], true)) {
            throw new InvalidArgumentException('storage must be file or memory.');
        }

        if ($config->maxCachedPages !== null && $config->maxCachedPages < 1) {
            throw new InvalidArgumentException('maxCachedPages must be a positive integer.');
        }
    }

    /**
     * 組み込みサンプル、直接指定、外部ファイルの優先順位で解析対象文字列を決定する。
     */
    private function benchmarkText(ParseBenchmarkConfig $config): string
    {
        if ($config->text !== null && $config->file !== null) {
            throw new InvalidArgumentException('--text and --file cannot be used together.');
        }

        if ($config->text !== null) {
            return $config->text;
        }

        if ($config->file !== null) {
            return $this->readBenchmarkFile($config->file);
        }

        $samples = [
            'short' => 'すもももももももものうち',
            'news' => '東京都は生成された辞書を使って形態素解析の性能を継続的に測定します。',
            'mixed' => 'すもももももももものうち。Igo PHP 8 版で辞書生成と解析性能を確認します。東京都千代田区で2026年6月4日にベンチマークを実行します。',
        ];

        if (!array_key_exists($config->sample, $samples)) {
            throw new InvalidArgumentException(sprintf('unknown sample: %s', $config->sample));
        }

        return $samples[$config->sample];
    }

    /**
     * 外部コーパスをそのまま解析対象として読み込み、空ファイルや読み込み失敗を早期に検出する。
     */
    private function readBenchmarkFile(string $file): string
    {
        if ($file === '' || !is_file($file)) {
            throw new InvalidArgumentException(sprintf('benchmark input file not found: %s', $file));
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('failed to read benchmark input file: %s', $file));
        }

        if ($contents === '') {
            throw new InvalidArgumentException(sprintf('benchmark input file is empty: %s', $file));
        }

        return $contents;
    }

    /**
     * 初回読み込みや JIT の影響を測定対象から外すため、結果を捨てる解析を実行する。
     */
    private function warmUpParser(Parser $parser, string $text, int $warmup): void
    {
        for ($i = 0; $i < $warmup; $i++) {
            $parser->parse($text);
        }
    }

    /**
     * 指定回数だけ parse を実行して経過時間を集め、比較用の形態素出力は最後の試行結果から一度だけ生成する。
     *
     * @return array{durations:non-empty-list<float>, morphemes:int, morphemeOutputLines:list<string>}
     */
    private function measureParser(Parser $parser, string $text, int $iterations): array
    {
        // 初回試行をループ外で実行し、経過時間リストの非空性と最新形態素列を確定する。
        [$firstDuration, $morphemes] = $this->measureOnce($parser, $text);
        $durations = [$firstDuration];

        for ($i = 1; $i < $iterations; $i++) {
            [$duration, $morphemes] = $this->measureOnce($parser, $text);
            $durations[] = $duration;
        }

        // 形態素整形は比較用途のみのため、測定区間とメモリピークの外で最後の結果に対し一度だけ行う。
        return [
            'durations' => $durations,
            'morphemes' => count($morphemes),
            'morphemeOutputLines' => $this->formatMorphemes($morphemes),
        ];
    }

    /**
     * 1 回の parse 実行の経過時間と、その試行で生成された形態素列をまとめて返す。
     *
     * @return array{0:float, 1:list<Morpheme>}
     */
    private function measureOnce(Parser $parser, string $text): array
    {
        $startedAt = hrtime(true);
        $morphemes = $parser->parse($text);
        $finishedAt = hrtime(true);

        return [(float) ($finishedAt - $startedAt) / 1_000_000.0, $morphemes];
    }

    /**
     * 解析結果比較用に、parse CLI と同じ surface<TAB>feature,start 形式へ変換する。
     *
     * @param list<Morpheme> $morphemes
     * @return list<string>
     */
    private function formatMorphemes(array $morphemes): array
    {
        return array_map(
            static fn(Morpheme $morpheme): string => (
                $morpheme->surface . "\t" . $morpheme->feature . ',' . $morpheme->start
            ),
            $morphemes,
        );
    }

    /**
     * ファイル由来の入力規模を比較しやすくするため、末尾改行の有無を考慮した行数を返す。
     */
    private function countLines(string $text): int
    {
        return substr_count($text, "\n") + ($text !== '' && substr($text, -1) !== "\n" ? 1 : 0);
    }
}
