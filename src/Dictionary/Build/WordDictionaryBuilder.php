<?php

declare(strict_types=1);

namespace IgoModern\Dictionary\Build;

use RuntimeException;

/**
 * MeCab 互換の単語定義から word2id、word.inf、word.dat、word.ary.idx を生成する。
 */
class WordDictionaryBuilder implements DictionaryBuildStep
{
    /**
     * unk.def と CSV を読み込み、WordDic が読む単語辞書ファイル群を生成する。
     */
    public function build(string $outputDirectory, string $inputDirectory, string $encoding, string $delimiter): void
    {
        $this->assertDelimiter($delimiter);

        $entries = $this->readEntries($inputDirectory, $encoding, $delimiter);
        $keys = $this->trieKeys($inputDirectory, $encoding, $entries);
        $groupedEntries = $this->entriesByTrieId($entries, $keys);

        $this->ensureOutputDirectory($outputDirectory);
        (new DoubleArrayTrieBuilder())->build($keys, $outputDirectory . '/word2id');
        $this->writeWordFiles($outputDirectory, $groupedEntries, count($keys));
    }

    /**
     * CSV parser の契約に合わせ、辞書生成 API では 1 文字 delimiter だけを受け入れる。
     */
    private function assertDelimiter(string $delimiter): void
    {
        if (strlen($delimiter) !== 1) {
            throw new RuntimeException('delimiter must be a single-character string.');
        }
    }

