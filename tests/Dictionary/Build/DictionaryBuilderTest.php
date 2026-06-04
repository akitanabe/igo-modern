<?php

declare(strict_types=1);

namespace IgoModern\Tests\Dictionary\Build;

use IgoModern\Dictionary\Build\DictionaryBuilder;
use IgoModern\Dictionary\Build\DictionaryBuildStep;
use PHPUnit\Framework\TestCase;

/**
 * DictionaryBuilder が辞書生成全体の順序制御だけを担当することを検証するテスト。
 */
class DictionaryBuilderTest extends TestCase
{
    /**
     * build が word、matrix、char の順で各生成ステップへ同じ入力値を渡すことを確認する。
     */
    public function testBuildDelegatesToStepsInDictionaryGenerationOrder(): void
    {
        $calls = new BuildStepCallLog();
        $builder = new DictionaryBuilder(
            new RecordingBuildStep('word', $calls),
            new RecordingBuildStep('matrix', $calls),
            new RecordingBuildStep('char', $calls),
        );

        $this->assertSame([], $calls->all());

        $builder->build('/tmp/out', '/tmp/in', 'EUC-JP', '|');

        $this->assertSame(
            [
                [
                    'step' => 'word',
                    'output' => '/tmp/out',
                    'input' => '/tmp/in',
                    'encoding' => 'EUC-JP',
                    'delimiter' => '|',
                ],
                [
                    'step' => 'matrix',
                    'output' => '/tmp/out',
                    'input' => '/tmp/in',
                    'encoding' => 'EUC-JP',
                    'delimiter' => '|',
                ],
                [
                    'step' => 'char',
                    'output' => '/tmp/out',
                    'input' => '/tmp/in',
                    'encoding' => 'EUC-JP',
                    'delimiter' => '|',
                ],
            ],
            $calls->all(),
        );
    }
}

/**
 * 生成ステップからの呼び出し履歴を型付きで蓄積し、PHPStan にも読み取り用途を伝える。
 */
class BuildStepCallLog
{
    /** @var list<array{step:string, output:string, input:string, encoding:string, delimiter:string}> 生成ステップ呼び出しの履歴を保持する。 */
    private array $calls = [];

    /**
     * 生成ステップから渡された呼び出し内容を順序付きで追加する。
     *
     * @param array{step:string, output:string, input:string, encoding:string, delimiter:string} $call
     */
    public function add(array $call): void
    {
        $this->calls[] = $call;
    }

    /**
     * テスト検証のため、記録済みの呼び出し履歴を返す。
     *
     * @return list<array{step:string, output:string, input:string, encoding:string, delimiter:string}>
     */
    public function all(): array
    {
        return $this->calls;
    }
}

/**
 * 受け取った生成要求を共有配列へ記録し、委譲順序を検証できる生成ステップ。
 */
class RecordingBuildStep implements DictionaryBuildStep
{
    /**
     * 生成ステップ名と記録先を保持する。
     */
    public function __construct(
        private string $name,
        private BuildStepCallLog $calls,
    ) {}

    /**
     * DictionaryBuilder から渡された生成要求を順序付きで記録する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        $this->calls->add([
            'step' => $this->name,
            'output' => $outputDirectory,
            'input' => $inputDirectory,
            'encoding' => $encoding,
            'delimiter' => $delimiter,
        ]);
    }
}
