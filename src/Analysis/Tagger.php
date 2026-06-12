<?php

declare(strict_types=1);

namespace IgoModern\Analysis;

use IgoModern\Dictionary\Binary\BinaryConnectionMatrix;
use IgoModern\Dictionary\Contract\ConnectionMatrix;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\Contract\WordDictionary;
use IgoModern\Morpheme;
use IgoModern\Storage\DictionaryStorage;
use RuntimeException;

/**
 * 単語辞書と未知語辞書から Viterbi ラティスを作り、最小コストの形態素列を復元する。
 */
class Tagger
{
    /** @var list<ViterbiNode> 文頭ノードだけを持つ初期候補列を保持する。 */
    private static array $bosNodes = [];

    /** 入力文字列のエンコーディングを保持する。固定指定時は検出をスキップする。 */
    private string $inputEncoding = 'UTF-8';

    /** 固定された入力エンコーディング。null の場合は parse ごとに mb_detect_encoding で検出する。 */
    private ?string $fixedInputEncoding;

    /** 辞書バイナリ内の UTF-16 バイトオーダーを保持する。 */
    private string $dictionaryEncoding;

    /**
     * 常駐メモリ時に連接コストの生配列を保持する。Lazy 時は null で fallback 経路を使う。
     *
     * @var list<int>|null
     */
    private ?array $rawLinkCosts = null;

    /** 連接コスト生配列の添字算出に使う行幅（左文脈 ID の総数）を保持する。 */
    private int $linkCostLeftSize = 0;

    /**
     * 事前に読み込まれた解析用辞書と出力エンコーディングを保持する。
     *
     * @param ?string $inputEncoding 入力エンコーディングを固定する場合に指定。null なら parse ごとに検出する。
     */
    public function __construct(
        private WordDictionary $wordDic,
        private UnknownWordDictionary $unknown,
        private ConnectionMatrix $matrix,
        private ?string $outputEncoding = null,
        ?string $inputEncoding = null,
    ) {
        if (self::$bosNodes === []) {
            self::$bosNodes = [ViterbiNode::makeBOSEOS()];
        }

        // 常駐メモリ辞書なら生配列と行幅を一度だけ取り出し、ホットパスの直接添字参照に備える。
        if ($matrix instanceof BinaryConnectionMatrix) {
            $this->rawLinkCosts = $matrix->rawCosts();
            $this->linkCostLeftSize = $matrix->leftSize();
        }

        $this->dictionaryEncoding = self::detectDictionaryEncoding();
        $this->fixedInputEncoding = $inputEncoding;

        // 固定エンコーディングが指定されている場合は即座に inputEncoding プロパティへ設定しておく。
        if ($inputEncoding !== null) {
            $this->inputEncoding = $inputEncoding;
        }
    }

    /**
     * 辞書ストレージ抽象から解析器を構築する主入口。
     *
     * @param ?string $inputEncoding 入力エンコーディングを固定する場合に指定。null なら parse ごとに検出する。
     */
    public static function fromStorage(
        DictionaryStorage $storage,
        ?string $outputEncoding = null,
        ?string $inputEncoding = null,
    ): self {
        return new self(
            $storage->wordDictionary(),
            $storage->unknownWordDictionary(),
            $storage->connectionMatrix(),
            $outputEncoding,
            $inputEncoding,
        );
    }

    /**
     * 解析した形態素を Morpheme として既存結果へ追記し、結果配列を返す。
     *
     * @param list<Morpheme>|null $result
     * @return list<Morpheme>
     */
    public function parse(string $text, ?array $result = null): array
    {
        $result ??= [];
        $utf16 = $this->prepareInput($text);

        foreach ($this->parseImpl($this->unpackUtf16Codes($utf16)) as $node) {
            $surface = $this->convertFromDictionaryEncoding(substr($utf16, $node->start << 1, $node->length << 1));
            $feature = $this->convertFromDictionaryEncoding($this->wordDic->wordData($node->wordId));
            $result[] = new Morpheme($surface, $feature, $node->start);
        }

        return $result;
    }

