<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\Word2IdCategoryIdResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Word2IdCategoryIdResolver が word2id の未知語カテゴリキーから trie ID を解決することを検証するテスト。
 */
class Word2IdCategoryIdResolverTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テスト用に作成した word2id と一時ディレクトリを削除し、状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $word2id = $directory . '/word2id';

            if (is_file($word2id)) {
                unlink($word2id);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * "\002" prefix 付きカテゴリキーに完全一致した trie ID を返すことを確認する。
     */
    public function testResolveReturnsTrieIdForUnknownCategoryKey(): void
    {
        $directory = $this->createDictionaryDirectory([
            "\002DEFAULT" => 3,
            "\002SPACE" => 5,
        ]);

        $resolver = new Word2IdCategoryIdResolver();

        $this->assertSame(3, $resolver->resolve($directory, 'UTF-8', 'DEFAULT'));
        $this->assertSame(5, $resolver->resolve($directory, 'UTF-8', 'SPACE'));
    }

    /**
     * カテゴリ名に対応する完全一致キーが word2id にない場合は生成エラーとして扱うことを確認する。
     */
    public function testResolveFailsWhenCategoryKeyIsMissing(): void
    {
        $directory = $this->createDictionaryDirectory([
            "\002DEFAULT" => 3,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown category "SPACE" is not registered in word2id.');

        (new Word2IdCategoryIdResolver())->resolve($directory, 'UTF-8', 'SPACE');
    }

    /**
     * 指定したカテゴリキーを含む word2id を持つ一時辞書ディレクトリを作成する。
     *
     * @param array<string, int> $keys
     */
    private function createDictionaryDirectory(array $keys): string
    {
        $baseName = tempnam(sys_get_temp_dir(), 'igo-category-id-');
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        $this->writeBinaryFile($baseName . '/word2id', $this->createTrieDictionary($keys));

        return $baseName;
    }

    /**
     * tail 圧縮を使わない小さな double-array trie 辞書を Searcher のバイナリ形式で作成する。
     *
     * @param array<string, int> $keys
     */
    private function createTrieDictionary(array $keys): string
    {
        $root = new TrieNode(1);
        $nextBase = 1000;

        foreach ($keys as $key => $id) {
            $node = $root;

            foreach ($this->utf16CodeUnits($key) as $code) {
                if (!isset($node->children[$code])) {
                    $node->children[$code] = new TrieNode($nextBase);
                    $nextBase += 1000;
                }

                $node = $node->children[$code];
            }

            $node->terminalId = $id;
        }

        $nodeSize = $this->nodeSize($root);
        $base = array_fill(0, $nodeSize, 0);
        $chck = array_fill(0, $nodeSize, 0);
        $base[0] = $root->base;
        [$base, $chck] = $this->emitTrieNode($root, $base, $chck);

        return (
            $this->packValues('l', [$nodeSize, count($keys), 0])
            . $this->packValues('l', array_fill(0, count($keys), 0))
            . $this->packValues('l', array_values($base))
            . $this->packValues('s', array_fill(0, count($keys), 0))
            . $this->packValues('S', array_values($chck))
        );
    }

    /**
     * trie ノードと遷移が収まる double-array 配列サイズを算出する。
     */
    private function nodeSize(TrieNode $node): int
    {
        $maxIndex = $node->base;

        foreach ($node->children as $code => $child) {
            $maxIndex = max($maxIndex, $node->base + $code, $this->nodeSize($child) - 1);
        }

        return $maxIndex + 1;
    }

    /**
     * trie ノードを base/check 配列へ展開し、終端 ID と遷移を Searcher の規則で保存する。
     *
     * @param array<int, int> $base
     * @param array<int, int> $chck
     * @return array{0:array<int, int>, 1:array<int, int>}
     */
    private function emitTrieNode(TrieNode $node, array $base, array $chck): array
    {
        if ($node->terminalId !== null) {
            $base[$node->base] = -($node->terminalId + 1);
        }

        foreach ($node->children as $code => $child) {
            $index = $node->base + $code;
            $base[$index] = $child->base;
            $chck[$index] = $code;
            [$base, $chck] = $this->emitTrieNode($child, $base, $chck);
        }

        return [$base, $chck];
    }

    /**
     * UTF-8 文字列を Searcher と同じ UTF-16LE code unit 配列へ変換する。
     *
     * @return list<int>
     */
    private function utf16CodeUnits(string $key): array
    {
        $values = unpack('S*', mb_convert_encoding($key, 'UTF-16LE', 'UTF-8'));
        $this->assertIsArray($values);

        return array_values($values);
    }

    /**
     * 指定パスにバイナリファイルを書き込み、内容が欠けずに保存されたことを確認する。
     */
    private function writeBinaryFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);
    }

    /**
     * 旧実装と同じ pack 形式で数値列をバイナリ文字列へ変換する。
     *
     * @param list<int> $values
     */
    private function packValues(string $format, array $values): string
    {
        $binary = '';

        foreach ($values as $value) {
            $binary .= pack($format, $value);
        }

        return $binary;
    }
}

/**
 * テスト用 trie の base offset、終端 ID、子遷移を保持する単純なノード。
 */
class TrieNode
{
    /** 完全一致するキーがある場合の trie ID を保持する。 */
    public ?int $terminalId = null;

    /** @var array<int, self> 文字コードごとの子ノードを保持する。 */
    public array $children = [];

    /**
     * double-array 内でこのノードの遷移基準になる base offset を保持する。
     */
    public function __construct(
        public int $base,
    ) {}
}
