<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Binary;

use IgoModern\Binary\Contract\InputStreamFactory;
use IgoModern\Binary\Contract\ShortArray;
use IgoModern\Dictionary\Contract\ConnectionMatrix;

/**
 * 形態素同士の連接コストを matrix.bin から読み込み、左・右 ID の組で参照する。
 */
class BinaryConnectionMatrix implements ConnectionMatrix
{
    /**
     * 事前に読み込まれた行幅と連接コスト表を保持する。
     */
    public function __construct(
        private int $leftSize,
        private ShortArray $matrix,
    ) {}

    /**
     * 辞書ディレクトリの matrix.bin を開き、ヘッダサイズとコスト表を読み込む。
     *
     * 公開構築点は Storage 実装のみ。$streams は実体化方式を内包した stream ファクトリ（Storage が提供）。
     */
    public static function fromDataDir(string $dataDir, InputStreamFactory $streams): self
    {
        $stream = $streams->open($dataDir . '/matrix.bin');

        try {
            $leftSize = $stream->getInt();
            $rightSize = $stream->getInt();

            return new self($leftSize, $stream->getShortArrayInstance($leftSize * $rightSize));
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
