<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * 形態素同士の連接コストを matrix.bin から読み込み、左・右 ID の組で参照する。
 */
class Matrix
{
    /** 右 ID ごとの行を平坦化した matrix 配列の行幅を保持する。 */
    private int $leftSize;

    /** 連接コスト表の signed short 値を必要に応じて読み出す。 */
    private ShortArray $matrix;

    /**
     * 辞書ディレクトリの matrix.bin を開き、ヘッダサイズとコスト表を読み込む。
     */
    public function __construct(string $dataDir)
    {
        $stream = new FileMappedInputStream($dataDir . '/matrix.bin');

        try {
            $this->leftSize = $stream->getInt();
            $rightSize = $stream->getInt();
            $this->matrix = $stream->getShortArrayInstance($this->leftSize * $rightSize);
        } finally {
            $stream->close();
        }
    }

    /**
     * 右 ID を行、左 ID を列として平坦化された連接コストを返す。
     */
    public function linkCost(int $leftId, int $rightId): int
    {
        return $this->matrix->get(($rightId * $this->leftSize) + $leftId);
    }
}
