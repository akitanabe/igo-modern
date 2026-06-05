<?php

declare(strict_types=1);

namespace IgoModern\Console;

use IgoModern\Igo;
use IgoModern\Parser;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 旧 CLI の辞書指定・入力読み込み・解析結果出力を Symfony Console コマンドとして提供する。
 */
class ParseCommand extends Command
{
    /** コマンド名を Symfony Console アプリケーションへ提供する。 */
    protected static $defaultName = 'parse';

    /** @var callable(string, ?string): Parser 解析器を遅延生成するファクトリを保持する。 */
    private $parserFactory;

    /**
     * 解析器ファクトリを必須依存として受け取り、解析器生成の差し替えを明示する。
     *
     * @param callable(string, ?string): Parser $parserFactory
     */
    public function __construct(callable $parserFactory)
    {
        parent::__construct();

        $this->parserFactory = $parserFactory;
    }

    /**
     * 通常利用向けに Igo を生成する標準 Parser factory を注入したコマンドを組み立てる。
     */
    public static function createDefault(): self
    {
        return new self(
            static fn(string $dataDir, ?string $outputEncoding): Parser => Igo::fromDataDir($dataDir, $outputEncoding),
        );
    }

    /**
     * 辞書ディレクトリ、解析対象、出力エンコーディングオプションを定義する。
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Parse text with an Igo dictionary.')
            ->addOption('dictionary', 'd', InputOption::VALUE_REQUIRED, 'Dictionary directory.')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Inline text to parse.')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Text file to parse.')
            ->addOption('encoding', 'e', InputOption::VALUE_REQUIRED, 'Output encoding.');
    }

    /**
     * 入力を解析器へ渡し、旧 CLI と同じ surface<TAB>feature,start 形式で出力する。
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dataDir = $this->requiredStringOption($input, 'dictionary');

        if (!is_dir($dataDir)) {
            $output->writeln('dictionary not found.');

            return Command::FAILURE;
        }

        $text = $this->textFromInput($input, $output);

        if ($text === null) {
            return Command::FAILURE;
        }

        $parser = ($this->parserFactory)($dataDir, $this->outputEncoding($input));

        foreach ($parser->parse($text) as $morpheme) {
            $output->writeln($morpheme->surface . "\t" . $morpheme->feature . ',' . $morpheme->start);
        }

        return Command::SUCCESS;
    }

    /**
     * Symfony Console の mixed な必須オプション値を、空でない文字列として取り出す。
     */
    private function requiredStringOption(InputInterface $input, string $name): string
    {
        $value = $this->stringOption($input, $name);

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('option "--%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    /**
     * インライン入力とファイル入力の排他を検証し、解析対象テキストを取得する。
     */
    private function textFromInput(InputInterface $input, OutputInterface $output): ?string
    {
        $inlineText = $this->stringOption($input, 'input');
        $fileName = $this->stringOption($input, 'file');

        if (($inlineText === null || $inlineText === '') && ($fileName === null || $fileName === '')) {
            $output->writeln('either --input or --file must be specified.');

            return null;
        }

        if ($inlineText !== null && $inlineText !== '' && $fileName !== null && $fileName !== '') {
            $output->writeln('--input and --file cannot be used together.');

            return null;
        }

        if ($fileName === null || $fileName === '') {
            return $inlineText;
        }

        return $this->readTextFile($fileName);
    }

    /**
     * ファイル入力オプションで指定された解析対象テキストを読み込む。
     */
    private function readTextFile(string $fileName): string
    {
        $contents = file_get_contents($fileName);

        if ($contents === false) {
            throw new RuntimeException(sprintf('failed to read input file "%s".', $fileName));
        }

        return $contents;
    }

    /**
     * Symfony Console の mixed なオプション値を、任意文字列として取り出す。
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('option "--%s" must be a string.', $name));
        }

        return $value;
    }

    /**
     * CLI オプションを優先し、未指定時は旧 CLI 互換の環境変数から出力エンコーディングを決める。
     */
    private function outputEncoding(InputInterface $input): ?string
    {
        $encoding = $input->getOption('encoding');

        if (is_string($encoding) && $encoding !== '') {
            return $encoding;
        }

        $environmentEncoding = getenv('IGO_OUTPUT_ENCODING');

        return $environmentEncoding === false || $environmentEncoding === '' ? null : $environmentEncoding;
    }
}
