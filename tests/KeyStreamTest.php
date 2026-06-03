<?php

declare(strict_types=1);

use IgoModern\KeyStream;
use PHPUnit\Framework\TestCase;

/**
 * KeyStream がキー配列をカーソル位置から順に読む挙動を検証するテスト。
 */
class KeyStreamTest extends TestCase
{
    /**
     * read が現在位置の値を返してカーソルを進め、終端後は 0 を返すことを確認する。
     */
    public function testReadReturnsCurrentValueAndZeroAfterEnd(): void
    {
        $stream = new KeyStream([10, 20], 0);

        $this->assertFalse($stream->eos());
        $this->assertSame(10, $stream->read());
        $this->assertFalse($stream->eos());
        $this->assertSame(20, $stream->read());
        $this->assertTrue($stream->eos());
        $this->assertSame(0, $stream->read());
        $this->assertTrue($stream->eos());
    }

    /**
     * コンストラクタで指定した開始位置から読み取りを始めることを確認する。
     */
    public function testConstructorUsesGivenStartPosition(): void
    {
        $stream = new KeyStream([10, 20, 30], 1);

        $this->assertSame(20, $stream->read());
        $this->assertSame(30, $stream->read());
        $this->assertTrue($stream->eos());
    }

    /**
     * startsWith が現在位置から prefix の指定範囲と一致するかを判定することを確認する。
     */
    public function testStartsWithComparesPrefixFromCurrentPosition(): void
    {
        $stream = new KeyStream([10, 20, 30, 40], 1);

        $this->assertTrue($stream->startsWith([0, 20, 30, 99], 1, 2));
        $this->assertFalse($stream->startsWith([20, 99], 0, 2));
        $this->assertFalse($stream->startsWith([20, 30, 40, 50], 0, 4));
    }
}
