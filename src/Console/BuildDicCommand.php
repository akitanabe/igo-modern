<?php

declare(strict_types=1);

namespace IgoModern\Console;

use IgoModern\Dictionary\Build\DictionaryBuilder;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
        return new self(static fn(): DictionaryBuilder => DictionaryBuilder::standard());
    }

    /**
     * Java 版 BuildDic と同じ位置引数を Symfony Console へ定義する。
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Build an Igo dictionary from a MeCab-compatible dictionary.')
            ->addArgument(
                'output-directory',
                InputArgument::REQUIRED,
                'Output directory for generated Igo dictionary files.',
            )
            ->addArgument(
                'input-directory',
                InputArgument::REQUIRED,
                'Input directory containing MeCab-compatible dictionary files.',
            )
            ->addArgument('encoding', InputArgument::REQUIRED, 'Encoding of input dictionary CSV files.')
            ->addArgument(
                'delimiter',
                InputArgument::OPTIONAL,
                'CSV delimiter used by dictionary definition files.',
                ',',
            );
    }

    /**
     * CLI 引数を辞書生成器へ渡し、生成全体の副作用を builder 側へ閉じ込める。
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $builder = ($this->builderFactory)();
        $builder->build(
            $this->stringArgument($input, 'output-directory'),
            $this->stringArgument($input, 'input-directory'),
            $this->stringArgument($input, 'encoding'),
            $this->delimiterArgument($input),
        );

        $output->writeln('dictionary built.');

        return Command::SUCCESS;
    }

    /**
     * Symfony Console の mixed な引数値を、必須文字列引数として取り出す。
     */
    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('argument "%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    /**
     * PHP の CSV parser に渡せる 1 文字 delimiter だけを CLI 引数として受け入れる。
     */
    private function delimiterArgument(InputInterface $input): string
    {
        $delimiter = $this->stringArgument($input, 'delimiter');

        if (strlen($delimiter) !== 1) {
            throw new RuntimeException('argument "delimiter" must be a single-character string.');
        }

        return $delimiter;
    }
}
