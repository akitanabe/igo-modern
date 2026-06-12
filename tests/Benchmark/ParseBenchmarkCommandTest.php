<?php

declare(strict_types=1);

namespace IgoModern\Tests\Benchmark;

use IgoModern\Benchmark\DurationSummary;
use IgoModern\Benchmark\ParseBenchmarkCommand;
use IgoModern\Benchmark\ParseBenchmarkConfig;
use IgoModern\Benchmark\ParseBenchmarkResult;
use IgoModern\Benchmark\ParseBenchmarkRunner;
use IgoModern\Morpheme;
use IgoModern\Parser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ParseBenchmarkCommand が測定条件を受け取り、結果表示と保存を担当することを検証するテスト。
 */
class ParseBenchmarkCommandTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時パスの削除対象を保持する。 */
    private array $temporaryPaths = [];

    /**
     * Runner は必須依存として扱い、null による暗黙生成を constructor に持ち込まないことを確認する。
     */
    public function testConstructorRequiresRunner(): void
    {
        $constructor = (new ReflectionClass(ParseBenchmarkCommand::class))->getConstructor();
        $this->assertNotNull($constructor);

        $runner = $constructor->getParameters()[0];

        $this->assertSame('runner', $runner->getName());
        $this->assertFalse($runner->allowsNull());
        $this->assertFalse($runner->isDefaultValueAvailable());
    }

    /**
     * 通常利用向けの標準 runner 付きコマンドは factory メソッドから作れることを確認する。
     */
    public function testCreateDefaultReturnsCommandWithStandardRunner(): void
    {
        $command = ParseBenchmarkCommand::createDefault();

        $this->assertInstanceOf(ParseBenchmarkCommand::class, $command);
        $this->assertSame('parse', $command->getName());
    }

    /**
     * ベンチマーク条件を位置引数ではなく短縮名付きオプションとして発見できることを確認する。
     */
    public function testConfigureDefinesShortOptionsForBenchmarkInputs(): void
    {
        $definition = (new ParseBenchmarkCommand(new FixedParseBenchmarkRunner()))->getDefinition();

        $this->assertFalse($definition->hasArgument('dictionary'));
        $this->assertSame('d', $definition->getOption('dictionary')->getShortcut());
        $this->assertSame('r', $definition->getOption('iterations')->getShortcut());
        $this->assertSame('w', $definition->getOption('warmup')->getShortcut());
        $this->assertNull($definition->getOption('sample')->getShortcut());
        $this->assertSame('s', $definition->getOption('storage')->getShortcut());
        $this->assertSame('i', $definition->getOption('text')->getShortcut());
        $this->assertSame('f', $definition->getOption('file')->getShortcut());
        $this->assertSame('o', $definition->getOption('output')->getShortcut());
        $this->assertSame('m', $definition->getOption('morpheme-output')->getShortcut());
        $this->assertTrue($definition->hasOption('input-encoding'));
    }

    /**
     * --input-encoding オプションが runner の parserFactory へ伝搬されることを確認する。
     */
    public function testExecutePassesInputEncodingToRunnerParserFactory(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $capturedInputs = [];
        $runner = new ParseBenchmarkRunner(static function (string $dict, string $storage, ?string $inputEncoding) use (
            &$capturedInputs,
        ): Parser {
            $capturedInputs[] = [$dict, $storage, $inputEncoding];

            return new BenchmarkStubParser([new Morpheme('A', 'ALPHA', 0)]);
        });
        $command = new ParseBenchmarkCommand($runner);
        $tester = new CommandTester($command);

        $tester->execute([
            '-d' => $dictionary,
            '--input-encoding' => 'UTF-8',
            '-i' => 'A',
            '-r' => '1',
        ]);

        $this->assertCount(1, $capturedInputs);
        $this->assertSame('UTF-8', $capturedInputs[0][2]);
    }

    /**
     * --input-encoding 未指定時は null が渡り、従来動作（検出あり）になることを確認する。
     */
    public function testExecutePassesNullInputEncodingWhenOptionIsOmitted(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $capturedInputs = [];
        $runner = new ParseBenchmarkRunner(static function (string $dict, string $storage, ?string $inputEncoding) use (
            &$capturedInputs,
        ): Parser {
            $capturedInputs[] = [$dict, $storage, $inputEncoding];

            return new BenchmarkStubParser([new Morpheme('A', 'ALPHA', 0)]);
        });
        $command = new ParseBenchmarkCommand($runner);
        $tester = new CommandTester($command);

        $tester->execute([
            '-d' => $dictionary,
            '-i' => 'A',
            '-r' => '1',
        ]);

        $this->assertCount(1, $capturedInputs);
        $this->assertNull($capturedInputs[0][2]);
    }

    /**
     * storage オプションを設定へ渡し、短縮 -s が sample ではなく storage を選択することを確認する。
     */
    public function testExecutePassesStorageOptionToRunner(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $runner = new FixedParseBenchmarkRunner();
        $command = new ParseBenchmarkCommand($runner);
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([
            '-d' => $dictionary,
            '-s' => 'memory',
            '--sample' => 'news',
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertNotNull($runner->lastConfig);
        $this->assertSame('memory', $runner->lastConfig->storage);
        $this->assertSame('news', $runner->lastConfig->sample);
        $this->assertStringContainsString('Storage: memory', $tester->getDisplay());
    }

    /**
     * 未対応の storage 種別は runner 実行前に CLI 入力エラーとして失敗することを確認する。
     */
    public function testExecuteFailsWhenStorageOptionIsUnknown(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $command = new ParseBenchmarkCommand(new FixedParseBenchmarkRunner());
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([
            '--dictionary' => $dictionary,
            '--storage' => 'sqlite',
        ]);

        $this->assertSame(1, $statusCode);
        $this->assertSame("--storage must be file or memory.\n", $tester->getDisplay());
    }

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
     * 既定の測定回数が統計値を比較できる回数で、行単位 throughput と保存ファイルに同じレポートが出ることを確認する。
     */
    public function testExecuteWritesReportWithLineThroughputToOutputFile(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $outputFile = $dictionary . '/result.txt';
        $this->temporaryPaths[] = $outputFile;
        $command = new ParseBenchmarkCommand(new FixedParseBenchmarkRunner());
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([
            '-d' => $dictionary,
            '-o' => $outputFile,
            '-r' => '3',
            '-w' => '0',
            '--sample' => 'mixed',
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString('Iterations: 3 measured, 0 warmup', $tester->getDisplay());
        $this->assertStringContainsString(
            'Throughput: 400.0 chars/sec, 100.0 lines/sec, 50.0 morphemes/sec',
            $tester->getDisplay(),
        );
        $this->assertFileExists($outputFile);
        $this->assertSame($tester->getDisplay(), file_get_contents($outputFile));
    }

    /**
     * メモリレポートが辞書常駐分と、実使用/割当ピークを区別して表示することを確認する。
     */
    public function testExecuteReportsSeparatedMemoryMetrics(): void
    {
        $dictionary = $this->temporaryDirectory('igo-bench-dic-');
        $command = new ParseBenchmarkCommand(new FixedParseBenchmarkRunner());
        $tester = new CommandTester($command);

        $statusCode = $tester->execute(['-d' => $dictionary]);

        $this->assertSame(0, $statusCode);
        $display = $tester->getDisplay();
        // 辞書常駐分を独立行として表示する。
        $this->assertStringContainsString('Dictionary resident: 0.06 MiB', $display);
        // ピークは実使用と割当を併記し、割当値だけが独り歩きするのを防ぐ。
        $this->assertStringContainsString('Peak memory: 0.50 MiB real / 1.00 MiB allocated', $display);
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
            '--dictionary' => $dictionary,
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
            '--dictionary' => $dictionary,
            '-m' => $morphemeFile,
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertFileExists($morphemeFile);
        $this->assertSame("alpha\tFEATURE_ALPHA,0\nbeta\tFEATURE_BETA,6\n", file_get_contents($morphemeFile));
    }

    /**
     * 必須の辞書オプションが欠けた場合は、runner 実行前に CLI 入力エラーとして失敗することを確認する。
     */
    public function testExecuteFailsWhenDictionaryOptionIsMissing(): void
    {
        $command = new ParseBenchmarkCommand(new FixedParseBenchmarkRunner());
        $tester = new CommandTester($command);

        $statusCode = $tester->execute([]);

        $this->assertSame(1, $statusCode);
        $this->assertSame("option \"--dictionary\" must be a non-empty string.\n", $tester->getDisplay());
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
    /** 最後に受け取った config を保持し、CLI オプション変換の検証に使う。 */
    public ?ParseBenchmarkConfig $lastConfig = null;

    /**
     * 親 constructor の必須 Parser factory を満たしつつ、run の固定結果だけをテスト対象にする。
     */
    public function __construct()
    {
        parent::__construct(static function (string $dictionary, string $storage): Parser {
            throw new RuntimeException('FixedParseBenchmarkRunner does not create parsers.');
        });
    }

    /**
     * 1 秒あたりの文字・行・形態素 throughput を検証しやすい固定結果を返す。
     */
    public function run(ParseBenchmarkConfig $config): ParseBenchmarkResult
    {
        $this->lastConfig = $config;

        return new ParseBenchmarkResult(
            $config,
            "alpha\nbeta\n",
            400,
            11,
            100,
            50,
            new DurationSummary(1000.0, 1000.0, 1000.0, 1000.0, 1000.0),
            1024 * 1024,
            512 * 1024,
            64 * 1024,
            ["alpha\tFEATURE_ALPHA,0", "beta\tFEATURE_BETA,6"],
        );
    }
}
