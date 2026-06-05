<?php

declare(strict_types=1);

namespace IgoModern\Analysis;

use IgoModern\Dictionary\Contract\ConnectionMatrix;
use IgoModern\Dictionary\Contract\DictionaryStorage;
use IgoModern\Dictionary\Contract\UnknownWordDictionary;
use IgoModern\Dictionary\Contract\WordDictionary;
use IgoModern\Dictionary\Storage\FileStorage;
use IgoModern\Morpheme;
use RuntimeException;

/**
 * 単語辞書と未知語辞書から Viterbi ラティスを作り、最小コストの形態素列を復元する。
 */
class Tagger
{
    /** @var list<ViterbiNode> 文頭ノードだけを持つ初期候補列を保持する。 */
    private static array $bosNodes = [];

    /** 入力文字列から検出した文字エンコーディングを保持する。 */
    private string $inputEncoding = 'UTF-8';

    /** 辞書バイナリ内の UTF-16 バイトオーダーを保持する。 */
    private string $dictionaryEncoding;

    /**
     * 事前に読み込まれた解析用辞書と出力エンコーディングを保持する。
     */
    public function __construct(
        private WordDictionary $wordDic,
        private UnknownWordDictionary $unknown,
        private ConnectionMatrix $matrix,
        private ?string $outputEncoding = null,
    ) {
        if (self::$bosNodes === []) {
            self::$bosNodes = [ViterbiNode::makeBOSEOS()];
        }

        $this->dictionaryEncoding = self::detectDictionaryEncoding();
    }

    /**
     * 辞書ストレージ抽象から解析器を構築する主入口。
     */
    public static function fromStorage(DictionaryStorage $storage, ?string $outputEncoding = null): self
    {
        return new self(
            $storage->wordDictionary(),
            $storage->unknownWordDictionary(),
            $storage->connectionMatrix(),
            $outputEncoding,
        );
    }

    /**
     * 辞書ディレクトリから解析に必要な辞書を読み込む（ファイル常駐回避の FileStorage を既定とする）。
     */
    public static function fromDataDir(string $dataDir, ?string $outputEncoding = null): self
    {
        return self::fromStorage(FileStorage::fromDataDir($dataDir), $outputEncoding);
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
     * @param list<ViterbiNode> $previousNodes
     */
    public function setMincostNode(ViterbiNode $node, array $previousNodes): ViterbiNode
    {
        if ($previousNodes === []) {
            throw new RuntimeException('previous nodes must not be empty.');
        }

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
     * 入力エンコーディングを検出し、辞書検索に使う UTF-16 バイト列へ変換する。
     */
    private function prepareInput(string $text): string
    {
        $detectedEncoding = mb_detect_encoding($text, 'ASCII,JIS,UTF-8,EUC-JP,SJIS');
        $this->inputEncoding = $detectedEncoding === false ? 'UTF-8' : $detectedEncoding;

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
