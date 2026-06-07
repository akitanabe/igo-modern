<?php

declare(strict_types=1);

namespace IgoModern\Tests\Support;

use IgoModern\Binary\Contract\ByteReader;

use function substr;

/**
 * ByteReader 契約を満たし、内部バイト列の切り出しと呼び出し引数の記録を行うテスト用 double。
 */
final class RecordingByteReader implements ByteReader
{
    /** @var list<array{int, int}> readBytes に渡された [offset, length] を検証用に記録する。 */
    public array $calls = [];

    /**
     * 読み取り対象の内部バイト列をテスト用に保持する。
     */
    public function __construct(
        private string $bytes,
    ) {}

    /**
     * 要求された範囲を内部バイト列から切り出して返し、呼び出し引数を記録する。
     */
    public function readBytes(int $byteOffset, int $byteLength): string
    {
        $this->calls[] = [$byteOffset, $byteLength];

        return substr($this->bytes, $byteOffset, $byteLength);
    }
}
