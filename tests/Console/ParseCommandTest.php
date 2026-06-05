<?php

declare(strict_types=1);

namespace IgoModern\Tests\Console;

use IgoModern\Console\ParseCommand;
use IgoModern\Morpheme;
use IgoModern\Parser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ParseCommand が CLI 入出力を担当し、解析処理を注入された Parser へ委譲することを検証するテスト。
 */
class ParseCommandTest extends TestCase
{
    /** 環境変数の変更前状態を保持し、テスト間の副作用を避ける。 */
    private false|string $originalOutputEncoding;

    /**
     * Parser factory は必須依存として扱い、null による標準 Parser 生成を constructor に隠さないことを確認する。
     */
    public function testConstructorRequiresParserFactory(): void
    {
        $constructor = (new ReflectionClass(ParseCommand::class))->getConstructor();
        $this->assertNotNull($constructor);

        $parserFactory = $constructor->getParameters()[0];

        $this->assertSame('parserFactory', $parserFactory->getName());
        $this->assertFalse($parserFactory->allowsNull());
        $this->assertFalse($parserFactory->isDefaultValueAvailable());
    }

    /**
     * 通常利用向けの標準 Parser factory は factory メソッドから組み立てられることを確認する。
     */
    public function testCreateDefaultReturnsParseCommand(): void
    {
        $command = ParseCommand::createDefault();

        $this->assertInstanceOf(ParseCommand::class, $command);
        $this->assertSame('parse', $command->getName());
    }

    /**
     * CLI 利用者が位置引数に頼らず、必須入力を短縮名付きオプションで発見できることを確認する。
     */
    public function testConfigureDefinesShortOptionsForRequiredInputs(): void
    {
        $definition = (new ParseCommand(static fn(): Parser => new StubParser([])))->getDefinition();

        $this->assertFalse($definition->hasArgument('dictionary'));
        $this->assertFalse($definition->hasArgument('text'));
        $this->assertSame('d', $definition->getOption('dictionary')->getShortcut());
        $this->assertSame('i', $definition->getOption('input')->getShortcut());
        $this->assertSame('f', $definition->getOption('file')->getShortcut());
        $this->assertSame('e', $definition->getOption('encoding')->getShortcut());
    }

    /**
     * 旧 CLI 互換の出力エンコーディング環境変数を退避し、各テストを独立させる。
     */
    protected function setUp(): void
    {
        $this->originalOutputEncoding = getenv('IGO_OUTPUT_ENCODING');
        putenv('IGO_OUTPUT_ENCODING');
    }

    /**
     * 退避した環境変数を復元し、後続テストや実行環境への影響を残さない。
     */
    protected function tearDown(): void
    {
        if ($this->originalOutputEncoding === false) {
            putenv('IGO_OUTPUT_ENCODING');

            return;
        }

        putenv('IGO_OUTPUT_ENCODING=' . $this->originalOutputEncoding);
    }

    /**
     * 引数で受け取ったテキストを解析し、旧 CLI と同じタブ区切り形式で出力することを確認する。
     */
    public function testExecuteParsesInlineTextAndWritesLegacyFormat(): void
    {
        $created = [];
        $command = new ParseCommand(static function (string $dataDir, ?string $outputEncoding) use (&$created): Parser {
            $created[] = [$dataDir, $outputEncoding];

            return new StubParser([
                new Morpheme('表層', '名詞,一般', 0),
                new Morpheme('X', '記号', 2),
            ]);
        });

        $tester = new CommandTester($command);
        $statusCode = $tester->execute([
            '-d' => __DIR__,
            '-i' => 'dummy',
            '-e' => 'UTF-8',
        ]);

        $this->assertSame(0, $statusCode);
        $this->assertSame([[__DIR__, 'UTF-8']], $created);
        $this->assertSame("表層\t名詞,一般,0\nX\t記号,2\n", $tester->getDisplay());
    }

