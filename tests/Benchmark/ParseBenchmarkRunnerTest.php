<?php

declare(strict_types=1);

namespace IgoModern\Tests\Benchmark;

use IgoModern\Benchmark\ParseBenchmarkConfig;
use IgoModern\Benchmark\ParseBenchmarkRunner;
use IgoModern\Morpheme;
use IgoModern\Parser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ParseBenchmarkRunner が標準 factory と明示注入 factory を分離して扱うことを検証するテスト。
 */
class ParseBenchmarkRunnerTest extends TestCase
{
    /**
     * Parser factory は必須依存として扱い、null による暗黙の標準構成を許容しないことを確認する。
     */
    public function testConstructorRequiresParserFactory(): void
    {
        $constructor = (new ReflectionClass(ParseBenchmarkRunner::class))->getConstructor();
        $this->assertNotNull($constructor);

        $parserFactory = $constructor->getParameters()[0];

        $this->assertSame('parserFactory', $parserFactory->getName());
        $this->assertFalse($parserFactory->allowsNull());
        $this->assertFalse($parserFactory->isDefaultValueAvailable());
    }

    /**
     * 通常利用向けの標準構成は constructor ではなく factory メソッドから作れることを確認する。
     */
    public function testCreateDefaultReturnsStandardRunner(): void
    {
        $runner = ParseBenchmarkRunner::createDefault();

        $this->assertInstanceOf(ParseBenchmarkRunner::class, $runner);
    }

    /**
     * 明示注入した Parser factory が辞書パスを受け取り、測定対象 Parser として利用されることを確認する。
     */
    public function testRunUsesInjectedParserFactory(): void
    {
        $createdDictionaries = [];
        $runner = new ParseBenchmarkRunner(static function (string $dictionary) use (&$createdDictionaries): Parser {
            $createdDictionaries[] = $dictionary;

            return new BenchmarkStubParser([
                new Morpheme('alpha', 'FEATURE_ALPHA', 0),
                new Morpheme('beta', 'FEATURE_BETA', 5),
            ]);
        });

        $result = $runner->run(new ParseBenchmarkConfig('/tmp/dictionary', 1, 0, 'mixed', 'alpha beta'));

        $this->assertSame(['/tmp/dictionary'], $createdDictionaries);
        $this->assertSame(2, $result->morphemes);
        $this->assertSame(["alpha\tFEATURE_ALPHA,0", "beta\tFEATURE_BETA,5"], $result->morphemeOutputLines);
    }

    /**
     * 辞書ロードの常駐メモリと parse のピークを分離指標として収集し、
     * 実使用ピークが割当ピークを超えない関係になることを確認する。
     */
    public function testRunReportsSeparatedMemoryMetrics(): void
    {
        $runner = new ParseBenchmarkRunner(static fn(string $dictionary): Parser => new BenchmarkStubParser([
            new Morpheme('alpha', 'FEATURE_ALPHA', 0),
        ]));

        $result = $runner->run(new ParseBenchmarkConfig('/tmp/dictionary', 2, 1, 'mixed', 'alpha'));

        // 辞書常駐分は非負、実使用ピークは正の、独立した指標として得られる。
        $this->assertGreaterThanOrEqual(0, $result->dictionaryResidentBytes);
        $this->assertGreaterThan(0, $result->peakMemoryRealBytes);
        // 実使用ピークは、OS から確保した割当ピークを超えない。
        $this->assertLessThanOrEqual($result->peakMemoryBytes, $result->peakMemoryRealBytes);
    }
}

/**
 * ベンチマーク測定で実辞書に依存せず、固定の形態素列を返す Parser。
 */
class BenchmarkStubParser implements Parser
{
    /**
     * 返却する形態素列を保持する。
     *
     * @param list<Morpheme> $morphemes
     */
    public function __construct(
        private array $morphemes,
    ) {}

    /**
     * 入力に依存しない固定結果により、Runner の依存注入と整形だけを検証できるようにする。
     *
     * @return list<Morpheme>
     */
    public function parse(string $text): array
    {
        return $this->morphemes;
    }
}
