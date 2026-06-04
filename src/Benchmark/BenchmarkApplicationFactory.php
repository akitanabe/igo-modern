<?php

declare(strict_types=1);

namespace IgoModern\Benchmark;

use Symfony\Component\Console\Application;

/**
 * ベンチマーク用 Symfony Console アプリケーションを組み立てる。
 */
class BenchmarkApplicationFactory
{
    /**
     * 開発者向けベンチマークコマンドを登録した Console アプリケーションを返す。
     */
    public function create(): Application
    {
        $application = new Application('igo-modern-bench');
        $application->add(ParseBenchmarkCommand::createDefault());

        return $application;
    }
}
