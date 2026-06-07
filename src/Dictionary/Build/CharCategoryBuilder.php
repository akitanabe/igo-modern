<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use IgoModern\Dictionary\Trie\TrieLoader;
use RuntimeException;

/**
 * char.def から runtime CharCategory が読める文字カテゴリ辞書を生成する。
 */
class CharCategoryBuilder implements DictionaryBuildStep
{
    /** UCS2 の全 code unit 数を code2category の固定サイズとして保持する。 */
    private const UCS2_CODE_COUNT = 0x1_0000;

    /**
     * カテゴリ名を未知語 trie ID へ解決する依存を必須で保持し、コンストラクタでは I/O を発生させない。
     */
    public function __construct(
        /** カテゴリ名から char.category に保存する未知語 trie ID を解決する。 */
        private CategoryIdResolver $categoryIdResolver,
    ) {}

    /**
     * 通常利用向けに word2id を参照する標準 resolver を注入した builder を組み立てる。
     */
    public static function createDefault(TrieLoader $trieLoader): self
    {
        return new self(new Word2IdCategoryIdResolver($trieLoader));
    }

    /**
     * char.def を読み込み、CharCategory が読むカテゴリ定義と文字コード表を生成する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        $definition = $this->readCharDefinition($inputDirectory . '/char.def');
        $categoryRecords = $this->categoryRecords($definition['categories'], $outputDirectory, $encoding);
        $codeTables = $this->codeTables($definition['categories'], $definition['ranges']);

        $this->ensureOutputDirectory($outputDirectory);
        $this->writeBinaryFile($outputDirectory . '/char.category', $this->categoryBinary($categoryRecords));
        $this->writeBinaryFile($outputDirectory . '/code2category', $this->codeTablesBinary($codeTables));
    }

    /**
     * char.def をカテゴリ定義行とコード範囲定義行へ分け、必須カテゴリの存在を検証する。
     *
     * @return array{
     *     categories:list<array{name:string, invoke:int, group:int, length:int}>,
     *     ranges:list<array{start:int, end:int, names:list<string>, line:int}>
     * }
     */
    private function readCharDefinition(string $fileName): array
    {
        $lines = file($fileName, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('failed to read char.def "%s".', $fileName));
        }

        $categories = [];
        $ranges = [];

        foreach ($this->definitionLines($lines) as $line) {
            if (str_starts_with($line['text'], '0x')) {
                $ranges[] = $this->parseRangeLine($line['text'], $line['number']);

                continue;
            }

            $categories[] = $this->parseCategoryLine($line['text'], $line['number']);
        }

        $this->assertRequiredCategories($categories);
        $this->assertRangeCategoryNames($ranges, $this->categoryNameMap($categories));

