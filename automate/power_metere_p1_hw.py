#!/usr/bin/env python3
"""
Power meter readers for automate.

This module isolates power-meter access behind a small normalized interface so
automation logic can swap implementations without changing control flow.
"""

from __future__ import annotations

import json
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any, Dict, Optional

import requests

from config_loader import load_config as load_config_json


API_ENDPOINT_PROPERTIES_REPORT = "/properties/report"
FIELD_TOTAL_POWER = "total_power"
CONFIG_KEY_POWER_METER = "powerMeter"
CONFIG_KEY_POWER_METER_TYPE = "type"
CONFIG_KEY_POWER_METER_P1_HW = "p1_hw"
POWER_METER_TYPE_P1_HW = "p1_hw"
REQUEST_TIMEOUT = 5


_SHARED_POWER_METER_READER = None
_SHARED_POWER_METER_READER_CONFIG_PATH: Optional[Path] = None


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


def get_power_meter_reader(config_path: Optional[Path] = None) -> "PowerMeterReader":
    """
    Return a shared, long-lived PowerMeterReader instance.

    The current implementation selects a built-in P1 reader from config.
    """
    global _SHARED_POWER_METER_READER, _SHARED_POWER_METER_READER_CONFIG_PATH

    resolved_config = (Path(config_path).resolve() if config_path is not None else _find_config_file().resolve())
    if _SHARED_POWER_METER_READER is None:
        _SHARED_POWER_METER_READER = build_power_meter_reader(resolved_config)
        _SHARED_POWER_METER_READER_CONFIG_PATH = resolved_config
        return _SHARED_POWER_METER_READER

    if _SHARED_POWER_METER_READER_CONFIG_PATH is not None and resolved_config != _SHARED_POWER_METER_READER_CONFIG_PATH:
        raise ValueError(
            "Shared PowerMeterReader already initialized with a different config path. "
            f"existing={_SHARED_POWER_METER_READER_CONFIG_PATH}, requested={resolved_config}"
        )

    return _SHARED_POWER_METER_READER


class PowerMeterReader(ABC):
    """Interface for normalized power-meter reads."""

    def __init__(self, config_path: Optional[Path] = None):
        self.config_path = Path(config_path).resolve() if config_path is not None else _find_config_file().resolve()
        self.config = _load_config(self.config_path)

    @abstractmethod
    def read(self) -> Optional[dict]:
        """Return normalized meter data with at least a total_power field."""


class P1PowerMeterReader(PowerMeterReader):
    """Read a P1-style HTTP JSON endpoint and normalize the payload."""

    def __init__(self, config_path: Optional[Path] = None, meter_config: Optional[dict] = None):
        super().__init__(config_path=config_path)
        if not isinstance(meter_config, dict):
            raise ValueError("powerMeter.p1_hw configuration is required and must be an object")

        self.power_meter_ip = meter_config.get("ip")
        if not self.power_meter_ip:
            raise ValueError("powerMeter.p1_hw.ip is required")

        self.power_meter_endpoint = meter_config.get("endpoint", API_ENDPOINT_PROPERTIES_REPORT)
        self.total_power_path = meter_config.get("totalPowerPath", FIELD_TOTAL_POWER)

    def _get_json_value(self, data: dict, path: str):
        keys = path.split(".")
        value = data
        for key in keys:
            if isinstance(value, dict):
                value = value.get(key)
            else:
                return None
            if value is None:
                return None
        return value

    def _get_api_url(self) -> Optional[str]:
        if not self.power_meter_ip:
            return None
        return f"http://{self.power_meter_ip}{self.power_meter_endpoint}"

    def read(self) -> Optional[dict]:
        url = self._get_api_url()
        if not url:
            return None

        try:
            response = requests.get(url, timeout=REQUEST_TIMEOUT)
            response.raise_for_status()
            data = response.json()
        except requests.exceptions.RequestException:
            return None
        except (json.JSONDecodeError, KeyError):
            return None

        total_power = self._get_json_value(data, self.total_power_path)
        result = data.copy()
        result[FIELD_TOTAL_POWER] = total_power
        return result


def build_power_meter_reader(config_path: Optional[Path] = None) -> PowerMeterReader:
    """Build the configured power-meter reader."""
    resolved_config = Path(config_path).resolve() if config_path is not None else _find_config_file().resolve()
    config = _load_config(resolved_config)
    power_meter_config = config.get(CONFIG_KEY_POWER_METER)
    if not isinstance(power_meter_config, dict):
        raise ValueError("powerMeter configuration is required")

    meter_type = power_meter_config.get(CONFIG_KEY_POWER_METER_TYPE)
    if not meter_type:
        raise ValueError("powerMeter.type is required")
    if meter_type != POWER_METER_TYPE_P1_HW:
        raise ValueError(f"Unsupported powerMeter.type '{meter_type}'. Supported types: ['{POWER_METER_TYPE_P1_HW}']")

    p1_config = power_meter_config.get(CONFIG_KEY_POWER_METER_P1_HW)
    return P1PowerMeterReader(config_path=resolved_config, meter_config=p1_config)
