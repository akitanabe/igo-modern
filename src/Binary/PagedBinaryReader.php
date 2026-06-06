<?php

declare(strict_types=1);

namespace IgoModern\Binary;

use IgoModern\Binary\Contract\ByteReader;
use RuntimeException;

/**
 * バイナリファイルを固定サイズページ単位で読み、直近ページを再利用する。
 */
class PagedBinaryReader implements ByteReader
{
    /** dynamic array のランダムアクセスで使う既定ページサイズを保持する。 */
    private const DEFAULT_PAGE_SIZE = 8192;

    /** @var resource バイナリ辞書をページ読み込みするためのファイルハンドルを保持する。 */
    private $file;

    /** @var positive-int 1 回の読み込み単位になるページサイズを保持する。 */
    private int $pageSize;

    /** 現在キャッシュしているページ番号を保持し、同一ページの再読み込みを避ける。 */
    private ?int $cachedPageNumber = null;

    /** 現在キャッシュしているページのバイト列を保持する。 */
    private string $cachedPage = '';

    /**
     * 開かれたファイルハンドルとページサイズを保持する。
     *
     * @param resource $file
     */
    public function __construct($file, int $pageSize = self::DEFAULT_PAGE_SIZE)
    {
        if ($pageSize < 1) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $this->file = $file;
        $this->pageSize = $pageSize;
    }

    /**
     * 読み取り対象ファイルを開き、ページ読み込み reader を作る。
     */
    public static function fromFile(string $fileName, int $pageSize = self::DEFAULT_PAGE_SIZE): self
    {
        $file = fopen($fileName, 'rb');

        if ($file === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return new self($file, $pageSize);
    }

    /**
     * 開いているファイルハンドルを閉じ、ページキャッシュの読み取りリソースを解放する。
     */
    public function __destruct()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }

    /**
     * 指定された byte offset から byte length 分のバイト列をページキャッシュ経由で返す。
     */
    public function readBytes(int $byteOffset, int $byteLength): string
    {
        if ($byteOffset < 0 || $byteLength < 0) {
            throw new RuntimeException('dictionary reading failed.');
        }

        if ($byteLength === 0) {
            return '';
        }

        $remaining = $byteLength;
        $cursor = $byteOffset;
        $bytes = '';

        while ($remaining > 0) {
            $pageNumber = intdiv($cursor, $this->pageSize);
            $page = $this->page($pageNumber);
            $offsetInPage = $cursor - ($pageNumber * $this->pageSize);
            $available = strlen($page) - $offsetInPage;

            if ($available < 1) {
                throw new RuntimeException('dictionary reading failed.');
            }

            $take = min($remaining, $available);
            $bytes .= substr($page, $offsetInPage, $take);
            $remaining -= $take;
            $cursor += $take;
        }

        return $bytes;
    }

    /**
     * 指定ページをキャッシュから返し、未キャッシュならファイルからページ単位で読み込む。
     */
    private function page(int $pageNumber): string
    {
        if ($this->cachedPageNumber === $pageNumber) {
            return $this->cachedPage;
        }

        $pageOffset = $pageNumber * $this->pageSize;

        if ($pageOffset < 0) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $seekResult = fseek($this->file, $pageOffset);

        if ($seekResult !== 0) {
            throw new RuntimeException('dictionary seeking failed.');
        }

        $bytes = fread($this->file, $this->pageSize);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('dictionary reading failed.');
        }

        $this->cachedPageNumber = $pageNumber;
        $this->cachedPage = $bytes;

        return $bytes;
    }
}
