<?php

declare(strict_types=1);

namespace IgoModern\Tests\Console;

use IgoModern\Console\BuildDicCommand;
use IgoModern\Dictionary\Build\DictionaryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * BuildDicCommand が CLI オプションを解釈し、辞書生成処理を注入された builder へ委譲することを検証するテスト。
 */
class BuildDicCommandTest extends TestCase
{
    /**
     * DictionaryBuilder factory は必須依存として扱い、null による標準 builder 生成を constructor に隠さないことを確認する。
     */
    public function testConstructorRequiresBuilderFactory(): void
    {
        $constructor = (new ReflectionClass(BuildDicCommand::class))->getConstructor();
        $this->assertNotNull($constructor);

        $builderFactory = $constructor->getParameters()[0];

        $this->assertSame('builderFactory', $builderFactory->getName());
        $this->assertFalse($builderFactory->allowsNull());
        $this->assertFalse($builderFactory->isDefaultValueAvailable());
    }

    /**
     * 通常利用向けの標準 DictionaryBuilder factory は factory メソッドから組み立てられることを確認する。
     */
    public function testCreateDefaultReturnsBuildDicCommand(): void
    {
        $command = BuildDicCommand::createDefault();

        $this->assertInstanceOf(BuildDicCommand::class, $command);
        $this->assertSame('build-dic', $command->getName());
    }

    /**
     * createDefault() が生成する DictionaryBuilder factory が FileTrieLoader を注入することを確認する。
     *
     * factory を実行することで FileTrieLoader::forBuild() を内包する標準構成が組み立てられ、
     * Storage 具象の注入漏れが CLI 経路で検知できる。
     */
    public function testCreateDefaultInjectsFileTrieLoader(): void
    {
        $command = BuildDicCommand::createDefault();
        $factoryProperty = (new ReflectionClass(BuildDicCommand::class))->getProperty('builderFactory');
        $factoryProperty->setAccessible(true);

        $builder = $factoryProperty->getValue($command)();

        $this->assertInstanceOf(DictionaryBuilder::class, $builder);
    }

    /**
     * 辞書生成に必要な値を、位置引数ではなく短縮名付きオプションとして発見できることを確認する。
     */
    public function testConfigureDefinesShortOptionsForBuildInputs(): void
    {
        $definition = (new BuildDicCommand(static fn(): DictionaryBuilder => new RecordingDictionaryBuilder(
            new DictionaryBuilderCallLog(),
        )))->getDefinition();

        $this->assertFalse($definition->hasArgument('output-directory'));
        $this->assertFalse($definition->hasArgument('input-directory'));
        $this->assertFalse($definition->hasArgument('encoding'));
        $this->assertFalse($definition->hasArgument('delimiter'));
        $this->assertSame('o', $definition->getOption('output')->getShortcut());
        $this->assertSame('i', $definition->getOption('input')->getShortcut());
        $this->assertSame('e', $definition->getOption('encoding')->getShortcut());
        $this->assertSame('d', $definition->getOption('delimiter')->getShortcut());
    }

    /**
     * 必須オプションと任意 delimiter を builder に渡し、成功終了することを確認する。
     */
    public function testExecuteDelegatesArgumentsToBuilder(): void
    {
        $calls = new DictionaryBuilderCallLog();
        $command = new BuildDicCommand(static fn(): DictionaryBuilder => new RecordingDictionaryBuilder($calls));

        $tester = new CommandTester($command);
        $statusCode = $tester->execute([
            '-o' => '/tmp/igo-out',
            '-i' => '/tmp/igo-in',
            '-e' => 'UTF-8',
            '-d' => '|',
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertSame(
            [
                ['output' => '/tmp/igo-out', 'input' => '/tmp/igo-in', 'encoding' => 'UTF-8', 'delimiter' => '|'],
            ],
            $calls->all(),
        );
        $this->assertSame("dictionary built.\n", $tester->getDisplay());
    }

    /**
     * delimiter 未指定時は Java 版と同じカンマを builder に渡すことを確認する。
     */
    public function testExecuteUsesCommaAsDefaultDelimiter(): void
    {
        $calls = new DictionaryBuilderCallLog();
        $command = new BuildDicCommand(static fn(): DictionaryBuilder => new RecordingDictionaryBuilder($calls));

        $tester = new CommandTester($command);
        $statusCode = $tester->execute([
            '--output' => '/tmp/igo-out',
            '--input' => '/tmp/igo-in',
            '--encoding' => 'UTF-8',
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertSame(',', $calls->all()[0]['delimiter']);
    }

    /**
     * CSV parser が扱えない複数文字 delimiter は、builder 実行前に CLI 引数エラーとして拒否する。
     */
    public function testExecuteFailsWhenDelimiterHasMultipleCharacters(): void
    {
        $calls = new DictionaryBuilderCallLog();
        $command = new BuildDicCommand(static fn(): DictionaryBuilder => new RecordingDictionaryBuilder($calls));

        $tester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('option "--delimiter" must be a single-character string.');

        $tester->execute([
            '--output' => '/tmp/igo-out',
            '--input' => '/tmp/igo-in',
            '--encoding' => 'UTF-8',
            '--delimiter' => '||',
        ]);
    }

    /**
     * 必須オプションが欠けた場合は、builder 実行前に CLI 入力エラーとして拒否する。
     */
    public function testExecuteFailsWhenRequiredOptionIsMissing(): void
    {
        $calls = new DictionaryBuilderCallLog();
        $command = new BuildDicCommand(static fn(): DictionaryBuilder => new RecordingDictionaryBuilder($calls));

        $tester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('option "--input" must be a non-empty string.');

        $tester->execute([
            '--output' => '/tmp/igo-out',
            '--encoding' => 'UTF-8',
        ]);
    }
}

/**
 * BuildDicCommand からの委譲内容を型付きで蓄積し、テストから読み出せるようにする。
 */
class DictionaryBuilderCallLog
{
    /** @var list<array{output:string, input:string, encoding:string, delimiter:string}> 辞書生成要求の履歴を保持する。 */
    private array $calls = [];

    /**
     * Console コマンドから渡された生成要求を履歴へ追加する。
     *
     * @param array{output:string, input:string, encoding:string, delimiter:string} $call
     */
    public function add(array $call): void
    {
        $this->calls[] = $call;
    }

    /**
     * テスト検証のため、記録済みの生成要求を返す。
     *
     * @return list<array{output:string, input:string, encoding:string, delimiter:string}>
     */
    public function all(): array
    {
        return $this->calls;
    }
}

/**
 * BuildDicCommand から受け取った生成要求を記録する DictionaryBuilder。
 */
class RecordingDictionaryBuilder extends DictionaryBuilder
{
    /**
     * 記録先ログを保持し、テストから委譲内容を観測できるようにする。
     */
    public function __construct(
        private DictionaryBuilderCallLog $calls,
    ) {}

    /**
     * Console コマンドから渡された生成要求を外部配列へ記録する。
     */
    public function build(
        string $outputDirectory,
        string $inputDirectory,
        string $encoding,
        string $delimiter = ',',
    ): void {
        $this->calls->add([
            'output' => $outputDirectory,
            'input' => $inputDirectory,
            'encoding' => $encoding,
            'delimiter' => $delimiter,
        ]);
    }
}
