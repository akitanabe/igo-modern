<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/**
 * 辞書ファイルを開き、順次読み取り用の InputStream を生成する契約。
 *
 * 実体化方式（Lazy / Resident）は実装側に内包され、契約自体はポリシーを露出しない。
 */
interface InputStreamFactory
{
    /** 指定ファイルを開き、順次読み取り用の InputStream を返す。 */
    public function open(string $fileName): InputStream;
}
