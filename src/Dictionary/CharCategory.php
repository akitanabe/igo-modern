<?php

declare(strict_types=1);

namespace IgoModern\Dictionary;

use IgoModern\Binary\Contract\IntArray;
use IgoModern\Binary\FileMappedInputStream;

/**
 * 文字コードから未知語カテゴリを引き、同じ未知語として連結できる文字種か判定する。
 */
class CharCategory
{
    /**
     * 事前に読み込まれたカテゴリ定義と文字コード表を保持する。
     *
     * @param list<Category> $categories
     */
    public function __construct(
        private array $categories,
        private IntArray $char2id,
        private IntArray $eqlMasks,
    ) {}

    /**
     * 辞書ディレクトリからカテゴリ定義と文字コード別のカテゴリ・互換性表を読み込む。
     */
    public static function fromDataDir(string $dataDir): self
    {
        $stream = new FileMappedInputStream($dataDir . '/code2category');

        try {
            $count = intdiv($stream->size(), 4 * 2);

            return new self(
                self::readCategories($dataDir),
                $stream->getIntArrayInstance($count),
                $stream->getIntArrayInstance($count),
            );
        } finally {
            $stream->close();
        }
    }

    /**
     * 指定された文字コードに割り当てられた未知語カテゴリ定義を返す。
     */
    public function category(int $code): Category
    {
        return $this->categories[$this->char2id->get($code)];
    }

    /**
     * 2 つの文字コードの互換性マスクに共通ビットがある場合に同じ未知語へ連結可能とみなす。
     */
    public function isCompatible(int $code1, int $code2): bool
    {
        return ($this->eqlMasks->get($code1) & $this->eqlMasks->get($code2)) !== 0;
    }

    /**
     * char.category の 4 int 1 組のレコードを Category のリストへ変換する。
     *
     * @return list<Category>
     */
    private static function readCategories(string $dataDir): array
    {
        $data = FileMappedInputStream::getIntArrayFromFile($dataDir . '/char.category');
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
