<?php

declare(strict_types=1);

namespace IgoModern\Tests;

use PHPUnit\Framework\TestCase;

/**
 * production class が PSR-4 のクラス単位 autoload で単独解決できることを検証するテスト。
 */
class Psr4AutoloadTest extends TestCase
{
    /**
     * 補助クラスを含む production class が、親ファイルの事前読み込みなしに autoload できることを確認する。
     */
    public function testProductionHelperClassesAreAutoloadableByPsr4Path(): void
    {
        $classes = [
            'IgoModern\\Dictionary\\Build\\TrieBuildNode',
            'IgoModern\\Dictionary\\Build\\ExactCategoryKeyCallback',
            'IgoModern\\Dictionary\\WordDicCallbackCaller',
        ];

        foreach ($classes as $className) {
            $this->assertClassExistsInFreshProcess($className);
        }
    }

    /**
     * PHPUnit の読み込み済みクラスに依存しないよう、autoload だけを有効にした別プロセスで確認する。
     */
    private function assertClassExistsInFreshProcess(string $className): void
    {
        $code = sprintf(
            'require %s; exit(class_exists(%s) ? 0 : 1);',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', return: true),
            var_export($className, return: true),
        );

        $process = proc_open(
            [PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $code],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__),
        );

        $this->assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(
            0,
            proc_close($process),
            sprintf('Class %s was not autoloadable. Output: %s%s', $className, $output, $errorOutput),
        );
    }
}
