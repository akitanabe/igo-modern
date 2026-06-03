<?php

declare(strict_types=1);

use IgoModern\ViterbiNode;
use PHPUnit\Framework\TestCase;

/**
 * ViterbiNode の経路探索用ノードとしての初期化結果を検証するテスト。
 */
class ViterbiNodeTest extends TestCase
{
    /**
     * コンストラクタで渡した単語情報と位置情報を公開プロパティに保持することを確認する。
     */
    public function testConstructorStoresGivenValues(): void
    {
        $node = new ViterbiNode(12, 3, 4, 99, 7, 8, true);

        $this->assertSame(12, $node->wordId);
        $this->assertSame(7, $node->leftId);
        $this->assertSame(8, $node->rightId);
        $this->assertSame(4, $node->length);
        $this->assertSame(99, $node->cost);
        $this->assertTrue($node->isSpace);
        $this->assertSame(3, $node->start);
        $this->assertNull($node->prev);
    }

    /**
     * 文頭文末を表す番兵ノードが旧実装と同じゼロ値で作られることを確認する。
     */
    public function testMakeBOSEOSCreatesSentinelNode(): void
    {
        $node = ViterbiNode::makeBOSEOS();

        $this->assertSame(0, $node->wordId);
        $this->assertSame(0, $node->leftId);
        $this->assertSame(0, $node->rightId);
        $this->assertSame(0, $node->length);
        $this->assertSame(0, $node->cost);
        $this->assertFalse($node->isSpace);
        $this->assertSame(0, $node->start);
        $this->assertNull($node->prev);
    }
}
