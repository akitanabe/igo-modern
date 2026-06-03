<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Composer 経由で既存の Igo クラス群を読み込めることを検証するテスト。
 */
class IgoAutoloadTest extends TestCase
{
    /**
     * Igo クラスの読み込み時に既存の require_once 連鎖が解決できることを確認する。
     */
    public function testComposerCanAutoloadIgoEntryPoint(): void
    {
        $this->assertTrue(class_exists(Igo::class));
    }
}
