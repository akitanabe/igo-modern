<?php

declare(strict_types=1);

namespace IgoModern\Storage\File;

use IgoModern\Binary\Contract\ByteReader;
use IgoModern\Binary\Contract\ByteReaderFactory;
use RuntimeException;

/**
 * 指定ファイルを開き、ページ読み込みの ByteReader を生成するファクトリ。
 */
final class PagedByteReaderFactory implements ByteReaderFactory
{
    /**
     * キャッシュ上限ページ数を保持する。null のとき PagedBinaryReader の既定値を使う。
     */
    private ?int $maxCachedPages;

    /**
     * キャッシュ上限ページ数を設定する。null なら PagedBinaryReader の既定値を使う。
     */
    public function __construct(?int $maxCachedPages = null)
    {
        $this->maxCachedPages = $maxCachedPages;
    }

    /**
     * 指定ファイルを開き、ページ読み込み reader を返す。
     *
     * maxCachedPages が指定されている場合はその値を PagedBinaryReader へ渡し、
     * null の場合は PagedBinaryReader の既定値（DEFAULT_MAX_CACHED_PAGES）を使う。
     */
    public function open(string $fileName): ByteReader
    {
        $file = fopen($fileName, mode: 'rb');

        if ($file === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        if ($this->maxCachedPages !== null) {
            return new PagedBinaryReader($file, maxCachedPages: $this->maxCachedPages);
        }

        return new PagedBinaryReader($file);
    }
}
