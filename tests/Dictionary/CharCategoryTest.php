<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary;

use IgoModern\Dictionary\Category;
use IgoModern\Dictionary\CharCategory;
use IgoModern\Storage\FileInputStreamFactory;
use IgoModern\Storage\PagedByteReaderFactory;
use IgoModern\Tests\Support\RecordingByteReaderFactory;
use PHPUnit\Framework\TestCase;

/**
 * CharCategory が文字コードごとの未知語カテゴリと互換性マスクを読む挙動を検証するテスト。
 */
class CharCategoryTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * テストで作成した辞書ディレクトリとカテゴリファイルを削除して状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (['char.category', 'code2category'] as $fileName) {
                $filePath = $directory . '/' . $fileName;

                if (is_file($filePath)) {
                    unlink($filePath);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * category が文字コードに割り当てられたカテゴリ定義を返すことを確認する。
     */
    public function testCategoryReturnsCategoryAssignedToCharacterCode(): void
    {
        $category = CharCategory::fromDataDir(
            $this->createDictionaryDirectory(
                [
                    ['id' => 0, 'length' => 1, 'invoke' => false, 'group' => false],
                    ['id' => 7, 'length' => 3, 'invoke' => true, 'group' => false],
                    ['id' => 9, 'length' => 5, 'invoke' => false, 'group' => true],
                ],
                [65 => 1, 66 => 2],
                [65 => 0b0011, 66 => 0b0100],
            ),
            FileInputStreamFactory::lazy(new PagedByteReaderFactory()),
        );

        $latinA = $category->category(65);
        $latinB = $category->category(66);

        $this->assertInstanceOf(Category::class, $latinA);
        $this->assertSame(7, $latinA->id);
        $this->assertSame(3, $latinA->length);
        $this->assertTrue($latinA->invoke);
        $this->assertFalse($latinA->group);
        $this->assertSame(9, $latinB->id);
        $this->assertSame(5, $latinB->length);
        $this->assertFalse($latinB->invoke);
        $this->assertTrue($latinB->group);
    }

    /**
     * isCompatible が文字コード同士の eqlMask に共通ビットがあるかどうかを判定することを確認する。
     */
    public function testIsCompatibleUsesSharedMaskBits(): void
    {
        $category = CharCategory::fromDataDir(
            $this->createDictionaryDirectory(
                [
                    ['id' => 0, 'length' => 1, 'invoke' => false, 'group' => false],
                    ['id' => 1, 'length' => 2, 'invoke' => true, 'group' => true],
                ],
                [65 => 1, 66 => 1, 67 => 1],
                [65 => 0b0011, 66 => 0b0010, 67 => 0b0100],
            ),
            FileInputStreamFactory::lazy(new PagedByteReaderFactory()),
        );

        $this->assertTrue($category->isCompatible(65, 66));
        $this->assertFalse($category->isCompatible(65, 67));
    }

    /**
     * 注入された factory が code2category に対し open され、Lazy 配列生成へ漏れなく伝播することを確認する。
     */
    public function testFactoryIsPropagatedToCode2CategoryFile(): void
    {
        $directory = $this->createDictionaryDirectory(
            [['id' => 0, 'length' => 1, 'invoke' => false, 'group' => false]],
            [65 => 0],
            [65 => 0b0001],
        );
        $factory = new RecordingByteReaderFactory();

        CharCategory::fromDataDir($directory, FileInputStreamFactory::lazy($factory));

        $openedBaseNames = array_values(array_unique(array_map('basename', $factory->openedFiles)));

        $this->assertSame(['code2category'], $openedBaseNames);
    }

    /**
     * テスト用の辞書ディレクトリを作り、カテゴリ辞書を旧実装と同じバイナリ形式で配置する。
     *
     * @param list<array{id:int, length:int, invoke:bool, group:bool}> $categories
     * @param array<int, int> $charToCategoryIds
     * @param array<int, int> $eqlMasks
     */
    private function createDictionaryDirectory(array $categories, array $charToCategoryIds, array $eqlMasks): string
    {
        $baseName = tempnam(sys_get_temp_dir(), 'igo-char-category-');
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        $maxCode = max(array_merge([0], array_keys($charToCategoryIds), array_keys($eqlMasks)));
        $charToCategory = array_fill(0, $maxCode + 1, 0);
        $masks = array_fill(0, $maxCode + 1, 0);

        foreach ($charToCategoryIds as $code => $categoryId) {
            $charToCategory[$code] = $categoryId;
        }

        foreach ($eqlMasks as $code => $mask) {
            $masks[$code] = $mask;
        }

        $categoryValues = [];
        foreach ($categories as $category) {
            $categoryValues[] = $category['id'];
            $categoryValues[] = $category['length'];
            $categoryValues[] = $category['invoke'] ? 1 : 0;
            $categoryValues[] = $category['group'] ? 1 : 0;
        }

        $this->writeFile($baseName . '/char.category', $this->packValues('l', $categoryValues));
        $this->writeFile(
            $baseName . '/code2category',
            $this->packValues('l', array_values($charToCategory)) . $this->packValues('l', array_values($masks)),
        );

        return $baseName;
    }

    /**
     * 指定パスにバイナリ辞書を書き込み、内容が欠けずに保存されたことを確認する。
     */
    private function writeFile(string $filePath, string $contents): void
    {
        $writtenBytes = file_put_contents($filePath, $contents);
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
