<?php

use PHPUnit\Framework\TestCase;

/**
 * Morpheme の値オブジェクトとしての初期化結果を検証するテスト。
 */
class MorphemeTest extends TestCase
{
    /**
     * コンストラクタで渡した表層形・素性・開始位置を公開プロパティに保持することを確認する。
     */
    public function testConstructorStoresGivenValues(): void
    {
        $morpheme = new Morpheme('すもも', '名詞,一般', 3);

        $this->assertSame('すもも', $morpheme->surface);
        $this->assertSame('名詞,一般', $morpheme->feature);
        $this->assertSame(3, $morpheme->start);
    }
}
