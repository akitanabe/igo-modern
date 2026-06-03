<?php

declare(strict_types=1);

namespace IgoModern;

/**
 * WordDic と Unknown から通知された候補を、開始位置ごとのラティスへ接続する。
 */
class MakeLattice implements WordDicCallback
{
    /** 現在の開始位置を保持する。 */
    private int $position = 0;

    /** @var list<ViterbiNode> 現在位置に到達している直前候補を保持する。 */
    private array $previousNodes = [];

    /** 現在位置で候補通知がまだないかを保持する。 */
    private bool $empty = true;

    /**
     * 最小コスト計算を行う Tagger と、更新対象のラティス配列を参照として保持する。
     *
     * @param list<list<ViterbiNode>> $nodes
     */
    public function __construct(
        private Tagger $tagger,
        private array &$nodes,
    ) {}

    /**
     * 新しい開始位置の探索に備えて直前候補と空状態を切り替える。
     */
    public function set(int $position): void
    {
        $this->position = $position;
        $this->previousNodes = $this->nodes[$position];
        $this->empty = true;
    }

    /**
     * 候補ノードをラティスへ接続し、空白ノードは形態素に出さず直前候補を先へ伝播する。
     */
    public function call(ViterbiNode $node): void
    {
        $this->empty = false;
        $nextPosition = $this->position + $node->length;

        if ($node->isSpace) {
            $this->nodes[$nextPosition] = $this->previousNodes;

            return;
        }

        $this->nodes[$nextPosition][] = $this->tagger->setMincostNode($node, $this->previousNodes);
    }

    /**
     * 現在位置で通常単語候補が見つからなかったかを未知語探索へ返す。
     */
    public function isEmpty(): bool
    {
        return $this->empty;
    }
}
