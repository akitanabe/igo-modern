<?php

declare(strict_types=1);

namespace IgoModern\Storage\File;

use IgoModern\Binary\Contract\ByteReader;
use RuntimeException;

/**
 * バイナリファイルを固定サイズページ単位で読み、複数ページをキャッシュして再利用する。
 *
 * ダブル配列トライ探索（base/chck）はランダムアクセスのためページが交互に切り替わり、
 * fseek+fread が頻発する。複数ページキャッシュにより file storage の parse 性能を改善する。
 */
class PagedBinaryReader implements ByteReader
{
    /** dynamic array のランダムアクセスで使う既定ページサイズを保持する。 */
    private const DEFAULT_PAGE_SIZE = 8192;

    /**
     * 既定のキャッシュ上限ページ数。
     *
     * double-array trie の base/chck アクセスは 2 系統のランダムアクセスを交互に行うため、
     * 両系統のホットページが同時にキャッシュに収まることが重要となる。
     * 8KB × 1,024 ページ ≈ 8MB/reader は L3 キャッシュに収まりやすい実用的な上限であり、
     * UniDic ファイルサイズ（数百 MB）に対して十分な作業集合を確保できる。
     */
    public const DEFAULT_MAX_CACHED_PAGES = 1024;

    /** @var resource バイナリ辞書をページ読み込みするためのファイルハンドルを保持する。 */
    private $file;

    /** @var positive-int コンストラクタバリデーション後に 1 以上が保証されるページサイズを保持する。 */
    private int $pageSize;

    /** @var positive-int コンストラクタバリデーション後に 1 以上が保証されるキャッシュ上限ページ数を保持する。 */
    private int $maxCachedPages;

    /**
     * ページ番号をキーとしたバイト列の連想配列（FIFO + simple LRU）を保持する。
     *
     * PHP 配列の挿入順を利用して最古エントリを array_key_first + unset で追い出す FIFO として動作し、
     * ヒット時には unset + 再挿入で末尾へ昇格させる simple LRU を実現する。
     *
     * @var array<int,string>
     */
    private array $pageCache = [];

    /**
     * 開かれたファイルハンドルとページサイズ、キャッシュ上限ページ数を保持する。
     *
     * pageSize は 1 以上、maxCachedPages は 1 以上でなければ辞書読み込み失敗とする。
     *
     * @param resource $file
     * @param int $pageSize 1 回の読み込み単位（バイト数）
     * @param int $maxCachedPages キャッシュに保持できる最大ページ数（デフォルト 1,024）
     */
    public function __construct(
        $file,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        int $maxCachedPages = self::DEFAULT_MAX_CACHED_PAGES,
    ) {
        if ($pageSize < 1) {
            throw new RuntimeException('dictionary reading failed.');
        }

        if ($maxCachedPages < 1) {
            throw new RuntimeException('dictionary reading failed.');
        }

        $this->file = $file;
        $this->pageSize = $pageSize;
        $this->maxCachedPages = $maxCachedPages;
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
     *
     * 要求範囲が 1 ページ内に収まる場合は fast path（直接 substr）で返す。
     * ページ境界をまたぐ場合は slow path（連結ループ）で処理する。
     */
    public function readBytes(int $byteOffset, int $byteLength): string
    {
        if ($byteOffset < 0 || $byteLength < 0) {
            throw new RuntimeException('dictionary reading failed.');
        }

        if ($byteLength === 0) {
            return '';
        }

        $startPage = intdiv($byteOffset, $this->pageSize);
        $endPage = intdiv($byteOffset + $byteLength - 1, $this->pageSize);

        // fast path: 要求範囲が 1 ページ内に収まる場合はループを通らず直接返す。
        if ($startPage === $endPage) {
            $page = $this->page($startPage);
            $offsetInPage = $byteOffset - ($startPage * $this->pageSize);
            $available = strlen($page) - $offsetInPage;

            // 最終ページが切り詰められて要求長に満たない場合も、旧実装と同じく読み込み失敗として扱う。
            if ($available < $byteLength) {
                throw new RuntimeException('dictionary reading failed.');
            }

            return substr($page, $offsetInPage, $byteLength);
        }

        // slow path: ページ境界をまたぐ要求は複数ページを連結して返す。
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
     * 指定ページを複数ページキャッシュから返し、未キャッシュならファイルから読み込んでキャッシュに追加する。
     *
     * ヒット時には unset + 再挿入で末尾へ昇格させる simple LRU を行う。
     * キャッシュが上限に達したときは array_key_first + unset で最古エントリを FIFO 追い出しする。
     */
    private function page(int $pageNumber): string
    {
        // キャッシュヒット: simple LRU で末尾へ昇格させる。
        if (isset($this->pageCache[$pageNumber])) {
            $cached = $this->pageCache[$pageNumber];
            unset($this->pageCache[$pageNumber]);
            $this->pageCache[$pageNumber] = $cached;

            return $cached;
        }

        // キャッシュミス: ファイルから読み込む。
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

        // 上限超過時は最古エントリ（配列先頭）を FIFO で追い出す。
        // count が maxCachedPages 以上の時点で pageCache は非空のため array_key_first は非 null を返す。
        if (count($this->pageCache) >= $this->maxCachedPages) {
            unset($this->pageCache[array_key_first($this->pageCache)]);
        }

        $this->pageCache[$pageNumber] = $bytes;

        return $bytes;
    }
}
