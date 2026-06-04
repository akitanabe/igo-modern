<?php

declare(strict_types=1);

namespace IgoModern\Tests\Benchmark;

use IgoModern\Benchmark\BenchmarkApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * BenchmarkApplicationFactory がベンチマーク用 Console アプリケーションを組み立てることを検証するテスト。
 */
class BenchmarkApplicationFactoryTest extends TestCase
{
    /**
     * parse サブコマンドが登録され、Symfony Console の help として主要オプションを表示できることを確認する。
     */
    public function testCreateRegistersParseCommand(): void
    {
        $application = (new BenchmarkApplicationFactory())->create();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $statusCode = $tester->run(['command' => 'parse', '--help' => true]);

        $this->assertSame(0, $statusCode);
        $this->assertTrue($application->has('parse'));
        $this->assertStringContainsString(
            'Benchmark morphological parsing with an Igo dictionary.',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString('dictionary', $tester->getDisplay());
        $this->assertStringContainsString('[default: "1"]', $tester->getDisplay());
        $this->assertStringContainsString('--file', $tester->getDisplay());
        $this->assertStringContainsString('--output', $tester->getDisplay());
    }
}
