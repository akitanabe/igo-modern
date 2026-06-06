<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\CategoryIdResolver;
use IgoModern\Dictionary\Build\CharCategoryBuilder;
use IgoModern\Storage\FileBinaryDictionaryLoader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * CharCategoryBuilder が char.def から runtime CharCategory 互換のカテゴリ辞書を生成することを検証するテスト。
 */
class CharCategoryBuilderTest extends TestCase
{
    /** @var list<string> テスト中に作成した一時ディレクトリの削除対象を保持する。 */
    private array $temporaryDirectories = [];

    /**
     * CategoryIdResolver は必須依存として扱い、null による標準 resolver 生成を constructor に隠さないことを確認する。
     */
    public function testConstructorRequiresCategoryIdResolver(): void
    {
        $constructor = (new ReflectionClass(CharCategoryBuilder::class))->getConstructor();
        $this->assertNotNull($constructor);

        $categoryIdResolver = $constructor->getParameters()[0];

        $this->assertSame('categoryIdResolver', $categoryIdResolver->getName());
        $this->assertFalse($categoryIdResolver->allowsNull());
        $this->assertFalse($categoryIdResolver->isDefaultValueAvailable());
    }

    /**
     * 通常利用向けの標準 CategoryIdResolver は factory メソッドから組み立てられることを確認する。
     */
    public function testCreateDefaultReturnsCharCategoryBuilder(): void
    {
        $builder = CharCategoryBuilder::createDefault();

        $this->assertInstanceOf(CharCategoryBuilder::class, $builder);
    }

    /**
     * テスト用に作成した入力・出力ディレクトリとファイルを削除し、状態を戻す。
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            foreach (['char.def', 'char.category', 'code2category'] as $fileName) {
                $path = $directory . '/' . $fileName;

                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * カテゴリ定義とコード範囲定義から runtime が読める char.category と code2category を生成することを確認する。
     */
    public function testBuildWritesCategoryFilesReadableByRuntimeCharCategory(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-char-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-char-out-');
        $this->writeTextFile(
            $inputDirectory . '/char.def',
            "# category invoke group length\n"
            . "DEFAULT 1 0 1\n"
            . "SPACE 0 1 2\n"
            . "ALPHA 1 1 4\n"
            . "SYMBOL 0 0 1\n"
            . "0x0020 SPACE\n"
            . "0x0041..0x0042 ALPHA SYMBOL\n"
            . "0x0021 SYMBOL\n",
        );

        (new CharCategoryBuilder(new MappingCategoryIdResolver([
            'DEFAULT' => 20,
            'SPACE' => 21,
            'ALPHA' => 22,
            'SYMBOL' => 23,
        ])))->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $category = FileBinaryDictionaryLoader::forFileStorage($outputDirectory)->loadCharCategory();
        $default = $category->category(0x0000);
        $space = $category->category(0x0020);
        $alpha = $category->category(0x0041);
        $symbol = $category->category(0x0021);

        $this->assertSame(20, $default->id);
        $this->assertSame(1, $default->length);
        $this->assertTrue($default->invoke);
        $this->assertFalse($default->group);
        $this->assertSame(21, $space->id);
        $this->assertSame(2, $space->length);
        $this->assertFalse($space->invoke);
        $this->assertTrue($space->group);
        $this->assertSame(22, $alpha->id);
        $this->assertSame(4, $alpha->length);
        $this->assertSame(23, $symbol->id);
        $this->assertTrue($category->isCompatible(0x0041, 0x0042));
        $this->assertTrue($category->isCompatible(0x0041, 0x0021));
        $this->assertFalse($category->isCompatible(0x0041, 0x0020));
    }

    /**
     * 出力ディレクトリが未作成でも build 呼び出し時に作成し、コンストラクタでは I/O しないことを確認する。
     */
    public function testBuildCreatesOutputDirectoryOnlyWhenBuildIsCalled(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-char-in-');
        $outputDirectory = $this->createMissingTemporaryDirectory('igo-char-out-');
        $this->writeTextFile($inputDirectory . '/char.def', "DEFAULT 1 0 1\nSPACE 0 1 2\n0x0020 SPACE\n");

        $builder = new CharCategoryBuilder(new MappingCategoryIdResolver(['DEFAULT' => 0, 'SPACE' => 1]));
        $this->assertDirectoryDoesNotExist($outputDirectory);

        $builder->build($outputDirectory, $inputDirectory, 'UTF-8', ',');

        $this->assertDirectoryExists($outputDirectory);
        $this->assertFileExists($outputDirectory . '/char.category');
        $this->assertFileExists($outputDirectory . '/code2category');
    }

