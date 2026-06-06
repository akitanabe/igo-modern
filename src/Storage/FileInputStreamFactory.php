<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Binary\Contract\ByteReaderFactory;
use IgoModern\Binary\Contract\InputStream;
use IgoModern\Binary\Contract\InputStreamFactory;

/**
 * 辞書ファイルを開き、実体化方式と ByteReaderFactory を内包した FileInputStream を生成するファクトリ。
 *
 * 実体化方式（Lazy / Resident）は factory に閉じ、辞書クラスへは InputStreamFactory 契約だけを公開する。
 */
final class FileInputStreamFactory implements InputStreamFactory
{
    /**
     * 全 stream へ伝播させる実体化方式と、Lazy 配列が使うファイル reader の生成元を保持する。
     */
    public function __construct(
        private ArrayMaterialization $materialization,
        private ByteReaderFactory $byteReaderFactory,
    ) {}

    /**
     * 遅延読み（DynamicArray）で実体化する factory を、指定の ByteReaderFactory とともに構築する。
     */
    public static function lazy(ByteReaderFactory $byteReaderFactory): self
    {
        return new self(ArrayMaterialization::Lazy(), $byteReaderFactory);
    }

    /**
     * 常駐（MemoryArray）で実体化する factory を、指定の ByteReaderFactory とともに構築する。
     *
     * word.dat は materialization に関係なくランダムアクセス reader を要するため、Resident でも factory を保持する。
     */
    public static function resident(ByteReaderFactory $byteReaderFactory): self
    {
        return new self(ArrayMaterialization::Resident(), $byteReaderFactory);
    }

    /**
     * 指定ファイルを開き、保持する実体化方式・reader factory を内包した InputStream を返す。
     */
    public function open(string $fileName): InputStream
    {
        return FileInputStream::fromFile($fileName, $this->materialization, $this->byteReaderFactory);
    }
}
