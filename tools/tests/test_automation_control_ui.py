#!/usr/bin/env python
"""Source-level checks for the authenticated automation-control panel."""

from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
CONTROL_INDEX = REPO_ROOT / "automate" / "control" / "index.php"
COMMANDS = REPO_ROOT / "automate" / "control" / "commands.php"
APP_INDEX = REPO_ROOT / "app" / "index.php"
APP_STATUS = REPO_ROOT / "app" / "assets" / "js" / "current-energy-status.js"
CLASSIC_RENDERER = REPO_ROOT / "main" / "assets" / "js" / "schedule_renderer.js"


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


def test_pause_control_loads_authoritative_state_and_updates_button_state():
    control_source = CONTROL_INDEX.read_text(encoding="utf-8")
    command_source = COMMANDS.read_text(encoding="utf-8")

    assert 'id="pauseCurrent"' in control_source
    assert 'id="pauseCurrentValue"' in control_source
    assert "function loadPauseStatus()" in control_source
    assert "function renderPauseStatus(payload)" in control_source
    assert "loadPauseStatus();" in control_source
    assert "renderPauseStatus(payload);" in control_source
    assert "type: 'zendure:pause-status'" in control_source
    assert "'get_pause_status' => [" in command_source
    assert "'path' => '/api/pause'" in command_source


def test_main_and_classic_guis_render_explicit_pause_status():
    app_index = APP_INDEX.read_text(encoding="utf-8")
    app_status = APP_STATUS.read_text(encoding="utf-8")
    classic_renderer = CLASSIC_RENDERER.read_text(encoding="utf-8")

    assert 'data-role="pause-state"' in app_index
    assert 'data-role="power-simple-pause-state"' in app_index
    assert 'visibleModeLabel = pauseActive ? "Automation paused" : copy.label' in app_status
    assert '"Schedule control is temporarily disabled"' in app_status
    assert 'pauseStatusUrl: "../automate/control/command.php?command=get_pause_status"' in app_status
    assert "const directPauseAvailable" in app_status
    assert 'event.data?.type !== "zendure:pause-status"' in app_status
    assert "typeof data.pauseActive === 'boolean'" in classic_renderer
    assert "Pause status unknown" in classic_renderer
