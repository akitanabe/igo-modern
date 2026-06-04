<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Trie;

use IgoModern\Binary\Contract\CharArray;
use IgoModern\Dictionary\Trie\KeyStream;
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
        $prefix = new FixedCharArray([0, 20, 30, 99]);

        $this->assertTrue($stream->startsWith($prefix, 1, 2));
        $this->assertFalse($stream->startsWith(new FixedCharArray([20, 99]), 0, 2));
        $this->assertFalse($stream->startsWith(new FixedCharArray([20, 30, 40, 50]), 0, 4));
    }
}

/**
 * KeyStream の prefix 比較に使う文字コード配列を固定値で返す。
 */
class FixedCharArray implements CharArray
{
    /**
     * prefix として返す文字コード列を保持する。
     *
     * @param list<int> $values
     */
    public function __construct(
        private array $values,
    ) {}

    /**
     * 指定添字に対応する文字コードを返す。
     */
    public function get(int $idx): int
    {
        return $this->values[$idx];
    }
}
