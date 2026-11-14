#!/usr/bin/env python
"""Source checks for the new GUI's Zendure calibration state."""

from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
STATUS_JS = REPO_ROOT / "app" / "assets" / "js" / "current-energy-status.js"
APP_CSS = REPO_ROOT / "app" / "assets" / "css" / "app.css"


def test_current_energy_status_recognizes_and_explains_auto_calibration():
    source = STATUS_JS.read_text(encoding="utf-8")

    assert "finiteNumber(properties.socStatus, 0) === 1" in source
    assert 'label: "Automatic calibration"' in source
    assert "temporarily overriding schedule control" in source
    assert "if (model.calibrationActive) return false;" in source
    assert "component.dataset.calibrationActive" in source
    assert "elements.powerHero.dataset.calibrationActive" in source
    assert 'label: "Calibration active"' in source


def test_calibration_state_uses_graphite_warning_tokens():
    source = APP_CSS.read_text(encoding="utf-8")

    assert '.app-power-hero[data-calibration-active="true"]' in source
    assert '.app-energy[data-calibration-active="true"] .app-target-label' in source
    assert "--app-mode-color: var(--gsd-warning);" in source
    assert "background: var(--gsd-warning-soft);" in source
