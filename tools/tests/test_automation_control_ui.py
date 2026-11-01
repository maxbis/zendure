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


def test_control_mode_panel_loads_authoritative_state_and_updates_button_state():
    control_source = CONTROL_INDEX.read_text(encoding="utf-8")
    command_source = COMMANDS.read_text(encoding="utf-8")

    assert 'id="pauseCurrent"' in control_source
    assert 'id="pauseCurrentValue"' in control_source
    assert "function loadPauseStatus()" in control_source
    assert "function renderPauseStatus(payload)" in control_source
    assert "loadPauseStatus();" in control_source
    assert "renderPauseStatus(payload);" in control_source
    assert "type: 'zendure:control-mode-status'" in control_source
    assert "'get_control_mode' => [" in command_source
    assert "'path' => '/api/control_mode'" in command_source
    assert "'external_control_on' => [" in command_source
    assert "'external_control_release' => [" in command_source
    assert 'External control — automation is monitoring only' in control_source
    assert "'label' => 'Pause Automation'" in command_source
    assert "'label' => 'Resume Automation'" in command_source
    assert "'label' => 'Enable External Control'" in command_source
    assert "'label' => 'Return to Automation (Paused)'" in command_source


def test_main_and_classic_guis_render_explicit_control_mode_status():
    app_index = APP_INDEX.read_text(encoding="utf-8")
    app_status = APP_STATUS.read_text(encoding="utf-8")
    classic_renderer = CLASSIC_RENDERER.read_text(encoding="utf-8")

    assert 'data-role="pause-state"' not in app_index
    assert 'data-role="power-simple-pause-state"' not in app_index
    assert 'controlMode === "external"' in app_status
    assert 'visibleModeLabel = externalControl' in app_status
    assert '"External control"' in app_status
    assert '"Schedule control is temporarily disabled"' in app_status
    assert 'controlModeStatusUrl: "../automate/control/command.php?command=get_control_mode"' in app_status
    assert "const directControlMode" in app_status
    assert 'event.data?.type !== "zendure:control-mode-status"' in app_status
    assert "data.controlMode" in classic_renderer
    assert "External control" in classic_renderer