    /**
     * 解析した形態素の表層形だけを既存結果へ追記し、分かち書き結果を返す。
     *
     * @param list<string>|null $result
     * @return list<string>
     */
    public function wakati(string $text, ?array $result = null): array
    {
        $result ??= [];
        $utf16 = $this->prepareInput($text);

        foreach ($this->parseImpl($this->unpackUtf16Codes($utf16)) as $node) {
            $result[] = $this->convertFromDictionaryEncoding(substr($utf16, $node->start << 1, $node->length << 1));
        }

        return $result;
    }

    /**
     * 候補ノードの直前候補から最小連接コストの経路を選び、ノードの累積コストへ反映する。
     *
     * 常駐メモリ辞書なら生配列直接参照の fast 版、Lazy なら linkCost 呼び出しの fallback 版へ
     * 分岐する。分岐はメソッド先頭で 1 回だけ行い、最内ループには instanceof / null 比較を持ち込まない。
     *
     * @param list<ViterbiNode> $previousNodes
     */
    public function setMincostNode(ViterbiNode $node, array $previousNodes): ViterbiNode
    {
        if ($previousNodes === []) {
            throw new RuntimeException('previous nodes must not be empty.');
        }

        if ($this->rawLinkCosts !== null) {
            return $this->setMincostNodeFast($node, $previousNodes, $this->rawLinkCosts);
        }

        return $this->setMincostNodeFallback($node, $previousNodes);
    }

    /**
     * 常駐メモリの連接コスト生配列を直接添字参照して最小コスト経路を選ぶ fast 版。
     *
     * fallback 版と完全に同一の結果（選択ノード・累積コスト）を返すことを不変条件とする。
     *
     * @param list<ViterbiNode> $previousNodes
     * @param list<int> $costs
     */
    private function setMincostNodeFast(ViterbiNode $node, array $previousNodes, array $costs): ViterbiNode
    {
        // fallback の linkCost($prev->rightId, $node->leftId) は内部で get($node->leftId * leftSize + $prev->rightId)
        // を引く。直接添字でも同じ並び（行 = node->leftId、列 = prev->rightId）を再現する。
        $rowBase = $node->leftId * $this->linkCostLeftSize;

        $bestPreviousNode = $previousNodes[0];
        $minCost = $bestPreviousNode->cost + $costs[$rowBase + $bestPreviousNode->rightId];

        for ($i = 1, $count = count($previousNodes); $i < $count; $i++) {
            $previousNode = $previousNodes[$i];
            $cost = $previousNode->cost + $costs[$rowBase + $previousNode->rightId];

            if ($cost < $minCost) {
                $minCost = $cost;
                $bestPreviousNode = $previousNode;
            }
        }

        $node->prev = $bestPreviousNode;
        $node->cost += $minCost;

        return $node;
    }

    /**
     * 連接コストを linkCost() 経由で参照する fallback 版（FileStorage / Lazy 経路）。
     *
     * @param list<ViterbiNode> $previousNodes
     */
    private function setMincostNodeFallback(ViterbiNode $node, array $previousNodes): ViterbiNode
    {
        $bestPreviousNode = $previousNodes[0];
        $minCost = $bestPreviousNode->cost + $this->matrix->linkCost($bestPreviousNode->rightId, $node->leftId);

        for ($i = 1, $count = count($previousNodes); $i < $count; $i++) {
            $previousNode = $previousNodes[$i];
            $cost = $previousNode->cost + $this->matrix->linkCost($previousNode->rightId, $node->leftId);

            if ($cost < $minCost) {
                $minCost = $cost;
                $bestPreviousNode = $previousNode;
            }
        }

        $node->prev = $bestPreviousNode;
        $node->cost += $minCost;

        return $node;
    }

