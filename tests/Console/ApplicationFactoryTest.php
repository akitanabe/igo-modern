<?php

declare(strict_types=1);

namespace IgoModern\Tests\Console;

use IgoModern\Console\ApplicationFactory;
use PHPUnit\Framework\TestCase;

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
}
