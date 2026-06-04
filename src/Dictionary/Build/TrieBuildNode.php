<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

/**
 * double-array 配置前の trie ノードとして、子遷移、終端 ID、base 値を保持する。
 */
class TrieBuildNode
{
    /** 完全一致するキーがある場合の trie ID を保持する。 */
    public ?int $id = null;

    /** double-array 内でこのノードの遷移基準になる base offset を保持する。 */
    public ?int $base = null;

    /** @var array<int, self> UTF-16 code unit ごとの子ノードを保持する。 */
    public array $children = [];
}
