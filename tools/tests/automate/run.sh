#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

TMP_OUTPUT="$(mktemp)"
cleanup() {
  rm -f "$TMP_OUTPUT"
}
trap cleanup EXIT

set +e
pytest -v . 2>&1 | tee "$TMP_OUTPUT"
PYTEST_EXIT=${PIPESTATUS[0]}
set -e

SUMMARY_LINE="$(grep -E '^[=]+ .+ in [0-9.]+s [=]+$' "$TMP_OUTPUT" | tail -n 1 || true)"

if [[ -z "$SUMMARY_LINE" ]]; then
  echo ""
  echo "SUMMARY: unable to parse pytest summary"
  exit "$PYTEST_EXIT"
fi

PASSED_COUNT="$(echo "$SUMMARY_LINE" | grep -oE '[0-9]+ passed' | awk '{sum += $1} END {print sum + 0}')"
FAILED_COUNT="$(echo "$SUMMARY_LINE" | { grep -oE '[0-9]+ failed' || true; } | awk '{sum += $1} END {print sum + 0}')"
ERROR_COUNT="$(echo "$SUMMARY_LINE" | { grep -oE '[0-9]+ error[s]?' || true; } | awk '{sum += $1} END {print sum + 0}')"
TOTAL_RUN=$((PASSED_COUNT + FAILED_COUNT + ERROR_COUNT))

echo ""
echo "SUMMARY: ran ${TOTAL_RUN} tests, ${PASSED_COUNT} passed, $((FAILED_COUNT + ERROR_COUNT)) failed"

exit "$PYTEST_EXIT"
