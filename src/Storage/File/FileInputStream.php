<?php

declare(strict_types=1);

namespace IgoModern\Storage\File;

use IgoModern\Binary\CharDynamicArray;
use IgoModern\Binary\CharMemoryArray;
use IgoModern\Binary\Contract\ByteReaderFactory;
use IgoModern\Binary\Contract\CharArray;
use IgoModern\Binary\Contract\CharArrayReader;
use IgoModern\Binary\Contract\InputStream;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\Contract\IntArrayReader;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Binary\Contract\ShortArrayReader;
use IgoModern\Binary\IntDynamicArray;
use IgoModern\Binary\IntMemoryArray;
use IgoModern\Binary\ShortDynamicArray;
use IgoModern\Binary\ShortMemoryArray;
use RuntimeException;

/**
 * 辞書バイナリを現在位置から順に読み、配列実装の入力元にもなるストリーム。
 *
 * ファイルシステム操作と実体化方式（Lazy / Resident）の知識を Storage 内へ閉じ、辞書クラスへは
 * InputStream 契約だけを公開する。Memory 配列の fromReader 用に *ArrayReader も実装する。
 * Resident 実体化では中間 list を経由せずチャンク単位で通常 PHP 配列へ直接詰め込み、ピークメモリを抑える。
 */
final class FileInputStream implements InputStream, IntArrayReader, ShortArrayReader, CharArrayReader
{
    /** @var resource バイナリ辞書を順次読むためのファイルハンドルを保持する。 */
    private $file;

    /** 読み取り対象ファイル名を、サイズ取得と dynamic 配列生成のために保持する。 */
    private string $fileName;

    /** 配列インスタンスに渡す開始位置を追跡するため、数値読み取りのカーソルを保持する。 */
    private int $cur = 0;

    /** 配列インスタンスの実体化方式（Lazy / Resident）を保持する。 */
    private ArrayMaterialization $materialization;

    /** Lazy 時に dynamic 配列へ注入するファイル reader を生成するファクトリを保持する。 */
    private ?ByteReaderFactory $byteReaderFactory;

    /**
     * Resident 実体化でチャンク読み込みする際の 1 チャンクあたりの要素数を保持する。
     *
     * デフォルト 1_000_000 はピークメモリを「配列本体 + 1 チャンク分の unpack 結果」に抑える経験値。
     * テスト時は小さい値を注入してチャンク境界の正しさを確認できる。
     */
    private int $chunkSize;

    /**
     * 開かれたファイルハンドルと配列インスタンスの実体化方式・reader ファクトリを保持する。
     *
     * 実体化方式の既定は Lazy。PHP 8.0 ではオブジェクト定数を既定値に置けないため null で受け取り正規化する。
     * factory は sequential helper では不要なため null を許し、Lazy 配列生成時にガードで必須化する。
     * chunkSize は Resident チャンク読み込みの要素数単位。null の場合は 1_000_000 を使う。
     *
     * @param resource $file
     */
    public function __construct(
        $file,
        string $fileName,
        ?ArrayMaterialization $materialization = null,
        ?ByteReaderFactory $byteReaderFactory = null,
        ?int $chunkSize = null,
    ) {
        $this->file = $file;
        $this->fileName = $fileName;
        $this->materialization = $materialization ?? ArrayMaterialization::Lazy();
        $this->byteReaderFactory = $byteReaderFactory;
        $this->chunkSize = $chunkSize ?? 1_000_000;
    }

