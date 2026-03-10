#!/usr/bin/env python3
"""
Config-driven power meter loader for automate.
"""

from __future__ import annotations

import importlib
import re
from pathlib import Path
from types import ModuleType
from typing import Any, Dict, Optional

from config_loader import load_config as load_config_json


CONFIG_KEY_POWER_METER = "powerMeter"
CONFIG_KEY_POWER_METER_TYPE = "type"
MODULE_PREFIX = "power_metere_"
VALID_IDENTIFIER_RE = re.compile(r"^[a-z][a-z0-9_]*$")


def _find_config_file() -> Path:
    script_dir = Path(__file__).parent
    config_path = script_dir / "config" / "config.jsonc"
    if not config_path.exists():
        raise FileNotFoundError(
            f"Config file not found: {config_path}\n"
            "   Automate uses automate/config/config.jsonc only."
        )
    return config_path


def _load_config(config_path: Path) -> Dict[str, Any]:
    return load_config_json(config_path)


def _get_identifier(config_path: Optional[Path] = None) -> str:
    resolved_config = Path(config_path).resolve() if config_path is not None else _find_config_file().resolve()
    config = _load_config(resolved_config)
    power_meter_config = config.get(CONFIG_KEY_POWER_METER)
    if not isinstance(power_meter_config, dict):
        raise ValueError("powerMeter configuration is required")

    identifier = power_meter_config.get(CONFIG_KEY_POWER_METER_TYPE)
    if not identifier:
        raise ValueError("powerMeter.type is required")
    identifier = str(identifier).strip()
    if not VALID_IDENTIFIER_RE.fullmatch(identifier):
        raise ValueError(
            f"Invalid powerMeter.type '{identifier}'. Only lowercase letters, digits, and underscores are allowed."
        )
    return identifier


def _load_reader_module(config_path: Optional[Path] = None) -> ModuleType:
    identifier = _get_identifier(config_path=config_path)
    module_name = f"{MODULE_PREFIX}{identifier}"
    try:
        module = importlib.import_module(module_name)
    except ModuleNotFoundError as exc:
        if exc.name == module_name:
            raise ValueError(
                f"Unsupported powerMeter.type '{identifier}': expected module '{module_name}.py'"
            ) from exc
        raise

    if not hasattr(module, "get_power_meter_reader"):
        raise ValueError(f"Power meter module '{module_name}' is missing get_power_meter_reader")
    return module


def get_power_meter_reader(config_path: Optional[Path] = None):
    module = _load_reader_module(config_path=config_path)
    return module.get_power_meter_reader(config_path)
