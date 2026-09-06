#!/usr/bin/env python
"""Source-level checks for the authenticated automation-control panel."""

from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
CONTROL_INDEX = REPO_ROOT / "automate" / "control" / "index.php"
COMMANDS = REPO_ROOT / "automate" / "control" / "commands.php"


def test_slow_charge_card_loads_and_renders_the_current_runtime_setting():
    source = CONTROL_INDEX.read_text(encoding="utf-8")

    assert 'id="slowChargeCurrent"' in source
    assert 'id="slowChargeCurrentValue"' in source
    assert "function loadSlowChargeStatus()" in source
    assert "function renderSlowChargeStatus(payload)" in source
    assert "loadSlowChargeStatus();" in source
    assert "renderSlowChargeStatus(payload);" in source
    assert "btn.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');" in source


def test_command_proxy_whitelists_the_slow_charge_status_read():
    source = COMMANDS.read_text(encoding="utf-8")

    assert "'get_slow_charge_max_power' => [" in source
    assert "'method' => 'GET'" in source
    assert "'path' => '/api/slow_charge_max_power'" in source
    assert "'ui' => false" in source
