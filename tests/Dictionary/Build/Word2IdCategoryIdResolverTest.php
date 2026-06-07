<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\DoubleArrayTrieBuilder;
use IgoModern\Dictionary\Build\Word2IdCategoryIdResolver;
use IgoModern\Storage\FileTrieLoader;
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
            'A' => 0,
            'B' => 1,
            'C' => 2,
            "\002DEFAULT" => 3,
            'D' => 4,
            "\002SPACE" => 5,
        ]);

        $resolver = new Word2IdCategoryIdResolver(FileTrieLoader::forBuild());

        $this->assertSame(3, $resolver->resolve($directory, 'UTF-8', 'DEFAULT'));
        $this->assertSame(5, $resolver->resolve($directory, 'UTF-8', 'SPACE'));
    }

    /**
     * カテゴリ名に対応する完全一致キーが word2id にない場合は生成エラーとして扱うことを確認する。
     */
    public function testResolveFailsWhenCategoryKeyIsMissing(): void
    {
        $directory = $this->createDictionaryDirectory([
            'A' => 0,
            'B' => 1,
            'C' => 2,
            "\002DEFAULT" => 3,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown category "SPACE" is not registered in word2id.');

        (new Word2IdCategoryIdResolver(FileTrieLoader::forBuild()))->resolve($directory, 'UTF-8', 'SPACE');
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

        (new DoubleArrayTrieBuilder())->build($keys, $baseName . '/word2id');

        return $baseName;
    }
}
