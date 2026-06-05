<?php

declare(strict_types=1);

namespace IgoModern\Tests\Console;

use IgoModern\Console\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * ApplicationFactory が公開 CLI に登録するコマンド構成を検証するテスト。
 */
class ApplicationFactoryTest extends TestCase
{
    /**
     * parse に加えて辞書生成コマンドが登録されることを確認する。
     */
    public function testCreateRegistersBuildDicCommand(): void
    {
        $application = (new ApplicationFactory())->create();

        $this->assertTrue($application->has('parse'));
        $this->assertTrue($application->has('build-dic'));
    }

    /**
     * 実 CLI と同じアプリケーション解決で build-dic が parse ではなく辞書生成コマンドとして実行されることを確認する。
     */
    public function testCreateAllowsBuildDicCommandExecution(): void
    {
        $application = (new ApplicationFactory())->create();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $statusCode = $tester->run(['command' => 'build-dic', '--help' => true]);

        $this->assertSame(0, $statusCode);
        $this->assertStringContainsString(
            'Build an Igo dictionary from a MeCab-compatible dictionary.',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString('--output', $tester->getDisplay());
        $this->assertStringContainsString('--input', $tester->getDisplay());
    }
}
