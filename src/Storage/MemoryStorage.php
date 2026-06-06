<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Binary\ArrayMaterialization;

/**
 * 辞書配列を常駐（MemoryArray）で実体化し、ファイルアクセスを避ける storage。
 */
final class MemoryStorage extends BinaryStorage
{
    /**
     * 辞書ディレクトリから常駐の辞書一式を構築する。
     */
    public static function fromDataDir(string $dir): self
    {
        return self::loadTrio($dir, ArrayMaterialization::Resident());
    }
}