    /**
     * 第 2 引数がファイルパスの場合は内容を読み込み、環境変数の出力エンコーディングを使うことを確認する。
     */
    public function testExecuteReadsTextFileAndUsesEnvironmentEncoding(): void
    {
        putenv('IGO_OUTPUT_ENCODING=SJIS');
        $textFile = tempnam(sys_get_temp_dir(), 'igo-console-');
        $this->assertIsString($textFile);
        $this->assertSame(5, file_put_contents($textFile, 'hello'));

        $parser = new RecordingParser([
            new Morpheme('hello', 'feature', 0),
        ]);
        $command = new ParseCommand(function (string $dataDir, ?string $outputEncoding) use ($parser): Parser {
            $this->assertSame('SJIS', $outputEncoding);

            return $parser;
        });

        try {
            $tester = new CommandTester($command);
            $statusCode = $tester->execute([
                '--dictionary' => __DIR__,
                '--file' => $textFile,
            ]);
        } finally {
            unlink($textFile);
        }

        $this->assertSame(0, $statusCode);
        $this->assertSame(['hello'], $parser->parsedTexts());
        $this->assertSame("hello\tfeature,0\n", $tester->getDisplay());
    }

    /**
     * 存在しない辞書ディレクトリでは解析器を作らず、失敗終了することを確認する。
     */
    public function testExecuteFailsWhenDictionaryDirectoryDoesNotExist(): void
    {
        $command = new ParseCommand(function (): Parser {
            $this->fail('辞書ディレクトリ検証に失敗した場合、Parser は作成されないべきです。');
        });

        $tester = new CommandTester($command);
        $statusCode = $tester->execute([
            '--dictionary' => __DIR__ . '/missing-dictionary',
            '--input' => 'dummy',
        ]);

        $this->assertSame(1, $statusCode);
        $this->assertSame("dictionary not found.\n", $tester->getDisplay());
    }

    /**
     * 解析対象はインライン入力かファイルのどちらか一方だけを指定できることを確認する。
     */
    public function testExecuteFailsWhenInputAndFileAreBothSpecified(): void
    {
        $command = new ParseCommand(function (): Parser {
            $this->fail('入力指定が矛盾している場合、Parser は作成されないべきです。');
        });

        $tester = new CommandTester($command);
        $statusCode = $tester->execute([
            '--dictionary' => __DIR__,
            '--input' => 'dummy',
            '--file' => __FILE__,
        ]);

        $this->assertSame(1, $statusCode);
        $this->assertSame("--input and --file cannot be used together.\n", $tester->getDisplay());
    }

    /**
     * 解析対象が明示されない場合は、空解析ではなく CLI 入力エラーとして失敗することを確認する。
     */
    public function testExecuteFailsWhenInputSourceIsMissing(): void
    {
        $command = new ParseCommand(function (): Parser {
            $this->fail('解析対象がない場合、Parser は作成されないべきです。');
        });

        $tester = new CommandTester($command);
        $statusCode = $tester->execute([
            '--dictionary' => __DIR__,
        ]);

        $this->assertSame(1, $statusCode);
        $this->assertSame("either --input or --file must be specified.\n", $tester->getDisplay());
    }
}

/**
 * 固定の形態素列を返し、Console コマンドの出力整形だけを検証できる Parser。
 */
class StubParser implements Parser
{
    /**
     * 返却する形態素列を保持する。
     *
     * @param list<Morpheme> $morphemes
     */
    public function __construct(
        private array $morphemes,
    ) {}

    /**
     * 入力内容に依存せず、テストで指定された形態素列を返す。
     *
     * @return list<Morpheme>
     */
    public function parse(string $text): array
    {
        return $this->morphemes;
    }
}

/**
 * 受け取った入力文字列を記録しつつ、固定の形態素列を返す Parser。
 */
class RecordingParser implements Parser
{
    /** @var list<string> Console コマンドから受け取った入力文字列を保持する。 */
    private array $parsedTexts = [];

    /**
     * 返却する形態素列を保持する。
     *
     * @param list<Morpheme> $morphemes
     */
    public function __construct(
        private array $morphemes,
    ) {}

    /**
     * Console コマンドから渡された解析対象テキストを記録してから結果を返す。
     *
     * @return list<Morpheme>
     */
    public function parse(string $text): array
    {
        $this->parsedTexts[] = $text;

        return $this->morphemes;
    }

    /**
     * Console コマンドが Parser へ渡した解析対象テキストの履歴を返す。
     *
     * @return list<string>
     */
    public function parsedTexts(): array
    {
        return $this->parsedTexts;
    }
}
