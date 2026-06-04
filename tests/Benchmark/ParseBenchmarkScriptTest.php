<?php

declare(strict_types=1);

namespace IgoModern\Tests\Benchmark;

use PHPUnit\Framework\TestCase;

/**
 * 解析ベンチマーク用 CLI が bin/bench から実行できる入口を提供することを検証するテスト。
 */
class ParseBenchmarkScriptTest extends TestCase
{
    /**
     * help 表示で利用者が必須引数と主要オプションを確認できることを検証する。
     */
    public function testHelpShowsUsageAndOptions(): void
    {
        [$exitCode, $stdout] = $this->runCommand([PHP_BINARY, __DIR__ . '/../../bin/bench', 'parse', '--help']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Benchmark morphological parsing with an Igo dictionary.', $stdout);
        $this->assertStringContainsString('--iterations', $stdout);
        $this->assertStringContainsString('--sample', $stdout);
        $this->assertStringContainsString('--file', $stdout);
    }

    /**
     * 指定コマンドを別プロセスで起動し、CLI と同じ標準出力を取得する。
     *
     * @param list<string> $command
     * @return array{0:int, 1:string}
     */
    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->assertIsString($stdout);
        $this->assertIsString($stderr);

        return [$exitCode, $stdout . $stderr];
    }
}
