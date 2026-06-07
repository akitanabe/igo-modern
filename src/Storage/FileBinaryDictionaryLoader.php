<?php

declare(strict_types=1);

namespace IgoModern\Storage;

use IgoModern\Binary\Contract\ByteReaderFactory;
use IgoModern\Binary\Contract\InputStreamFactory;
use IgoModern\Binary\Contract\IntArray;
use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use IgoModern\Dictionary\Binary\BinaryUnknownWordDictionary;
use IgoModern\Dictionary\Binary\BinaryWordDictionary;
use IgoModern\Dictionary\Category;
use IgoModern\Dictionary\CharCategory;
use IgoModern\Dictionary\WordDataReader;

/**
 * ファイル辞書から runtime 辞書一式を構築する loader。
 *
 * 辞書ディレクトリ構造（word2id / word.dat / word.ary.idx / word.inf / matrix.bin /
 * code2category / char.category）の知識をここに閉じる。FileStorage と MemoryStorage の差は
 * `InputStreamFactory` が Lazy か Resident かだけなので、読み取りロジックは 1 クラスにまとめ、
 * 実体化方針は named constructor で切り替える。
 */
final class FileBinaryDictionaryLoader implements BinaryDictionaryLoader
{
    /**
     * 読み込み対象の辞書ディレクトリと、実体化方針を内包した stream / reader factory を保持する。
     *
     * $byteReaderFactory は word.dat が常に要するランダムアクセス reader の生成元で、$streams と並走で渡す。
     */
    public function __construct(
        private string $dataDir,
        private InputStreamFactory $streams,
        private ByteReaderFactory $byteReaderFactory,
    ) {}

    /**
     * FileStorage 用に、Lazy（DynamicArray）実体化を内包した loader を構築する。
     */
    public static function forFileStorage(string $dataDir): self
    {
        $byteReaderFactory = new PagedByteReaderFactory();

        return new self($dataDir, FileInputStreamFactory::lazy($byteReaderFactory), $byteReaderFactory);
    }

    /**
     * MemoryStorage 用に、Resident（MemoryArray）実体化を内包した loader を構築する。
     */
    public static function forMemoryStorage(string $dataDir): self
    {
        $byteReaderFactory = new PagedByteReaderFactory();

        return new self($dataDir, FileInputStreamFactory::resident($byteReaderFactory), $byteReaderFactory);
    }

    /**
     * word2id / word.dat / word.ary.idx / word.inf を組み合わせて単語辞書を作る。
     */
    public function loadWordDictionary(): BinaryWordDictionary
    {
        $stream = $this->streams->open($this->dataDir . '/word.inf');

        try {
            $wordCount = intdiv($stream->size(), 4 + 2 + 2 + 2);

            return new BinaryWordDictionary(
                (new FileTrieLoader($this->streams))->load($this->dataDir . '/word2id'),
                new WordDataReader($this->byteReaderFactory->open($this->dataDir . '/word.dat')),
                $this->readWordIndices(),
                $stream->getIntArrayInstance($wordCount),
                $stream->getShortArrayInstance($wordCount),
                $stream->getShortArrayInstance($wordCount),
                $stream->getShortArrayInstance($wordCount),
            );
        } finally {
            $stream->close();
        }
    }

    /**
     * code2category / char.category を読み、単語辞書と組み合わせて未知語辞書を作る。
     */
    public function loadUnknownWordDictionary(BinaryWordDictionary $wordDictionary): BinaryUnknownWordDictionary
    {
        return new BinaryUnknownWordDictionary($this->loadCharCategory(), $wordDictionary);
    }

    /**
     * matrix.bin を読み、ヘッダサイズとコスト表から連接行列を作る。
     */
    public function loadConnectionMatrix(): BinaryConnectionMatrix
    {
        $stream = $this->streams->open($this->dataDir . '/matrix.bin');

        try {
            $leftSize = $stream->getInt();
            $rightSize = $stream->getInt();

            return new BinaryConnectionMatrix($leftSize, $stream->getShortArrayInstance($leftSize * $rightSize));
        } finally {
            $stream->close();
        }
    }

    /**
     * code2category と char.category から文字カテゴリ辞書を構築する。
     *
     * 未知語辞書の構築に内部利用するほか、build 経路のカテゴリ読み戻し検証からも使う。
     */
    public function loadCharCategory(): CharCategory
    {
        $stream = $this->streams->open($this->dataDir . '/code2category');

        try {
            $count = intdiv($stream->size(), 4 * 2);

            return new CharCategory(
                $this->readCategories(),
                $stream->getIntArrayInstance($count),
                $stream->getIntArrayInstance($count),
            );
        } finally {
            $stream->close();
        }
    }

    /**
     * word.ary.idx 全体を PHP 配列へ展開せず、trie ID 範囲の参照に必要な int 配列 reader を作る。
     */
    private function readWordIndices(): IntArray
    {
        $stream = $this->streams->open($this->dataDir . '/word.ary.idx');

        try {
            return $stream->getIntArrayInstance(intdiv($stream->size(), 4));
        } finally {
            $stream->close();
        }
    }

    /**
     * char.category の 4 int 1 組のレコードを Category のリストへ変換する。
     *
     * ByteReader を開かない順次読みでファイル全体を int 配列として読み切り、char.category 用 stream を確実に閉じる。
     *
     * @return list<Category>
     */
    private function readCategories(): array
    {
        $stream = $this->streams->open($this->dataDir . '/char.category');

        try {
            $data = $stream->getIntArray(intdiv($stream->size(), 4));
        } finally {
            $stream->close();
        }

        $size = intdiv(count($data), 4);
        $categories = [];

        for ($i = 0; $i < $size; $i++) {
            $base = $i * 4;
            $categories[] = new Category(
                $data[$base],
                $data[$base + 1],
                $data[$base + 2] === 1,
                $data[$base + 3] === 1,
            );
        }

        return $categories;
    }
}