    /**
     * 入力エンコーディングを確定し、辞書検索に使う UTF-16 バイト列へ変換する。
     *
     * 固定エンコーディングが指定されている場合は検出をスキップし、指定値をそのまま使う。
     * 未指定の場合は従来どおり mb_detect_encoding で検出する。
     */
    private function prepareInput(string $text): string
    {
        if ($this->fixedInputEncoding === null) {
            $detectedEncoding = mb_detect_encoding($text, 'ASCII,JIS,UTF-8,EUC-JP,SJIS');
            $this->inputEncoding = $detectedEncoding === false ? 'UTF-8' : $detectedEncoding;
        }

        $utf16 = mb_convert_encoding($text, $this->dictionaryEncoding, $this->inputEncoding);

        if ($utf16 === false) {
            throw new RuntimeException('failed to convert input text to dictionary encoding.');
        }

        return $utf16;
    }

    /**
     * 辞書エンコーディングのバイト列を利用者向けの出力エンコーディングへ変換する。
     */
    private function convertFromDictionaryEncoding(string $text): string
    {
        $converted = mb_convert_encoding(
            $text,
            $this->outputEncoding ?? $this->inputEncoding,
            $this->dictionaryEncoding,
        );

        if ($converted === false) {
            throw new RuntimeException('failed to convert dictionary text to output encoding.');
        }

        return $converted;
    }

    /**
     * UTF-16 相当の辞書バイト列を Viterbi 探索用の文字コード列へ復元する。
     *
     * @return list<int>
     */
    private function unpackUtf16Codes(string $utf16): array
    {
        if ($utf16 === '') {
            return [];
        }

        $codes = unpack('S*', $utf16);

        if ($codes === false) {
            return [];
        }

        return array_values($codes);
    }

    /**
     * 入力文字コード列からラティスを構築し、先頭から読める最小コスト経路へ反転して返す。
     *
     * @param list<int> $text
     * @return array<int, ViterbiNode>
     */
    private function parseImpl(array $text): array
    {
        $textLength = count($text);
        $nodes = array_fill(0, $textLength + 1, []);
        $nodes[0] = self::$bosNodes;

        $callback = new MakeLattice($this, $nodes);

        for ($i = 0; $i < $textLength; $i++) {
            if ($nodes[$i] === []) {
                continue;
            }

            $callback->set($i);
            $this->wordDic->search($text, $i, $callback);
            $this->unknown->search($text, $i, $callback);
            $nodes[$i] = [];
        }

        $endNode = $this->setMincostNode(ViterbiNode::makeBOSEOS(), $nodes[$textLength]);

        return $this->reversePath($endNode->prev);
    }

    /**
     * 終端側から prev で連なった経路を、先頭から走査できる配列へ変換する。
     *
     * @return array<int, ViterbiNode>
     */
    private function reversePath(?ViterbiNode $node): array
    {
        $result = [];

        while ($node !== null && $node->prev !== null) {
            $result[] = $node;
            $node = $node->prev;
        }

        $this->reverseNodesInPlace($result);

        return $result;
    }

    /**
     * list の添字連続性を保ったまま、ViterbiNode の順序だけを反転する。
     *
     * @param array<int, ViterbiNode> $nodes
     */
    private function reverseNodesInPlace(array &$nodes): void
    {
        for ($left = 0, $right = count($nodes) - 1; $left < $right; $left++, $right--) {
            $temporary = $nodes[$left];
            $nodes[$left] = $nodes[$right];
            $nodes[$right] = $temporary;
        }
    }

    /**
     * 旧辞書が依存する unsigned short のネイティブバイトオーダーに対応する UTF-16 名を返す。
     */
    private static function detectDictionaryEncoding(): string
    {
        return pack('S', 1) === "\x01\x00" ? 'UTF-16LE' : 'UTF-16BE';
    }
}
