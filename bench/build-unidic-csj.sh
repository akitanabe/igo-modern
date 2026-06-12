#!/usr/bin/env bash
# UniDic CSJ full 辞書ビルドの時間・ピークメモリ計測スクリプト（issue #9）。
# 使い方: bench/build-unidic-csj.sh <ラベル> [memory_limit]
# 例:     bench/build-unidic-csj.sh baseline 4G
set -euo pipefail

cd "$(dirname "$0")/.."

LABEL="${1:?usage: bench/build-unidic-csj.sh <label> [memory_limit]}"
MEMORY_LIMIT="${2:-4G}"
INPUT_DIR="dist/unidic-csj-202512_full"
OUTPUT_DIR="dist/igo-unidic-csj-${LABEL}"
LOG_FILE="bench/results/unidic-csj-build-${LABEL}.log"

mkdir -p "$OUTPUT_DIR"

# Xdebug はビルド速度を数倍劣化させるため計測時は必ず無効化する。
/usr/bin/time -v php -d xdebug.mode=off -d memory_limit="$MEMORY_LIMIT" \
    bin/igo build-dic \
    -o "$OUTPUT_DIR" \
    -i "$INPUT_DIR" \
    -e UTF-8 \
    >"$LOG_FILE" 2>&1

echo "EXIT=$? label=${LABEL} memory_limit=${MEMORY_LIMIT}"
grep -E 'Elapsed \(wall clock\)|Maximum resident set size|Exit status' "$LOG_FILE"
