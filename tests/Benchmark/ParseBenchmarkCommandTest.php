<?php

declare(strict_types=1);

namespace IgoModern\Tests\Benchmark;

use IgoModern\Benchmark\DurationSummary;
use IgoModern\Benchmark\ParseBenchmarkCommand;
use IgoModern\Benchmark\ParseBenchmarkConfig;
use IgoModern\Benchmark\ParseBenchmarkResult;
use IgoModern\Benchmark\ParseBenchmarkRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ParseBenchmarkCommand が測定条件を受け取り、結果表示と保存を担当することを検証するテスト。
 */
class ParseBenchmarkCommandTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時パスの削除対象を保持する。 */
    private array $temporaryPaths = [];

    /**
     * テスト用の出力ファイルとディレクトリを削除し、ファイルシステム上の状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                unlink($path);
                continue;
            }

            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    /**
     * 既定の測定回数が 1 回で、行単位 throughput と保存ファイルに同じレポートが出ることを確認する。
     */
    public function testExecuteWritesReportWithLineThroughputToOutputFile(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $outputFile = $dictionary . '/result.txt';
        $this->temporaryPaths[] = $outputFile;
        $command = new ParseBenchmarkCommand(new FixedParseBenchmarkRunner());
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([
            'dictionary' => $dictionary,
            '--output' => $outputFile,
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString('Iterations: 1 measured, 0 warmup', $tester->getDisplay());
        $this->assertStringContainsString(
            'Throughput: 400.0 chars/sec, 100.0 lines/sec, 50.0 morphemes/sec',
            $tester->getDisplay(),
        );
        $this->assertFileExists($outputFile);
        $this->assertSame($tester->getDisplay(), file_get_contents($outputFile));
    }

    /**
     * 出力パスの datetime プレースホルダが、実行時刻を含むファイル名へ展開されることを確認する。
     */
    public function testExecuteExpandsDatetimePlaceholderInOutputPath(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $command = new ParseBenchmarkCommand(new FixedParseBenchmarkRunner());
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([
            'dictionary' => $dictionary,
            '--output' => $dictionary . '/result-{datetime}.txt',
        ]);

        $this->assertSame(0, $statusCode);

        $matches = glob($dictionary . '/result-????????-??????.txt');
        $this->assertIsArray($matches);
        $this->assertCount(1, $matches);
        $this->temporaryPaths[] = $matches[0];
        $this->assertSame($tester->getDisplay(), file_get_contents($matches[0]));
    }

    /**
     * 形態素解析結果を別ファイルへ保存し、後続変更で解析結果を比較できることを確認する。
     */
    public function testExecuteWritesMorphemeOutputFile(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $morphemeFile = $dictionary . '/morphemes.txt';
        $this->temporaryPaths[] = $morphemeFile;
        $command = new ParseBenchmarkCommand(new FixedParseBenchmarkRunner());
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([
            'dictionary' => $dictionary,
            '--morpheme-output' => $morphemeFile,
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertFileExists($morphemeFile);
        $this->assertSame("alpha\tFEATURE_ALPHA,0\nbeta\tFEATURE_BETA,6\n", file_get_contents($morphemeFile));
    }

    /**
     * 指定 prefix の一時ディレクトリを作成し、後片付け対象として記録する。
     */
    private function temporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($path);
        unlink($path);
        mkdir($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }
}

/**
 * コマンドテストで実辞書に依存せず、固定のベンチマーク結果を返す runner。
 */
class FixedParseBenchmarkRunner extends ParseBenchmarkRunner
{
    /**
     * 1 秒あたりの文字・行・形態素 throughput を検証しやすい固定結果を返す。
     */
    public function run(ParseBenchmarkConfig $config): ParseBenchmarkResult
    {
        return new ParseBenchmarkResult(
            $config,
            "alpha\nbeta\n",
            400,
            11,
            100,
            50,
            new DurationSummary(1000.0, 1000.0, 1000.0, 1000.0, 1000.0),
            1024 * 1024,
            ["alpha\tFEATURE_ALPHA,0", "beta\tFEATURE_BETA,6"],
        );
    }
}
