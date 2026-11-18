from __future__ import annotations

import json
from pathlib import Path
import subprocess


REPO_ROOT = Path(__file__).resolve().parents[2]
PAGE = REPO_ROOT / "app" / "configuration.php"
FIELDS = REPO_ROOT / "app" / "includes" / "system_configuration_fields.php"


def _load_field_groups() -> list[dict]:
    php = (
        f'require {json.dumps(str(REPO_ROOT / "common/php/system_config.php"))};'
        f'require {json.dumps(str(FIELDS))};'
        "echo json_encode(systemConfigurationFieldGroups(loadSystemConfig()), JSON_THROW_ON_ERROR);"
    )
    result = subprocess.run(
        ["php", "-r", php],
        check=False,
        capture_output=True,
        text=True,
        cwd=REPO_ROOT,
    )
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def test_catalog_covers_only_webserver_owned_system_fields():
    groups = _load_field_groups()
    fields = [field for group in groups for field in group["fields"]]
    paths = {field["path"] for field in fields}

    assert len(groups) == 8
    assert len(fields) == 60
    assert "battery.minimumGridArbitrageMarginEurPerKwhCharged" in paths
    assert "priceConversion.vatMultiplier" in paths
    assert "forecast.defaultHouseholdUsageWByHour.23:00" in paths

    assert "schemaVersion" not in paths
    assert "battery.minChargePercent" not in paths
    assert "battery.maxChargePercent" not in paths
    assert "battery.maxChargePowerW" not in paths
    assert "battery.maxDischargePowerW" not in paths
    assert "installation.timezone" not in paths


def test_every_parameter_has_edit_ready_explanatory_metadata():
    groups = _load_field_groups()
    fields = [field for group in groups for field in group["fields"]]

    for field in fields:
        assert field["label"]
        assert field["description"]
        assert field["type"]
        assert field["editability"] in {"planned", "advanced", "protected", "linked"}
        assert field["recommended"] is not None or field["recommended_note"]
        assert field["constraint"]
        assert field["effect"]


def test_catalog_page_is_read_only_and_linked_from_the_app():
    page = PAGE.read_text(encoding="utf-8")
    app_index = (REPO_ROOT / "app/index.php").read_text(encoding="utf-8")
    optimizer = (REPO_ROOT / "app/optimizer.php").read_text(encoding="utf-8")

    assert "systemConfigurationFieldGroups" in page
    assert 'type="search"' in page
    assert 'role="tooltip"' in page
    assert "This page does not write configuration" in page
    assert "<form" not in page
    assert "configuration.php" in app_index
    assert "configuration.php" in optimizer

