#!/usr/bin/env bash
set -u -o pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
FAILED=0
FAILED_SUITES=()

echo "==> Running automate runtime tests"
if (cd "$SCRIPT_DIR/automate" && ./run.sh); then
  :
else
  FAILED=1
  FAILED_SUITES+=("automate")
fi

echo ""
echo "==> Running data API tests"
if python "$SCRIPT_DIR/main_data_api_test.py"; then
  :
else
  FAILED=1
  FAILED_SUITES+=("main_data_api")
fi

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "SUMMARY: ALL OK"
  exit 0
fi

echo "SUMMARY: FAILED (${FAILED_SUITES[*]})"
exit 1
