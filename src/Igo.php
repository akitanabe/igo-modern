<?php

declare(strict_types=1);

namespace IgoModern;

use IgoModern\Analysis\Tagger;

/**
 * 利用者向けの公開 API として Tagger を保持し、形態素解析と分かち書きを提供する。
 */
class Igo implements Parser
{
    /** 解析処理の実体である Tagger を保持する。 */
    private Tagger $tagger;

    /**
     * 辞書ディレクトリと出力エンコーディングから Tagger を初期化する。
     */
    public function __construct(string $dataDir, ?string $outputEncoding = null)
    {
        $this->tagger = new Tagger($dataDir, $outputEncoding);
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
