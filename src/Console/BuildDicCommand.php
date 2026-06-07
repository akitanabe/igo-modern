<?php

declare(strict_types=1);

namespace IgoModern\Console;

use IgoModern\Dictionary\Build\DictionaryBuilder;
use IgoModern\Storage\FileTrieLoader;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MeCab 互換辞書から Igo 形式辞書を生成する CLI コマンドを提供する。
 */
class BuildDicCommand extends Command
{
    /** コマンド名を Symfony Console アプリケーションへ提供する。 */
    protected static $defaultName = 'build-dic';

    /** @var callable(): DictionaryBuilder 辞書生成器を遅延生成するファクトリを保持する。 */
    private $builderFactory;

    /**
     * 辞書生成器ファクトリを必須依存として受け取り、標準構成とテスト差し替えを明示する。
     *
     * @param callable(): DictionaryBuilder $builderFactory
     */
    public function __construct(callable $builderFactory)
    {
        parent::__construct();

        $this->builderFactory = $builderFactory;
    }

    /**
     * 通常利用向けに標準 DictionaryBuilder factory を注入したコマンドを組み立てる。
     */
    public static function createDefault(): self
    {
        return new self(static fn(): DictionaryBuilder => DictionaryBuilder::standard(FileTrieLoader::forBuild()));
    }

    /**
     * 辞書生成に必要な入力値を名前付き CLI オプションとして定義する。
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Build an Igo dictionary from a MeCab-compatible dictionary.')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for generated Igo dictionary files.',
            )
            ->addOption(
                'input',
                'i',
                InputOption::VALUE_REQUIRED,
                'Input directory containing MeCab-compatible dictionary files.',
            )
            ->addOption('encoding', 'e', InputOption::VALUE_REQUIRED, 'Encoding of input dictionary CSV files.')
            ->addOption(
                'delimiter',
                'd',
                InputOption::VALUE_REQUIRED,
                'CSV delimiter used by dictionary definition files.',
                ',',
            );
    }

    /**
     * CLI オプションを辞書生成器へ渡し、生成全体の副作用を builder 側へ閉じ込める。
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $builder = ($this->builderFactory)();
        $builder->build(
            $this->requiredStringOption($input, 'output'),
            $this->requiredStringOption($input, 'input'),
            $this->requiredStringOption($input, 'encoding'),
            $this->delimiterOption($input),
        );

        $output->writeln('dictionary built.');

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
     * PHP の CSV parser に渡せる 1 文字 delimiter だけを CLI オプションとして受け入れる。
     */
    private function delimiterOption(InputInterface $input): string
    {
        $delimiter = $this->requiredStringOption($input, 'delimiter');

        if (strlen($delimiter) !== 1) {
            throw new RuntimeException('option "--delimiter" must be a single-character string.');
        }

        return $delimiter;
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
}
