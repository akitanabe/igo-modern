<?php

declare(strict_types=1);

namespace IgoModern\Benchmark;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Igo 辞書を使った parse 処理のベンチマークを Symfony Console コマンドとして提供する。
 */
class ParseBenchmarkCommand extends Command
{
    /** コマンド名をベンチマーク用 Console アプリケーションへ提供する。 */
    protected static $defaultName = 'parse';

    /**
     * ベンチマーク実行器を必須依存として受け取り、CLI から測定処理を呼び出せるようにする。
     */
    public function __construct(
        private ParseBenchmarkRunner $runner,
    ) {
        parent::__construct();
    }

    /**
     * 通常利用向けに標準 runner を注入した parse ベンチマークコマンドを組み立てる。
     */
    public static function createDefault(): self
    {
        return new self(ParseBenchmarkRunner::createDefault());
    }

    /**
     * 辞書ディレクトリ、入力ソース、反復回数などのベンチマーク条件を定義する。
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Benchmark morphological parsing with an Igo dictionary.')
            ->addArgument('dictionary', InputArgument::REQUIRED, 'Dictionary directory.')
            ->addOption(
                'iterations',
                null,
                InputOption::VALUE_REQUIRED,
                'Measured parse iterations.',
                (string) ParseBenchmarkConfig::DEFAULT_ITERATIONS,
            )
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Warmup parse iterations.', '0')
            ->addOption(
                'sample',
                null,
                InputOption::VALUE_REQUIRED,
                'Built-in sample name: short, news, or mixed.',
                'mixed',
            )
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Inline text to benchmark.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'UTF-8 text file to benchmark.')
            ->addOption(
                'output',
                null,
                InputOption::VALUE_REQUIRED,
                'Write the benchmark report to a file. Use {datetime} for Ymd-His timestamp.',
            )
            ->addOption(
                'morpheme-output',
                null,
                InputOption::VALUE_REQUIRED,
                'Write morpheme output to a file. Use {datetime} for Ymd-His timestamp.',
            );
    }

    /**
     * CLI 入力を測定設定へ変換し、runner の結果を人間が比較しやすいテキストとして出力する。
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->configFromInput($input);

            if (!is_dir($config->dictionary)) {
                throw new InvalidArgumentException(sprintf('dictionary directory not found: %s', $config->dictionary));
            }

            $result = $this->runner->run($config);
            $report = $this->formatReport($result);
            $output->write($report);
            $this->writeReportFile($input, $report);
            $this->writeMorphemeOutputFile($input, $result);

            if ($output instanceof ConsoleOutputInterface && $this->xdebugAffectsBenchmark()) {
                $this->errorOutput($output)->writeln(
                    'Note: Xdebug is loaded; disable it for more stable benchmark numbers.',
                );
            }

            return Command::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->errorOutput($output)->writeln($exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * 利用者が指定した保存先へ、解析結果比較用の形態素列を書き込む。
     */
    private function writeMorphemeOutputFile(InputInterface $input, ParseBenchmarkResult $result): void
    {
        $outputFile = $this->stringOption($input, 'morpheme-output');

        if ($outputFile === null) {
            return;
        }

        if ($outputFile === '') {
            throw new InvalidArgumentException('--morpheme-output must be a non-empty file path.');
        }

        $contents = implode("\n", $result->morphemeOutputLines) . "\n";
        $resolvedOutputFile = $this->resolveOutputFile($outputFile);
        $writtenBytes = file_put_contents($resolvedOutputFile, $contents);

        if ($writtenBytes !== strlen($contents)) {
            throw new InvalidArgumentException(sprintf(
                'failed to write morpheme output file: %s',
                $resolvedOutputFile,
            ));
        }
    }

    /**
     * ConsoleOutputInterface では stderr を優先し、テスト用 output では同じ出力先へエラーを書けるようにする。
     */
    private function errorOutput(OutputInterface $output): OutputInterface
    {
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }

