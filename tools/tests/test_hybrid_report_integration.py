from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SMART_FILE = REPO_ROOT / "daily_report" / "includes" / "report_smart_common.php"
DAILY_API_FILE = REPO_ROOT / "daily_report" / "api" / "report_data.php"
MONTHLY_API_FILE = REPO_ROOT / "daily_report" / "api" / "monthly_report_data.php"
PNL_API_FILE = REPO_ROOT / "daily_report" / "api" / "pnl_data.php"


def test_smart_loader_uses_live_today_and_aggregate_for_history():
    source = SMART_FILE.read_text(encoding="utf-8")

    assert "function dailyReportLoadSmart" in source
    assert "$date === $today" in source
    assert "dailyReportGenerateLive($date)" in source
    assert "dailyReportLoadFromAggregate" in source
    assert "dailyReportRegenerateAggregate($date)" in source


def test_live_loader_runs_generator_without_output_file():
    source = SMART_FILE.read_text(encoding="utf-8")

    assert "function dailyReportGenerateLive" in source
    assert "DAILY_REPORT_GENERATOR_SCRIPT" in source
    assert "['--date', $date]" in source
    assert "--output" not in source


def test_production_apis_use_smart_loader_not_legacy_json_loader():
    daily_api = DAILY_API_FILE.read_text(encoding="utf-8")
    monthly_api = MONTHLY_API_FILE.read_text(encoding="utf-8")
    pnl_api = PNL_API_FILE.read_text(encoding="utf-8")

    for source in [daily_api, monthly_api, pnl_api]:
        assert "report_smart_common.php" in source
        assert "dailyReportLoadSmart" in source
        assert "dailyReportLoadOrGenerate" not in source


def test_monthly_and_pnl_preserve_report_pnl_helpers():
    monthly_api = MONTHLY_API_FILE.read_text(encoding="utf-8")
    pnl_api = PNL_API_FILE.read_text(encoding="utf-8")

    assert "dailyReportBuildPnlDayPayload" in monthly_api
    assert "dailyReportBuildPnlDayPayload" in pnl_api
