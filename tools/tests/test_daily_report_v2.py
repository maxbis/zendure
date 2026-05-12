from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
PAGE_FILE = REPO_ROOT / "daily_report" / "daily_report_v2.php"
API_FILE = REPO_ROOT / "daily_report" / "api" / "report_data_v2.php"
SMART_FILE = REPO_ROOT / "daily_report" / "includes" / "report_smart_common.php"
INDEX_FILE = REPO_ROOT / "daily_report" / "index.php"


def test_daily_report_v2_page_points_to_v2_api_and_reuses_assets():
    page = PAGE_FILE.read_text(encoding="utf-8")

    assert "Daily Report V2" in page
    assert "assets/css/daily_report.css" in page
    assert "assets/js/daily_report.js" in page
    assert "api/report_data_v2.php" in page


def test_daily_report_v2_does_not_modify_current_daily_entrypoint():
    index = INDEX_FILE.read_text(encoding="utf-8")

    assert "api/report_data.php" in index
    assert "api/report_data_v2.php" not in index


def test_report_data_v2_reads_hourly_aggregate_not_raw_status_updates():
    api = API_FILE.read_text(encoding="utf-8")
    smart = SMART_FILE.read_text(encoding="utf-8")

    assert "dailyReportLoadFromAggregate" in api
    assert "FROM hourly_report_inputs" in smart
    assert "status_updates" not in api
    assert "price_ticks" not in api


def test_report_data_v2_keeps_frontend_payload_shape_and_missing_row_defaults():
    api = API_FILE.read_text(encoding="utf-8")
    smart = SMART_FILE.read_text(encoding="utf-8")

    expected_keys = [
        "'success'",
        "'requestedDate'",
        "'source'",
        "'canRegenerate'",
        "'savedAt'",
        "'report'",
        "'hours'",
        "'totals'",
        "'price_file_found'",
        "'price_hours_available'",
        "'price_source'",
    ]
    for key in expected_keys:
        assert key in api or key in smart

    assert "$row = $rowsByHour[$hour] ?? null;" in smart
    assert "$chargedWh = dailyReportFloatValue($row['charged_wh'] ?? null) ?? 0.0;" in smart
    assert "$dischargedWh = dailyReportFloatValue($row['discharged_wh'] ?? null) ?? 0.0;" in smart


def test_report_data_v2_regenerate_runs_hourly_inputs_updater_for_date():
    api = API_FILE.read_text(encoding="utf-8")

    assert "dailyReportRegenerateAggregate($requestedDate)" in api
    assert "update_hourly_report_inputs.py" in SMART_FILE.read_text(encoding="utf-8")
    assert "aggregate_regenerated_manual" in api
