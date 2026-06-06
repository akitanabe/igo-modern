<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Binary\Contract\ByteReader;
use IgoModern\Binary\Contract\ByteReaderFactory;
use RuntimeException;

/**
 * 指定ファイルを開き、ページ読み込みの ByteReader を生成するファクトリ。
 */
final class PagedByteReaderFactory implements ByteReaderFactory
{
    /**
     * 指定ファイルを開き、ページ読み込み reader を返す。
     */
    public function open(string $fileName): ByteReader
    {
        $file = fopen($fileName, 'rb');

        if ($file === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return new PagedBinaryReader($file);
    }
}
