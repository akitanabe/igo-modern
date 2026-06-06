<?php

declare(strict_types=1);

namespace IgoModern\Tests\Analysis;

use IgoModern\Analysis\Tagger;
use IgoModern\Analysis\ViterbiNode;
use IgoModern\Dictionary\Contract\ConnectionMatrix;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\Contract\WordDictionary;
use IgoModern\Dictionary\WordDicCallback;
use IgoModern\Morpheme;
use IgoModern\Storage\DictionaryStorage;
use PHPUnit\Framework\TestCase;

/**
 * Tagger がバイナリ辞書に依存せず、DictionaryStorage 抽象経由で解析できることを検証するテスト。
 */
class TaggerFromStorageTest extends TestCase
{
    /**
     * 通常辞書に一致のない入力を、storage の未知語辞書が候補化し、その wordId を
     * 同一 storage の単語辞書が素性へ解決して parse 結果になることを確認する。
     */
    public function testParseResolvesUnknownCandidateThroughStorage(): void
    {
        $storage = new FakeDictionaryStorage();

        $result = Tagger::fromStorage($storage)->parse('AB');

        $this->assertCount(1, $result);
        $this->assertContainsOnlyInstancesOf(Morpheme::class, $result);
        $this->assertSame('AB', $result[0]->surface);
        $this->assertSame('UNKNOWN', $result[0]->feature);
        $this->assertSame(0, $result[0]->start);
    }
}

/**
 * バイナリファイルを使わず、3 つの辞書 fake を束ねるテスト用 storage。
 */
final class FakeDictionaryStorage implements DictionaryStorage
{
    /** 同一 storage 内で wordId を共有させるため、単語辞書 fake を一度だけ生成して保持する。 */
    private FakeWordDictionary $wordDictionary;

    /**
     * 未知語辞書が通知する wordId を単語辞書 fake が解決できるよう、共有インスタンスを組み立てる。
     */
    public function __construct()
    {
        $this->wordDictionary = new FakeWordDictionary();
    }

    /**
     * 既知語をまったく返さない単語辞書 fake を返す。
     */
    public function wordDictionary(): WordDictionary
    {
        return $this->wordDictionary;
    }

    /**
     * 入力全体を 1 候補として通知する未知語辞書 fake を返す。
     */
    public function unknownWordDictionary(): UnknownWordDictionary
    {
        return new FakeUnknownWordDictionary();
    }

    /**
     * 連接コストを常に 0 とする行列 fake を返す。
     */
    public function connectionMatrix(): ConnectionMatrix
    {
        return new FakeConnectionMatrix();
    }
}

/**
 * 既知語は通知せず、未知語が使う wordId の素性だけを解決する単語辞書 fake。
 */
final class FakeWordDictionary implements WordDictionary
{
    /**
     * 既知語は一切一致しないため、候補を通知しない。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void
    {
        // 既知語なし。未知語経路を必ず通すため何も通知しない。
    }

    /**
     * 未知語辞書が通知した wordId に対応する素性を UTF-16LE バイト列で返す。
     */
    public function wordData(int $wordId): string
    {
        return mb_convert_encoding('UNKNOWN', 'UTF-16LE', 'UTF-8');
    }
}

/**
 * 入力全体を 1 つの未知語候補として通知する未知語辞書 fake。
 */
final class FakeUnknownWordDictionary implements UnknownWordDictionary
{
    /**
     * 開始位置から入力末尾までを 1 候補とし、wordId 7 の未知語ノードを通知する。
     *
     * @param list<int> $text
     */
    public function search(array $text, int $start, WordDicCallback $fn): void
    {
        $fn->call(new ViterbiNode(7, $start, count($text) - $start, 0, 0, 0, false));
    }
}

/**
 * 連接コストを常に 0 とする行列 fake。
 */
final class FakeConnectionMatrix implements ConnectionMatrix
{
    /**
     * どの ID 組でも連接コストを 0 として返す。
     */
    public function linkCost(int $leftId, int $rightId): int
    {
        return 0;
    }
}
