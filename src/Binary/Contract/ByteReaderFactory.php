<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/** 指定ファイルを開き、ランダムアクセス可能な ByteReader を生成する契約。 */
interface ByteReaderFactory
{
    /** 指定ファイルを開き、ランダムアクセス可能な ByteReader を返す。 */
    public function open(string $fileName): ByteReader;
}
