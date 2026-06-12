<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Storage\Loader\FileBinaryDictionaryLoader;

/**
 * 辞書配列を遅延読み（DynamicArray）で実体化する、ファイル常駐を避ける storage。
 */
final class FileStorage extends BinaryStorage
{
    /**
     * 辞書ディレクトリから遅延読みの辞書一式を構築する。
     *
     * @param ?int $maxCachedPages ページキャッシュ上限数。null なら PagedBinaryReader の既定値を使う。
     */
    public static function fromDataDir(string $dir, ?int $maxCachedPages = null): self
    {
        return self::loadTrio(FileBinaryDictionaryLoader::forFileStorage($dir, $maxCachedPages));
    }
}
