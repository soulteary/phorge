#!/usr/bin/env bash
set -euo pipefail

# One-shot loop:
# 1) detect missing keys
# 2) auto-apply up to N keys each round
# 3) stop when missing==0 or max rounds reached

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${ROOT_DIR}"

BATCH_SIZE="${BATCH_SIZE:-1000}"
MAX_ROUNDS="${MAX_ROUNDS:-20}"
MISSING_FILE="resources/i18n-zh-missing-top1000.txt"
TOP20_FILE="resources/i18n-zh-missing-top20.txt"

round=1
while [ "${round}" -le "${MAX_ROUNDS}" ]; do
  echo "[round ${round}] checking missing..."
  out="$(python3 scripts/i18n/check_zh_missing.py --top "${BATCH_SIZE}" --out "${MISSING_FILE}")"
  echo "${out}"

  missing="$(printf '%s\n' "${out}" | awk -F= '/^missing=/{print $2}')"
  if [ -z "${missing}" ]; then
    echo "Unable to parse missing count from checker output."
    exit 1
  fi

  if [ "${missing}" -eq 0 ]; then
    echo "No missing keys left."
    python3 scripts/i18n/check_zh_missing.py --top 20 --out "${TOP20_FILE}" >/dev/null
    exit 0
  fi

  echo "[round ${round}] applying up to ${BATCH_SIZE} keys..."
  python3 scripts/i18n/gen_zh_batch.py "${MISSING_FILE}" --auto --apply --limit "${BATCH_SIZE}"

  round=$((round + 1))
done

echo "Reached MAX_ROUNDS=${MAX_ROUNDS}. Refreshing summary file."
python3 scripts/i18n/check_zh_missing.py --top 20 --out "${TOP20_FILE}" >/dev/null