        return ['categories' => $categories, 'ranges' => $ranges];
    }

    /**
     * コメントと空行を除外し、エラー表示に使う元の行番号を保った定義行へ整える。
     *
     * @param list<string> $lines
     * @return list<array{number:int, text:string}>
     */
    private function definitionLines(array $lines): array
    {
        $definitionLines = [];

        foreach ($lines as $index => $line) {
            $commentStart = strpos($line, '#');
            $withoutComment = $commentStart === false ? $line : substr($line, 0, $commentStart);
            $trimmed = trim($withoutComment);

            if ($trimmed === '') {
                continue;
            }

            $definitionLines[] = ['number' => $index + 1, 'text' => $trimmed];
        }

        return $definitionLines;
    }

    /**
     * カテゴリ定義行からカテゴリ名と未知語探索フラグを読み取り、int 値として検証する。
     *
     * @return array{name:string, invoke:int, group:int, length:int}
     */
    private function parseCategoryLine(string $line, int $lineNumber): array
    {
        $fields = $this->splitFields($line);

        if (
            count($fields) !== 4
            || !$this->isCategoryName($fields[0])
            || !$this->isBinaryFlag($fields[1])
            || !$this->isBinaryFlag($fields[2])
            || !$this->isNonNegativeInteger($fields[3])
        ) {
            throw new RuntimeException(sprintf(
                'char.def line %d must contain category, invoke, group, and length.',
                $lineNumber,
            ));
        }

        return [
            'name' => $fields[0],
            'invoke' => (int) $fields[1],
            'group' => (int) $fields[2],
            'length' => (int) $fields[3],
        ];
    }

    /**
     * コード範囲定義行から UCS2 範囲と 1 個以上のカテゴリ名を読み取る。
     *
     * @return array{start:int, end:int, names:list<string>, line:int}
     */
    private function parseRangeLine(string $line, int $lineNumber): array
    {
        $fields = $this->splitFields($line);

        if (count($fields) < 2) {
            throw new RuntimeException(sprintf(
                'char.def line %d must contain code range and categories.',
                $lineNumber,
            ));
        }

        [$start, $end] = $this->parseCodeRange($fields[0], $lineNumber);
        $names = array_slice($fields, 1);

        foreach ($names as $name) {
            if (!$this->isCategoryName($name)) {
                throw new RuntimeException(sprintf('char.def line %d has invalid category name.', $lineNumber));
            }
        }

        return ['start' => $start, 'end' => $end, 'names' => $names, 'line' => $lineNumber];
    }

    /**
     * 単一コードまたは 0xXXXX..0xYYYY 形式を UCS2 の閉区間へ変換する。
     *
     * @return array{0:int, 1:int}
     */
    private function parseCodeRange(string $field, int $lineNumber): array
    {
        $parts = explode('..', $field);

        if (count($parts) > 2) {
            throw new RuntimeException(sprintf('char.def line %d has invalid code range.', $lineNumber));
        }

        $start = $this->parseCode($parts[0], $lineNumber);
        $end = isset($parts[1]) ? $this->parseCode($parts[1], $lineNumber) : $start;

        if ($start > $end) {
            throw new RuntimeException(sprintf('char.def line %d has descending code range.', $lineNumber));
        }

        return [$start, $end];
    }

    /**
     * 0x0000..0xFFFF のコード表記を int の code unit 値へ変換する。
     */
    private function parseCode(string $value, int $lineNumber): int
    {
        if (preg_match('/^0x[0-9A-Fa-f]{4}$/', $value) !== 1) {
            throw new RuntimeException(sprintf('char.def line %d has invalid UCS2 code.', $lineNumber));
        }

        return (int) hexdec(substr($value, 2));
    }

    /**
     * char.def に必須の DEFAULT と SPACE カテゴリが定義されていることを検証する。
     *
     * @param list<array{name:string, invoke:int, group:int, length:int}> $categories
     */
    private function assertRequiredCategories(array $categories): void
    {
        $names = $this->categoryNameMap($categories);

        foreach (['DEFAULT', 'SPACE'] as $requiredName) {
            if (!isset($names[$requiredName])) {
                throw new RuntimeException(sprintf('char.def must define %s category.', $requiredName));
            }
        }
    }

    /**
     * コード範囲定義が既知カテゴリだけを参照し、0x0020 を SPACE に割り当てることを検証する。
     *
     * @param list<array{start:int, end:int, names:list<string>, line:int}> $ranges
     * @param array<string, int> $categoryIndexes
     */
    private function assertRangeCategoryNames(array $ranges, array $categoryIndexes): void
    {
        $spaceAssigned = false;

        foreach ($ranges as $range) {
            foreach ($range['names'] as $name) {
                if (!isset($categoryIndexes[$name])) {
                    throw new RuntimeException(sprintf(
                        'char.def line %d references undefined category "%s".',
                        $range['line'],
                        $name,
                    ));
                }
            }

            if ($range['start'] <= 0x0020 && 0x0020 <= $range['end']) {
                $spaceAssigned = $range['names'][0] === 'SPACE';
            }
        }

        if (!$spaceAssigned) {
            throw new RuntimeException('char.def must assign 0x0020 to SPACE.');
        }
    }

    /**
     * カテゴリ名から char.category 内の添字を引ける対応表を作る。
     *
     * @param list<array{name:string, invoke:int, group:int, length:int}> $categories
     * @return array<string, int>
     */
    private function categoryNameMap(array $categories): array
    {
        $indexes = [];

        foreach ($categories as $index => $category) {
            if (isset($indexes[$category['name']])) {
                throw new RuntimeException(sprintf('char.def category "%s" is duplicated.', $category['name']));
            }

            $indexes[$category['name']] = $index;
        }

        return $indexes;
    }

    /**
     * char.category の 4 int レコードへ、カテゴリ名解決後の trie ID と探索フラグを並べる。
     *
     * @param list<array{name:string, invoke:int, group:int, length:int}> $categories
     * @return list<array{id:int, length:int, invoke:int, group:int}>
     */
    private function categoryRecords(array $categories, string $outputDirectory, string $encoding): array
    {
        $records = [];

        foreach ($categories as $index => $category) {
            $records[] = [
                'id' => $this->resolveCategoryId($outputDirectory, $encoding, $category['name']),
                'length' => $category['length'],
                'invoke' => $category['invoke'],
                'group' => $category['group'],
            ];
        }

        return $records;
    }

    /**
     * resolver を通じてカテゴリ名に対応する未知語 trie ID を取得する。
     */
    private function resolveCategoryId(string $outputDirectory, string $encoding, string $categoryName): int
    {
        $id = $this->categoryIdResolver->resolve($outputDirectory, $encoding, $categoryName);

        if ($id < 0) {
            throw new RuntimeException(sprintf('category id for %s must be non-negative.', $categoryName));
        }

        return $id;
    }

    /**
     * DEFAULT で初期化した UCS2 全域のカテゴリ添字表と互換性マスク表へ範囲指定を反映する。
     *
     * @param list<array{name:string, invoke:int, group:int, length:int}> $categories
     * @param list<array{start:int, end:int, names:list<string>, line:int}> $ranges
     * @return array{charToCategory:list<int>, masks:list<int>}
     */
    private function codeTables(array $categories, array $ranges): array
    {
        $categoryIndexes = $this->categoryNameMap($categories);
        $defaultIndex = $categoryIndexes['DEFAULT'];
        $charToCategory = array_fill(0, self::UCS2_CODE_COUNT, $defaultIndex);
        $masks = array_fill(0, self::UCS2_CODE_COUNT, 1 << $defaultIndex);

        foreach ($ranges as $range) {
            $primaryIndex = $categoryIndexes[$range['names'][0]];
            $mask = 0;

            foreach ($range['names'] as $name) {
                $mask |= 1 << $categoryIndexes[$name];
            }

            for ($code = $range['start']; $code <= $range['end']; $code++) {
                $charToCategory[$code] = $primaryIndex;
                $masks[$code] = $mask;
            }
        }

        return ['charToCategory' => array_values($charToCategory), 'masks' => array_values($masks)];
    }

    /**
     * 出力先が存在しない場合だけ作成し、ファイル書き込み前の前提を満たす。
     */
    private function ensureOutputDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('failed to create output directory "%s".', $directory));
        }
    }

    /**
     * char.category のカテゴリレコードを native endian int の連続バイナリへ変換する。
     *
     * @param list<array{id:int, length:int, invoke:int, group:int}> $records
     */
    private function categoryBinary(array $records): string
    {
        $values = [];

        foreach ($records as $record) {
            $values[] = $record['id'];
            $values[] = $record['length'];
            $values[] = $record['invoke'];
            $values[] = $record['group'];
        }

        return $this->packInts($values);
    }

    /**
     * code2category のカテゴリ添字表と互換性マスク表を runtime の読み取り順に連結する。
     *
     * @param array{charToCategory:list<int>, masks:list<int>} $tables
     */
    private function codeTablesBinary(array $tables): string
    {
        return $this->packInts($tables['charToCategory']) . $this->packInts($tables['masks']);
    }

    /**
     * int 値の列を native endian の連続バイナリへ変換する。
     *
     * @param list<int> $values
     */
    private function packInts(array $values): string
    {
        $binary = '';

        foreach ($values as $value) {
            $binary .= pack('l', $value);
        }

        return $binary;
    }

    /**
     * バイナリファイルを一括で書き込み、短い書き込みを辞書生成失敗として扱う。
     */
    private function writeBinaryFile(string $fileName, string $contents): void
    {
        $writtenBytes = file_put_contents($fileName, $contents);

        if ($writtenBytes !== strlen($contents)) {
            throw new RuntimeException(sprintf('failed to write dictionary file "%s".', $fileName));
        }
    }

    /**
     * char.def の空白区切りフィールドを、連続空白に依存しない形で分割する。
     *
     * @return list<string>
     */
    private function splitFields(string $line): array
    {
        $fields = preg_split('/\s+/', $line);

        if ($fields === false) {
            throw new RuntimeException('char.def parsing failed.');
        }

        return $fields;
    }

    /**
     * カテゴリ名として ASCII の英数字・アンダースコアだけを受け入れる。
     */
    private function isCategoryName(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * invoke/group フラグとして 0 または 1 だけを受け入れる。
     */
    private function isBinaryFlag(string $value): bool
    {
        return $value === '0' || $value === '1';
    }

    /**
     * length 値として 0 以上の 10 進整数だけを受け入れる。
     */
    private function isNonNegativeInteger(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }
}
