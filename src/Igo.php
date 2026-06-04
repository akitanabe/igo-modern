<?php

declare(strict_types=1);

namespace IgoModern;

use IgoModern\Analysis\Tagger;

/**
 * 利用者向けの公開 API として Tagger を保持し、形態素解析と分かち書きを提供する。
 */
class Igo implements Parser
{
    /**
     * 事前に構築された Tagger を公開 API の実体として保持する。
     */
    public function __construct(
        private Tagger $tagger,
    ) {}

    /**
     * 辞書ディレクトリと出力エンコーディングから公開 API を構築する。
     */
    public static function fromDataDir(string $dataDir, ?string $outputEncoding = null): self
    {
        return new self(Tagger::fromDataDir($dataDir, $outputEncoding));
    }

    /**
     * 入力テキストを解析し、形態素列を返す。
     *
     * @return list<Morpheme>
     */
    public function parse(string $text): array
    {
        return $this->tagger->parse($text);
    }

    /**
     * 入力テキストを解析し、形態素の表層形だけを順序どおり返す。
     *
     * @return list<string>
     */
    public function wakati(string $text): array
    {
        return $this->tagger->wakati($text);
    }
}
