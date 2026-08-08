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
echo "==> Running target battery planner tests"
if php "$SCRIPT_DIR/target_battery_planner_test.php"; then
  :
else
  FAILED=1
  FAILED_SUITES+=("target_battery_planner")
fi

echo ""
echo "==> Running historical backtest tests"
if php "$SCRIPT_DIR/backtest_schedule_test.php"; then
  :
else
  FAILED=1
  FAILED_SUITES+=("historical_backtest")
fi

echo ""
echo "==> Running app battery forecast tests"
if node "$SCRIPT_DIR/app_battery_forecast_test.js"; then
  :
else
  FAILED=1
  FAILED_SUITES+=("app_battery_forecast")
fi

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "SUMMARY: ALL OK"
  exit 0
fi

echo "SUMMARY: FAILED (${FAILED_SUITES[*]})"
exit 1
