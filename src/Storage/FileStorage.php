<?php

declare(strict_types=1);

namespace IgoModern\Storage;

/**
 * 辞書配列を遅延読み（DynamicArray）で実体化する、ファイル常駐を避ける storage。
 */
final class FileStorage extends BinaryStorage
{
    /**
     * 辞書ディレクトリから遅延読みの辞書一式を構築する。
     */
    public static function fromDataDir(string $dir): self
    {
        return self::loadTrio(FileBinaryDictionaryLoader::forFileStorage($dir));
    }
}
