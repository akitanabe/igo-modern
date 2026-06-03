<?php

declare(strict_types=1);

use IgoModern\Analysis\Tagger;
use IgoModern\Binary\FileMappedInputStream;
use IgoModern\Dictionary\Trie\Searcher;
use IgoModern\Dictionary\WordDic;
use IgoModern\Igo;
use PHPUnit\Framework\TestCase;

/**
 * Composer 経由で公開入口と役割別 namespace の内部クラスを読み込めることを検証するテスト。
 */
class IgoAutoloadTest extends TestCase
{
    /**
     * PSR-4 の配置変更後も主要な公開クラスと内部クラスが解決できることを確認する。
     */
    public function testComposerCanAutoloadIgoEntryPoint(): void
    {
        $this->assertTrue(class_exists(Igo::class));
        $this->assertTrue(class_exists(Tagger::class));
        $this->assertTrue(class_exists(WordDic::class));
        $this->assertTrue(class_exists(Searcher::class));
        $this->assertTrue(class_exists(FileMappedInputStream::class));
    }
}
