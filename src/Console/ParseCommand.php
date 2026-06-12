<?php

declare(strict_types=1);

namespace IgoModern\Console;

use IgoModern\Igo;
use IgoModern\Parser;
use IgoModern\Storage\File\PagedBinaryReader;
use IgoModern\Storage\FileStorage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 旧 CLI の辞書指定・入力読み込み・解析結果出力を Symfony Console コマンドとして提供する。
 */
class ParseCommand extends Command
{
    /** コマンド名を Symfony Console アプリケーションへ提供する。 */
    protected static $defaultName = 'parse';

    /** @var callable(string, ?string, ?string, ?int): Parser 解析器を遅延生成するファクトリを保持する。 */
    private $parserFactory;

    /**
     * 解析器ファクトリを必須依存として受け取り、解析器生成の差し替えを明示する。
     *
     * @param callable(string, ?string, ?string, ?int): Parser $parserFactory
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
        return new self(static fn(
            string $dataDir,
            ?string $outputEncoding,
            ?string $inputEncoding,
            ?int $maxCachedPages,
        ): Parser => Igo::fromStorage(
            FileStorage::fromDataDir($dataDir, $maxCachedPages),
            $outputEncoding,
            $inputEncoding,
        ));
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
            ->addOption('encoding', 'e', InputOption::VALUE_REQUIRED, 'Output encoding.')
            ->addOption(
                'input-encoding',
                null,
                InputOption::VALUE_REQUIRED,
                'Fixed input encoding (skips auto-detection).',
            )
            ->addOption(
                'page-cache',
                null,
                InputOption::VALUE_REQUIRED,
                'Max cached pages for file storage (positive integer, memory-saving: 32). Default: '
                . PagedBinaryReader::DEFAULT_MAX_CACHED_PAGES
                . '.',
            );
    }

    /**
     * 入力を解析器へ渡し、旧 CLI と同じ surface<TAB>feature,start 形式で出力する。
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $dataDir = $this->requiredStringOption($input, 'dictionary');

            if (!is_dir($dataDir)) {
                $output->writeln('dictionary not found.');

                return Command::FAILURE;
            }

            $text = $this->textFromInput($input, $output);

            if ($text === null) {
                return Command::FAILURE;
            }

            $parser = ($this->parserFactory)(
                $dataDir,
                $this->outputEncoding($input),
                $this->inputEncoding($input),
                $this->pageCacheOption($input),
            );

            foreach ($parser->parse($text) as $morpheme) {
                $output->writeln($morpheme->surface . "\t" . $morpheme->feature . ',' . $morpheme->start);
            }

            return Command::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->errorOutput($output)->writeln($exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * ConsoleOutputInterface では stderr を優先し、テスト用 output では同じ出力先へエラーを書けるようにする。
     */
    private function errorOutput(OutputInterface $output): OutputInterface
    {
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }

        return $output;
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
     * CLI オプションで明示された出力エンコーディングだけを任意設定として取り出す。
     */
    private function outputEncoding(InputInterface $input): ?string
    {
        $encoding = $input->getOption('encoding');

        if (is_string($encoding) && $encoding !== '') {
            return $encoding;
        }

        return null;
    }

    /**
     * CLI オプションで明示された入力エンコーディング固定値を任意設定として取り出す。
     */
    private function inputEncoding(InputInterface $input): ?string
    {
        $encoding = $input->getOption('input-encoding');

        if (is_string($encoding) && $encoding !== '') {
            return $encoding;
        }

        return null;
    }

    /**
     * --page-cache オプションで明示されたキャッシュ上限ページ数を検証して返す。
     *
     * 未指定なら null を返し PagedBinaryReader の既定値を使う。1 未満が指定された場合は早期エラーにする。
     */
    private function pageCacheOption(InputInterface $input): ?int
    {
        $value = $this->stringOption($input, 'page-cache');

        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('--page-cache must be a positive integer.');
        }

        return (int) $value;
    }
}
