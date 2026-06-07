<?php

declare(strict_types=1);

namespace IgoModern\Tests\Support;

use IgoModern\Binary\Contract\ByteReader;
use IgoModern\Binary\Contract\ByteReaderFactory;

/**
 * ByteReaderFactory 契約を満たし、open されたファイル名を記録する test double。
 *
 * 実ファイルの内容を読み込んで RecordingByteReader を返すため、ファイル名の伝播検証と実際の値読み取りを両立する。
 */
final class RecordingByteReaderFactory implements ByteReaderFactory
{
    /** @var list<string> open に渡されたファイル名を検証用に記録する。 */
    public array $openedFiles = [];

    /**
     * open されたファイル名を記録し、実ファイル内容を読む RecordingByteReader を返す。
     */
    public function open(string $fileName): ByteReader
    {
        $this->openedFiles[] = $fileName;

        $bytes = file_get_contents($fileName);

        if ($bytes === false) {
            throw new \RuntimeException('dictionary reading failed.');
        }

        return new RecordingByteReader($bytes);
    }
}