    /**
     * unk.def と入力ディレクトリ直下の CSV ファイルを読み、単語レコード候補へ変換する。
     *
     * @return list<array{key:string, leftId:int, rightId:int, cost:int, feature:string}>
     */
    private function readEntries(string $inputDirectory, string $encoding, string $delimiter): array
    {
        $entries = [];

        foreach ($this->readUnknownEntries($inputDirectory, $encoding, $delimiter) as $entry) {
            $entries[] = $entry;
        }

        foreach ($this->csvFileNames($inputDirectory) as $fileName) {
            foreach ($this->readCsvEntries($fileName, $encoding, $delimiter) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * unk.def のカテゴリ行を、"\002" prefix 付きカテゴリキーの単語レコードへ変換する。
     *
     * @return list<array{key:string, leftId:int, rightId:int, cost:int, feature:string}>
     */
    private function readUnknownEntries(string $inputDirectory, string $encoding, string $delimiter): array
    {
        $fileName = $inputDirectory . '/unk.def';
        $entries = [];

        foreach ($this->definitionLines($fileName, $encoding) as $line) {
            $fields = $this->parseSeparatedFields($line['text'], $delimiter, $line['number'], 'unk.def');

            if (count($fields) < 5) {
                throw new RuntimeException(sprintf(
                    'unk.def line %d must contain category, ids, cost, and feature.',
                    $line['number'],
                ));
            }

            $entries[] = $this->entryFromFields(
                "\002" . $fields[0],
                array_slice($fields, offset: 1),
                $delimiter,
                'unk.def',
                $line['number'],
            );
        }

        return $entries;
    }

    /**
     * 通常単語 CSV 行を、表層形をキーにした単語レコードへ変換する。
     *
     * @return list<array{key:string, leftId:int, rightId:int, cost:int, feature:string}>
     */
    private function readCsvEntries(string $fileName, string $encoding, string $delimiter): array
    {
        $entries = [];

        foreach ($this->definitionLines($fileName, $encoding) as $line) {
            $fields = $this->parseSeparatedFields($line['text'], $delimiter, $line['number'], basename($fileName));

            if (count($fields) < 5) {
                throw new RuntimeException(sprintf(
                    '%s line %d must contain surface, ids, cost, and feature.',
                    basename($fileName),
                    $line['number'],
                ));
            }

            $entries[] = $this->entryFromFields(
                $fields[0],
                array_slice($fields, offset: 1),
                $delimiter,
                basename($fileName),
                $line['number'],
            );
        }

        return $entries;
    }

    /**
     * 文脈 ID、コスト、素性フィールドを検証し、WordDic 用の単語レコードへ整える。
     *
     * @param list<string> $fields
     * @return array{key:string, leftId:int, rightId:int, cost:int, feature:string}
     */
    private function entryFromFields(
        string $key,
        array $fields,
        string $delimiter,
        string $sourceName,
        int $lineNumber,
    ): array {
        if (
            count($fields) < 4
            || !$this->isInteger($fields[0])
            || !$this->isInteger($fields[1])
            || !$this->isInteger($fields[2])
        ) {
            throw new RuntimeException(sprintf('%s line %d has invalid word ids or cost.', $sourceName, $lineNumber));
        }

        if ($key === '') {
            throw new RuntimeException(sprintf('%s line %d has empty trie key.', $sourceName, $lineNumber));
        }

        $leftId = (int) $fields[0];
        $rightId = (int) $fields[1];
        $cost = (int) $fields[2];

        if ($leftId < 0 || $rightId < 0) {
            throw new RuntimeException(sprintf('%s line %d word ids must be non-negative.', $sourceName, $lineNumber));
        }

        if (!$this->isSignedShort($leftId) || !$this->isSignedShort($rightId) || !$this->isSignedShort($cost)) {
            throw new RuntimeException(sprintf(
                '%s line %d word ids or cost is outside signed short range.',
                $sourceName,
                $lineNumber,
            ));
        }

        return [
            'key' => $key,
            'leftId' => $leftId,
            'rightId' => $rightId,
            'cost' => $cost,
            'feature' => implode($delimiter, array_slice($fields, offset: 3)),
        ];
    }

    /**
     * char.def のカテゴリ名と単語レコードの表層形から、trie ID 付きキー集合を作る。
     *
     * @param list<array{key:string, leftId:int, rightId:int, cost:int, feature:string}> $entries
     * @return array<string, int>
     */
    private function trieKeys(string $inputDirectory, string $encoding, array $entries): array
    {
        $keys = [];

        foreach ($this->categoryNames($inputDirectory . '/char.def', $encoding) as $categoryName) {
            $this->appendTrieKey($keys, "\002" . $categoryName);
        }

        foreach ($entries as $entry) {
            $this->appendTrieKey($keys, $entry['key']);
        }

        return $keys;
    }

    /**
     * 未登録キーだけに次の trie ID を割り当て、既存キーの ID を安定させる。
     *
     * @param array<string, int> $keys
     */
    private function appendTrieKey(array &$keys, string $key): void
    {
        if (isset($keys[$key])) {
            return;
        }

        $keys[$key] = count($keys);
    }

    /**
     * char.def のカテゴリ定義行からカテゴリ名を定義順に取り出す。
     *
     * @return list<string>
     */
    private function categoryNames(string $fileName, string $encoding): array
    {
        $names = [];

        foreach ($this->definitionLines($fileName, $encoding) as $line) {
            if (str_starts_with($line['text'], '0x')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line['text']);

            if ($fields === false || $fields === [] || $fields[0] === '') {
                throw new RuntimeException(sprintf(
                    'char.def line %d has invalid category definition.',
                    $line['number'],
                ));
            }

            $names[] = $fields[0];
        }

        return $names;
    }

    /**
     * 単語レコードを trie ID ごとの範囲に並べ替え、word.ary.idx と word.inf の契約に合わせる。
     *
     * @param list<array{key:string, leftId:int, rightId:int, cost:int, feature:string}> $entries
     * @param array<string, int> $keys
     * @return list<list<array{key:string, leftId:int, rightId:int, cost:int, feature:string}>>
     */
    private function entriesByTrieId(array $entries, array $keys): array
    {
        $groupedEntries = array_fill(0, count($keys), []);

        foreach ($entries as $entry) {
            $groupedEntries[$keys[$entry['key']]][] = $entry;
        }

        return $groupedEntries;
    }

    /**
     * word.dat、word.ary.idx、word.inf を trie ID 順の単語レコードから生成する。
     *
     * @param list<list<array{key:string, leftId:int, rightId:int, cost:int, feature:string}>> $groupedEntries
     */
    private function writeWordFiles(string $outputDirectory, array $groupedEntries, int $keyCount): void
    {
        $indices = [];
        $dataOffsets = [];
        $leftIds = [];
        $rightIds = [];
        $costs = [];
        $wordData = '';
        $wordId = 0;

        for ($trieId = 0; $trieId < $keyCount; $trieId++) {
            $indices[] = $wordId;

            foreach ($groupedEntries[$trieId] as $entry) {
                $dataOffsets[] = intdiv(strlen($wordData), num2: 2);
                $leftIds[] = $entry['leftId'];
                $rightIds[] = $entry['rightId'];
                $costs[] = $entry['cost'];
                $wordData .= mb_convert_encoding($entry['feature'], to_encoding: 'UTF-16LE', from_encoding: 'UTF-8');
                $wordId++;
            }
        }

        $indices[] = $wordId;
        $dataOffsets[] = intdiv(strlen($wordData), num2: 2);
        $leftIds[] = 0;
        $rightIds[] = 0;
        $costs[] = 0;

        $this->writeBinaryFile($outputDirectory . '/word.dat', $wordData);
        $this->writeBinaryFile($outputDirectory . '/word.ary.idx', $this->packInts($indices));
        $this->writeBinaryFile(
            $outputDirectory . '/word.inf',
            $this->packInts($dataOffsets) . $this->packShorts($leftIds) . $this->packShorts($rightIds)
                . $this->packShorts($costs),
        );
    }

    /**
     * 入力ディレクトリ直下の CSV ファイル名を安定した順序で列挙する。
     *
     * @return list<string>
     */
    private function csvFileNames(string $inputDirectory): array
    {
        $fileNames = glob($inputDirectory . '/*.csv');

        if ($fileNames === false) {
            throw new RuntimeException(sprintf('failed to list CSV files in "%s".', $inputDirectory));
        }

        sort($fileNames);

        return $fileNames;
    }

    /**
     * 入力ファイルを指定エンコーディングから UTF-8 の非空・非コメント行へ変換する。
     *
     * @return list<array{number:int, text:string}>
     */
    private function definitionLines(string $fileName, string $encoding): array
    {
        $lines = file($fileName, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('failed to read "%s".', $fileName));
        }

        $definitionLines = [];

        foreach ($lines as $index => $line) {
            $utf8Line = $this->toUtf8($line, $encoding);
            $trimmed = trim($utf8Line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $definitionLines[] = ['number' => $index + 1, 'text' => $trimmed];
        }

        return $definitionLines;
    }

    /**
     * 指定エンコーディングの入力行を内部処理用の UTF-8 へ変換する。
     */
    private function toUtf8(string $line, string $encoding): string
    {
        if (strcasecmp($encoding, 'UTF-8') === 0 || strcasecmp($encoding, 'UTF8') === 0) {
            return $line;
        }

        $converted = mb_convert_encoding($line, to_encoding: 'UTF-8', from_encoding: $encoding);

        if ($converted === false) {
            throw new RuntimeException(sprintf('failed to convert input from "%s" to UTF-8.', $encoding));
        }

        return $converted;
    }

    /**
     * delimiter 区切りの行を CSV 規則で分解し、パース失敗を辞書生成エラーにする。
     *
     * @return list<string>
     */
    private function parseSeparatedFields(string $line, string $delimiter, int $lineNumber, string $sourceName): array
    {
        $this->assertCsvQuotesClosed($line, $sourceName, $lineNumber);

        return $this->trimFields(str_getcsv($line, $delimiter), $sourceName, $lineNumber);
    }

    /**
     * CSV の引用フィールドが 1 行内で閉じていることを確認し、寛容な parser の前に壊れた行を止める。
     */
    private function assertCsvQuotesClosed(string $line, string $sourceName, int $lineNumber): void
    {
        $insideQuotedField = false;
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            if ($line[$index] !== '"') {
                continue;
            }

            if ($insideQuotedField && ($index + 1) < $length && $line[$index + 1] === '"') {
                $index++;
                continue;
            }

            $insideQuotedField = !$insideQuotedField;
        }

        if ($insideQuotedField) {
            throw new RuntimeException(sprintf('%s line %d could not be parsed.', $sourceName, $lineNumber));
        }
    }

    /**
     * CSV parser から返った nullable field を検証し、辞書行として扱う文字列リストへ整える。
     *
     * @param list<string|null> $fields
     * @return list<string>
     */
    private function trimFields(array $fields, string $sourceName, int $lineNumber): array
    {
        $trimmedFields = [];

        foreach ($fields as $field) {
            if ($field === null) {
                throw new RuntimeException(sprintf('%s line %d could not be parsed.', $sourceName, $lineNumber));
            }

            $trimmedFields[] = trim($field);
        }

        return $trimmedFields;
    }

    /**
     * 出力先が存在しない場合だけ作成し、ファイル書き込み前の前提を満たす。
     */
    private function ensureOutputDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, permissions: 0777, recursive: true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('failed to create output directory "%s".', $directory));
        }
    }

    /**
     * int 値の列を native endian の連続バイナリへ変換する。
     *
     * spread 演算子の引数上限を避けるため 10,000 要素ずつ処理する。
     * array_chunk による入力配列全体の複製を避けるため、array_slice で
     * 1 チャンクずつ切り出して pack('l*', ...) し、バイナリ文字列へ逐次追記する。
     * 出力バイト列は要素ごとに pack('l', $v) した素朴実装と完全一致する。
     *
     * @param list<int> $values
     */
    private function packInts(array $values): string
    {
        $count = count($values);
        $binary = '';

        for ($offset = 0; $offset < $count; $offset += 10_000) {
            $binary .= pack('l*', ...array_slice($values, offset: $offset, length: 10_000));
        }

        return $binary;
    }

    /**
     * signed short 値の列を native endian の連続バイナリへ変換する。
     *
     * spread 演算子の引数上限を避けるため 10,000 要素ずつ処理する。
     * array_chunk による入力配列全体の複製を避けるため、array_slice で
     * 1 チャンクずつ切り出して pack('s*', ...) し、バイナリ文字列へ逐次追記する。
     * 出力バイト列は要素ごとに pack('s', $v) した素朴実装と完全一致する。
     *
     * @param list<int> $values
     */
    private function packShorts(array $values): string
    {
        $count = count($values);
        $binary = '';

        for ($offset = 0; $offset < $count; $offset += 10_000) {
            $binary .= pack('s*', ...array_slice($values, offset: $offset, length: 10_000));
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
     * 符号付き 10 進整数として読める値だけを ID・コストに受け入れる。
     */
    private function isInteger(string $value): bool
    {
        return preg_match('/^-?\d+$/', $value) === 1;
    }

    /**
     * word.inf の signed short フィールドへ損失なく保存できる範囲だけを受け入れる。
     */
    private function isSignedShort(int $value): bool
    {
        return $value >= -32_768 && $value <= 32_767;
    }
}
