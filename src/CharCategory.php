<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * 文字コードから未知語カテゴリを引き、同じ未知語として連結できる文字種か判定する。
 */
class CharCategory
{
    /** @var list<Category> char.category に格納されたカテゴリ定義を保持する。 */
    private array $categories;

    /** 文字コードからカテゴリ配列の添字を引くための int 配列を保持する。 */
    private IntArray $char2id;

    /** 文字コード同士の連結互換性をビットマスクで判定するための int 配列を保持する。 */
    private IntArray $eqlMasks;

    /**
     * 辞書ディレクトリからカテゴリ定義と文字コード別のカテゴリ・互換性表を読み込む。
     */
    public function __construct(string $dataDir)
    {
        $this->categories = $this->readCategories($dataDir);

        $stream = new FileMappedInputStream($dataDir . '/code2category');

        try {
            $count = intdiv($stream->size(), 4 * 2);
            $this->char2id = $stream->getIntArrayInstance($count);
            $this->eqlMasks = $stream->getIntArrayInstance($count);
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
    private function readCategories(string $dataDir): array
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