    /**
     * 読み取り対象ファイルを開き、順次読み込み stream を作る。
     *
     * chunkSize は Resident チャンク読み込みの要素数単位。テスト時に小さい値を渡してチャンク境界を検証できる。
     */
    public static function fromFile(
        string $fileName,
        ?ArrayMaterialization $materialization = null,
        ?ByteReaderFactory $byteReaderFactory = null,
        ?int $chunkSize = null,
    ): self {
        $file = fopen($fileName, 'rb');

        if ($file === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return new self($file, $fileName, $materialization, $byteReaderFactory, $chunkSize);
    }

    /**
     * 現在位置から 4 バイト signed int を 1 件読み込む。
     */
    public function getInt(): int
    {
        $this->cur += 4;

        return $this->readUnpackedValue(4, 'l');
    }

    /**
     * 現在位置から指定件数の signed int を読み込む。
     *
     * @return list<int>
     */
    public function getIntArray(int $count): array
    {
        $this->cur += $count * 4;

        return $this->readUnpackedValues($count, 4, 'l');
    }

    /**
     * 設定された実体化方式に応じて int 配列の実装を作る。
     *
     * Resident 時はチャンク読み込みで通常 PHP 配列を直接構築し、中間 list の全量複製を避ける。
     */
    public function getIntArrayInstance(int $count): IntArray
    {
        if ($this->materialization === ArrayMaterialization::Lazy()) {
            $array = new IntDynamicArray($this->requireByteReaderFactory()->open($this->fileName), $this->cur);
            $this->skipBytes($count * 4);

            return $array;
        }

        $this->cur += $count * 4;

        return new IntMemoryArray($this->readIntoArray($count, 4, 'l'));
    }

    /**
     * 現在位置から指定件数の signed short を読み込む。
     *
     * @return list<int>
     */
    public function getShortArray(int $count): array
    {
        $this->cur += $count * 2;

        return $this->readUnpackedValues($count, 2, 's');
    }

    /**
     * 設定された実体化方式に応じて signed short 配列の実装を作る。
     *
     * Resident 時はチャンク読み込みで通常 PHP 配列を直接構築し、中間 list の全量複製を避ける。
     */
    public function getShortArrayInstance(int $count): ShortArray
    {
        if ($this->materialization === ArrayMaterialization::Lazy()) {
            $array = new ShortDynamicArray($this->requireByteReaderFactory()->open($this->fileName), $this->cur);
            $this->skipBytes($count * 2);

            return $array;
        }

        $this->cur += $count * 2;

        return new ShortMemoryArray($this->readIntoArray($count, 2, 's'));
    }

    /**
     * 設定された実体化方式に応じて unsigned short 文字コード配列の実装を作る。
     *
     * Resident 時はチャンク読み込みで通常 PHP 配列を直接構築し、中間 list の全量複製を避ける。
     */
    public function getCharArrayInstance(int $count): CharArray
    {
        if ($this->materialization === ArrayMaterialization::Lazy()) {
            $array = new CharDynamicArray($this->requireByteReaderFactory()->open($this->fileName), $this->cur);
            $this->skipBytes($count * 2);

            return $array;
        }

        $this->cur += $count * 2;

        return new CharMemoryArray($this->readIntoArray($count, 2, 'S'));
    }

    /**
     * 現在位置から指定件数の unsigned short を読み込む。
     *
     * @return list<int>
     */
    public function getCharArray(int $count): array
    {
        $this->cur += $count * 2;

        return $this->readUnpackedValues($count, 2, 'S');
    }

    /**
     * 読み取り対象ファイルのバイトサイズを返す。
     */
    public function size(): int
    {
        $size = filesize($this->fileName);

        if ($size === false) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $size;
    }

    /**
     * 開いているファイルハンドルを閉じ、読み取りリソースを解放する。
     */
    public function close(): bool
    {
        if (!is_resource($this->file)) {
            return true;
        }

        return fclose($this->file);
    }

    /**
     * Lazy 配列生成に必須の reader ファクトリを返し、未設定なら設定漏れとして失敗させる。
     */
    private function requireByteReaderFactory(): ByteReaderFactory
    {
        if ($this->byteReaderFactory === null) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $this->byteReaderFactory;
    }

    /**
     * dynamic 配列用に、数値カーソルとファイルポインタを同じバイト数だけ進める。
     */
    private function skipBytes(int $byteCount): void
    {
        $this->cur += $byteCount;
        $seekResult = fseek($this->file, $this->cur);

        if ($seekResult !== 0) {
            throw new RuntimeException('dictionary seeking failed.');
        }
    }

    /**
     * 現在位置から固定幅のバイト列を読み、unpack 形式で 1 件の値に変換する。
     */
    private function readUnpackedValue(int $byteWidth, string $unpackFormat): int
    {
        $data = unpack($unpackFormat, $this->readBytes($byteWidth));

        if ($data === false) {
            throw new RuntimeException('dictionary unpacking failed.');
        }

        return (int) $data[1];
    }

    /**
     * 現在位置から固定幅の値を指定件数だけ読み、list<int> として返す。
     *
     * unpack の戻り値は 1 始まりキーのため array_values で 0 始まり連続添字へ正規化する。
     * 恒等写像の array_map は不要なため除去済み（unpack は既に int を返す）。
     *
     * @return list<int>
     */
    private function readUnpackedValues(int $count, int $byteWidth, string $unpackFormat): array
    {
        if ($count === 0) {
            return [];
        }

        $data = unpack($unpackFormat . '*', $this->readBytes($count * $byteWidth));

        if ($data === false) {
            throw new RuntimeException('dictionary unpacking failed.');
        }

        return array_values($data);
    }

    /**
     * 現在位置から固定幅の値を指定件数だけチャンク単位で読み、通常 PHP 配列（packed list）へ詰め込む。
     *
     * 全量を一括 unpack して list を作るのと比べ、ピークメモリを「配列本体 + 1 チャンク分の unpack 結果」に抑える。
     * unpack の戻りは 1 始まりキーだが、$result[] = $value の append で自然に 0 始まり連続添字の packed list になる。
     * カーソル前進は呼び出し側が済ませてあるため、このメソッド内では進めない。
     *
     * @return list<int>
     */
    private function readIntoArray(int $count, int $byteWidth, string $unpackFormat): array
    {
        if ($count === 0) {
            return [];
        }

        $result = [];
        $remaining = $count;

        while ($remaining > 0) {
            $batchCount = min($remaining, $this->chunkSize);
            $data = unpack($unpackFormat . '*', $this->readBytes($batchCount * $byteWidth));

            if ($data === false) {
                throw new RuntimeException('dictionary unpacking failed.');
            }

            foreach ($data as $value) {
                $result[] = $value;
            }

            $remaining -= $batchCount;
        }

        return $result;
    }

    /**
     * ファイルポインタから指定バイト数を読み、不足があれば辞書読み込み失敗として扱う。
     */
    private function readBytes(int $byteCount): string
    {
        if ($byteCount < 0) {
            throw new RuntimeException('dictionary reading failed.');
        }

        if ($byteCount === 0) {
            return '';
        }

        $bytes = fread($this->file, $byteCount);

        if ($bytes === false || strlen($bytes) !== $byteCount) {
            throw new RuntimeException('dictionary reading failed.');
        }

        return $bytes;
    }
}
