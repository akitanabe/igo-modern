<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary;

use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\IntMemoryArray;
use IgoModern\Dictionary\Category;
use IgoModern\Dictionary\CharCategory;
use PHPUnit\Framework\TestCase;

/**
 * CharCategory が構築済みのカテゴリ定義と文字コード表から、カテゴリ参照と互換性判定を行う挙動を検証するテスト。
 *
 * ファイル配置からの構築は Storage loader の責務へ移ったため、ここでは constructor に構築済みデータを渡す純粋テストにする。
 */
class CharCategoryTest extends TestCase
{
    /**
     * category が文字コードに割り当てられたカテゴリ定義を返すことを確認する。
     */
    public function testCategoryReturnsCategoryAssignedToCharacterCode(): void
    {
        $category = new CharCategory(
            [
                new Category(0, 1, false, false),
                new Category(7, 3, true, false),
                new Category(9, 5, false, true),
            ],
            // 文字コード 65 -> カテゴリ index 1、66 -> index 2、その他 -> index 0。
            $this->intArray([65 => 1, 66 => 2]),
            $this->intArray([65 => 0b0011, 66 => 0b0100]),
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
        $category = new CharCategory(
            [
                new Category(0, 1, false, false),
                new Category(1, 2, true, true),
            ],
            $this->intArray([65 => 1, 66 => 1, 67 => 1]),
            $this->intArray([65 => 0b0011, 66 => 0b0010, 67 => 0b0100]),
        );

        $this->assertTrue($category->isCompatible(65, 66));
        $this->assertFalse($category->isCompatible(65, 67));
    }

    /**
     * 文字コードを添字とする int 配列を、指定値以外を 0 埋めした IntArray として構築する。
     *
     * array_fill + foreach の代入で PHPStan が array<int,int> と推論するため、
     * array_values で list<int> に正規化してから IntMemoryArray へ渡す。
     *
     * @param non-empty-array<int, int> $values
     */
    private function intArray(array $values): IntArray
    {
        $size = max(array_keys($values)) + 1;
        $dense = array_fill(0, count: $size, value: 0);

        foreach ($values as $code => $value) {
            $dense[$code] = $value;
        }

        return new IntMemoryArray(array_values($dense));
    }
}
