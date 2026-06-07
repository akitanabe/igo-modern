<?php

declare(strict_types=1);

namespace IgoModern\Binary\Contract;

/** 指定 offset から指定長のバイト列を読み出す契約。 */
interface ByteReader
{
    /** 指定された byte offset から byte length 分のバイト列を返す。 */
    public function readBytes(int $byteOffset, int $byteLength): string;
}
