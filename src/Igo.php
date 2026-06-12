<?php

declare(strict_types=1);

namespace IgoModern;

use IgoModern\Analysis\Tagger;
use IgoModern\Storage\DictionaryStorage;
use Throwable;

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
     * 辞書ストレージ抽象から公開 API を構築する正式な拡張点。
     *
     * @param ?string $inputEncoding 入力エンコーディングを固定する場合に指定。null なら parse ごとに検出する。
     */
    public static function fromStorage(
        DictionaryStorage $storage,
        ?string $outputEncoding = null,
        ?string $inputEncoding = null,
    ): self {
        return new self(Tagger::fromStorage($storage, $outputEncoding, $inputEncoding));
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
     * 解析失敗時に例外を捕捉し、成功時だけ形態素列を返す。
     *
     * @return list<Morpheme>|null
     */
    public function tryParse(string $text): ?array
    {
        try {
            return $this->parse($text);
        } catch (Throwable) {
            return null;
        }
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

    /**
     * 分かち書き失敗時に例外を捕捉し、成功時だけ表層形リストを返す。
     *
     * @return list<string>|null
     */
    public function tryWakati(string $text): ?array
    {
        try {
            return $this->wakati($text);
        } catch (Throwable) {
            return null;
        }
    }
}
