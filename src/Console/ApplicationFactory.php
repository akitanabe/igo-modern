<?php

declare(strict_types=1);

namespace IgoModern\Console;

use Symfony\Component\Console\Application;

/**
 * CLI エントリポイントから利用する Symfony Console アプリケーションを組み立てる。
 */
class ApplicationFactory
{
    /**
     * Igo Modern の標準コマンドを登録した Console アプリケーションを返す。
     */
    public function create(): Application
    {
        $application = new Application('igo-modern');
        $application->add(new ParseCommand());
        $application->add(new BuildDicCommand());
        $application->setDefaultCommand('parse', true);

        return $application;
    }
}