        return $output;
    }

    /**
     * Symfony Console の mixed な引数とオプションを厳密なベンチマーク設定へ変換する。
     */
    private function configFromInput(InputInterface $input): ParseBenchmarkConfig
    {
        return new ParseBenchmarkConfig(
            $this->stringArgument($input, 'dictionary'),
            $this->positiveIntegerOption($input, 'iterations'),
            $this->nonNegativeIntegerOption($input, 'warmup'),
            $this->stringOption($input, 'sample') ?? 'mixed',
            $this->stringOption($input, 'text'),
            $this->stringOption($input, 'file'),
        );
    }

    /**
     * Symfony Console の mixed な引数値を、必須文字列引数として取り出す。
     */
    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('argument "%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    /**
     * Symfony Console の mixed なオプション値を、任意文字列として取り出す。
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('option "%s" must be a string.', $name));
        }

        return $value;
    }

    /**
     * 正の整数だけを反復回数オプションとして受け入れ、不正値を早期に検出する。
     */
    private function positiveIntegerOption(InputInterface $input, string $name): int
    {
        $value = $this->nonNegativeIntegerOption($input, $name);

        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('--%s must be greater than 0.', $name));
        }

        return $value;
    }

    /**
     * 0 以上の整数だけをウォームアップ回数オプションとして受け入れる。
     */
    private function nonNegativeIntegerOption(InputInterface $input, string $name): int
    {
        $value = $this->stringOption($input, $name);

        if ($value === null || $value === '' || !ctype_digit($value)) {
            throw new InvalidArgumentException(sprintf('--%s must be a non-negative integer.', $name));
        }

        return (int) $value;
    }

    /**
     * ベンチマーク結果を、比較時にコピーしやすい固定ラベルの行へ整形する。
     */
    private function formatReport(ParseBenchmarkResult $result): string
    {
        $secondsPerIteration = $result->duration->mean / 1000;

        return (
            sprintf("Dictionary: %s\n", $result->config->dictionary)
            . sprintf(
                "Sample: %s (%d chars, %d morphemes)\n",
                $result->config->sampleLabel(),
                $result->characters,
                $result->morphemes,
            )
            . sprintf(
                "Input: %d bytes, %d lines%s\n",
                $result->bytes,
                $result->lines,
                $result->config->file === null ? '' : sprintf(', file=%s', $result->config->file),
            )
            . sprintf("Iterations: %d measured, %d warmup\n", $result->config->iterations, $result->config->warmup)
            . sprintf("Mean: %.3f ms\n", $result->duration->mean)
            . sprintf("Median: %.3f ms\n", $result->duration->median)
            . sprintf("p95: %.3f ms\n", $result->duration->p95)
            . sprintf("Min/Max: %.3f / %.3f ms\n", $result->duration->min, $result->duration->max)
            . sprintf(
                "Throughput: %.1f chars/sec, %.1f lines/sec, %.1f morphemes/sec\n",
                $result->characters / $secondsPerIteration,
                $result->lines / $secondsPerIteration,
                $result->morphemes / $secondsPerIteration,
            )
            . sprintf("Peak memory: %.2f MiB\n", $this->mebibytes($result->peakMemoryBytes))
        );
    }

    /**
     * 利用者が指定した保存先へ、標準出力と同じベンチマークレポートを書き込む。
     */
    private function writeReportFile(InputInterface $input, string $report): void
    {
        $outputFile = $this->stringOption($input, 'output');

        if ($outputFile === null) {
            return;
        }

        if ($outputFile === '') {
            throw new InvalidArgumentException('--output must be a non-empty file path.');
        }

        $resolvedOutputFile = $this->resolveOutputFile($outputFile);
        $writtenBytes = file_put_contents($resolvedOutputFile, $report);

        if ($writtenBytes !== strlen($report)) {
            throw new InvalidArgumentException(sprintf(
                'failed to write benchmark output file: %s',
                $resolvedOutputFile,
            ));
        }
    }

    /**
     * 出力パス内の日時プレースホルダを実行時刻へ展開し、結果ファイルの上書きを避けやすくする。
     */
    private function resolveOutputFile(string $outputFile): string
    {
        return str_replace('{datetime}', date('Ymd-His'), $outputFile);
    }

    /**
     * バイト数を MiB 表記へ変換し、実行環境が違っても読みやすい値にする。
     */
    private function mebibytes(int $bytes): float
    {
        return ($bytes / 1024) / 1024;
    }

    /**
     * Xdebug が計測へ影響する mode で読み込まれている場合だけ警告対象にする。
     */
    private function xdebugAffectsBenchmark(): bool
    {
        if (!extension_loaded('xdebug')) {
            return false;
        }

        $mode = ini_get('xdebug.mode');

        return $mode === false || $mode !== '' && $mode !== 'off';
    }
}