    /**
     * SPACE カテゴリが 0x0020 に割り当てられていない char.def を parse error として扱うことを確認する。
     */
    public function testBuildFailsWhenSpaceCodeIsNotAssignedToSpaceCategory(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-char-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-char-out-');
        $this->writeTextFile($inputDirectory . '/char.def', "DEFAULT 1 0 1\nSPACE 0 1 2\n0x0021 SPACE\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('char.def must assign 0x0020 to SPACE.');

        (new CharCategoryBuilder(new MappingCategoryIdResolver(['DEFAULT' => 0, 'SPACE' => 1])))->build(
            $outputDirectory,
            $inputDirectory,
            'UTF-8',
            ',',
        );
    }

    /**
     * 0x0020 の主カテゴリが SPACE でない char.def を、互換カテゴリに SPACE が含まれていても拒否する。
     */
    public function testBuildFailsWhenSpaceCodeUsesSpaceOnlyAsCompatibleCategory(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-char-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-char-out-');
        $this->writeTextFile($inputDirectory . '/char.def', "DEFAULT 1 0 1\nSPACE 0 1 2\n0x0020 DEFAULT SPACE\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('char.def must assign 0x0020 to SPACE.');

        (new CharCategoryBuilder(new MappingCategoryIdResolver(['DEFAULT' => 0, 'SPACE' => 1])))->build(
            $outputDirectory,
            $inputDirectory,
            'UTF-8',
            ',',
        );
    }

    /**
     * DEFAULT と SPACE の必須カテゴリがない char.def を parse error として扱うことを確認する。
     *
     * @dataProvider requiredCategoryDefinitionProvider
     *
     * @param array<string, int> $resolverIds
     */
    public function testBuildFailsWhenRequiredCategoryIsMissing(
        string $definition,
        array $resolverIds,
        string $message,
    ): void {
        $inputDirectory = $this->createTemporaryDirectory('igo-char-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-char-out-');
        $this->writeTextFile($inputDirectory . '/char.def', $definition);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new CharCategoryBuilder(new MappingCategoryIdResolver($resolverIds)))->build(
            $outputDirectory,
            $inputDirectory,
            'UTF-8',
            ',',
        );
    }

    /**
     * char.category に保存する未知語 trie ID は非負でなければならないため、resolver の不正値を拒否する。
     */
    public function testBuildFailsWhenResolvedCategoryIdIsNegative(): void
    {
        $inputDirectory = $this->createTemporaryDirectory('igo-char-in-');
        $outputDirectory = $this->createTemporaryDirectory('igo-char-out-');
        $this->writeTextFile($inputDirectory . '/char.def', "DEFAULT 1 0 1\nSPACE 0 1 2\n0x0020 SPACE\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('category id for DEFAULT must be non-negative.');

        (new CharCategoryBuilder(new MappingCategoryIdResolver(['DEFAULT' => -1, 'SPACE' => 1])))->build(
            $outputDirectory,
            $inputDirectory,
            'UTF-8',
            ',',
        );
    }

    /**
     * 必須カテゴリ欠落の代表的な char.def と期待するエラーメッセージを返す。
     *
     * @return array<string, array{0:string, 1:array<string, int>, 2:string}>
     */
    public function requiredCategoryDefinitionProvider(): array
    {
        return [
            'missing default' => [
                "SPACE 0 1 2\n0x0020 SPACE\n",
                ['SPACE' => 1],
                'char.def must define DEFAULT category.',
            ],
            'missing space' => [
                "DEFAULT 1 0 1\n0x0020 DEFAULT\n",
                ['DEFAULT' => 0],
                'char.def must define SPACE category.',
            ],
        ];
    }

    /**
     * 指定 prefix の一時ディレクトリを作成し、後片付け対象として記録する。
     */
    private function createTemporaryDirectory(string $prefix): string
    {
        $baseName = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertIsString($baseName);
        unlink($baseName);
        mkdir($baseName);
        $this->temporaryDirectories[] = $baseName;

        return $baseName;
    }

    /**
     * 存在しない一時ディレクトリパスを確保し、build が作成する対象として記録する。
     */
    private function createMissingTemporaryDirectory(string $prefix): string
    {
        $baseName = $this->createTemporaryDirectory($prefix);
        rmdir($baseName);

        return $baseName;
    }

    /**
     * テスト入力ファイルを書き込み、期待バイト数が保存されたことを確認する。
     */
    private function writeTextFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);
        $this->assertSame(strlen($contents), $writtenBytes);
    }
}

/**
 * テスト用の固定対応表からカテゴリ名に対応する trie ID を返す。
 */
class MappingCategoryIdResolver implements CategoryIdResolver
{
    /**
     * カテゴリ名から返す ID の対応表を保持する。
     *
     * @param array<string, int> $ids
     */
    public function __construct(
        private array $ids,
    ) {}

    /**
     * CharCategoryBuilder から渡されたカテゴリ名を固定対応表で解決する。
     */
    public function resolve(string $outputDirectory, string $encoding, string $categoryName): int
    {
        return $this->ids[$categoryName];
    }
}
