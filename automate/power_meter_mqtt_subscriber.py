#!/usr/bin/env python3
"""
MQTT-backed Shelly power meter subscriber/cache for automate_mqtt.
"""

from __future__ import annotations

import json
import threading
import time
from pathlib import Path
from typing import Any, Optional

from config_loader import load_config as load_config_json

try:
    import paho.mqtt.client as mqtt
except ImportError:  # pragma: no cover - handled by start()
    mqtt = None


CONFIG_KEY_MQTT_POWER_METER = "mqttPowerMeter"
DEFAULT_BROKER_PORT = 1883
DEFAULT_STALE_AFTER_SECONDS = 55
DEFAULT_CHANGE_THRESHOLD_WATTS = 0
DEFAULT_KEEPALIVE_SECONDS = 60


def _find_config_file() -> Path:
    script_dir = Path(__file__).parent
    config_path = script_dir / "config" / "config.jsonc"
    if not config_path.exists():
        raise FileNotFoundError(
            f"Config file not found: {config_path}\n"
            "   Automate uses automate/config/config.jsonc only."
        )
    return config_path


def _load_config(config_path: Path) -> dict[str, Any]:
    return load_config_json(config_path)


def _get_json_value(data: dict[str, Any], path: str) -> Any:
    keys = str(path or "").split(".")
    value: Any = data
    for key in keys:
        if not key:
            continue
        if isinstance(value, dict):
            value = value.get(key)
        else:
            return None
        if value is None:
            return None
    return value


class MqttPowerMeterSubscriber:
    """Maintain a cached latest MQTT reading and expose change/staleness helpers."""

    def __init__(self, config_path: Optional[Path] = None):
        self.config_path = Path(config_path).resolve() if config_path is not None else _find_config_file().resolve()
        self.config = _load_config(self.config_path)
        self.mqtt_config = self.config.get(CONFIG_KEY_MQTT_POWER_METER) or {}

        self.enabled = bool(self.mqtt_config.get("enabled", False))
        self.broker_host = str(self.mqtt_config.get("brokerHost", "")).strip()
        self.broker_port = self._parse_int(self.mqtt_config.get("brokerPort"), DEFAULT_BROKER_PORT)
        self.username = self._normalize_optional_string(self.mqtt_config.get("username"))
        self.password = self._normalize_optional_string(self.mqtt_config.get("password"))
        self.topic = str(self.mqtt_config.get("topic", "")).strip()
        self.total_power_path = str(self.mqtt_config.get("totalPowerPath", "total_act_power")).strip()
        self.stale_after_seconds = self._parse_int(
            self.mqtt_config.get("staleAfterSeconds"),
            DEFAULT_STALE_AFTER_SECONDS,
        )
        self.change_threshold_watts = max(
            0,
            self._parse_int(
                self.mqtt_config.get("changeThresholdWatts"),
                DEFAULT_CHANGE_THRESHOLD_WATTS,
            ),
        )

        self._lock = threading.Lock()
        self._client: Any = None
        self._connected = False
        self._running = False
        self._power_change_event = False
        self._wake_event = threading.Event()
        self._last_raw_payload: Optional[str] = None
        self._last_payload: Optional[dict[str, Any]] = None
        self._last_total_power: Optional[int] = None
        self._last_message_timestamp: Optional[float] = None
        self._message_count = 0
        self._last_delta_watts: Optional[int] = None
        self._last_triggered_change = False

    @staticmethod
    def _parse_int(raw: Any, default: int) -> int:
        try:
            return int(raw)
        except (TypeError, ValueError):
            return default

    @staticmethod
    def _normalize_optional_string(raw: Any) -> Optional[str]:
        if raw is None:
            return None
        value = str(raw).strip()
        return value or None

    def _require_runtime_config(self) -> None:
        if not self.enabled:
            return
        if mqtt is None:
            raise RuntimeError("mqttPowerMeter.enabled is true but paho-mqtt is not installed")
        if not self.broker_host:
            raise ValueError("mqttPowerMeter.brokerHost is required when mqttPowerMeter.enabled is true")
        if not self.topic:
            raise ValueError("mqttPowerMeter.topic is required when mqttPowerMeter.enabled is true")

    def _build_client(self):
        client = mqtt.Client()
        if self.username is not None:
            client.username_pw_set(self.username, self.password)
        client.on_connect = self._on_connect
        client.on_disconnect = self._on_disconnect
        client.on_message = self._on_message
        return client

    def start(self) -> None:
        """Start the MQTT subscriber if enabled."""
        self._require_runtime_config()
        if not self.enabled:
            return
        with self._lock:
            if self._running:
                return
            self._client = self._build_client()
            self._running = True
        self._client.connect_async(self.broker_host, self.broker_port, DEFAULT_KEEPALIVE_SECONDS)
        self._client.loop_start()

    def stop(self) -> None:
        """Stop the MQTT client loop."""
        client = None
        with self._lock:
            client = self._client
            self._running = False
            self._connected = False
            self._client = None
            self._wake_event.clear()
        if client is None:
            return
        try:
            client.loop_stop()
        except Exception:
            pass
        try:
            client.disconnect()
        except Exception:
            pass

    def is_enabled(self) -> bool:
        return self.enabled

    def is_connected(self) -> bool:
        with self._lock:
            return self._connected

    def get_latest_reading(self) -> Optional[dict[str, Any]]:
        with self._lock:
            if self._last_payload is None:
                return None
            return dict(self._last_payload)

    def get_latest_total_power(self) -> Optional[int]:
        with self._lock:
            return self._last_total_power

    def get_last_message_timestamp(self) -> Optional[float]:
        with self._lock:
            return self._last_message_timestamp

    def get_stale_after_seconds(self) -> int:
        return self.stale_after_seconds

    def get_topic(self) -> str:
        return self.topic

    def is_stale(self, max_age_seconds: Optional[int] = None) -> bool:
        max_age = self.stale_after_seconds if max_age_seconds is None else int(max_age_seconds)
        ts = self.get_last_message_timestamp()
        if ts is None:
            return True
        return (time.time() - ts) > max(1, max_age)

    def get_status_snapshot(self, max_age_seconds: Optional[int] = None) -> dict[str, Any]:
        with self._lock:
            last_message_timestamp = self._last_message_timestamp
            total_power = self._last_total_power
            message_count = self._message_count
            connected = self._connected
            last_delta_watts = self._last_delta_watts
            last_triggered_change = self._last_triggered_change

        age_seconds = None
        if last_message_timestamp is not None:
            age_seconds = max(0.0, time.time() - last_message_timestamp)
        stale_limit = self.stale_after_seconds if max_age_seconds is None else int(max_age_seconds)
        return {
            "enabled": self.enabled,
            "connected": connected,
            "topic": self.topic,
            "last_message_timestamp": last_message_timestamp,
            "age_seconds": age_seconds,
            "stale": True if age_seconds is None else age_seconds > max(1, stale_limit),
            "total_power": total_power,
            "message_count": message_count,
            "last_delta_watts": last_delta_watts,
            "last_triggered_change": last_triggered_change,
            "change_threshold_watts": self.change_threshold_watts,
        }

    def consume_power_change_event(self) -> bool:
        with self._lock:
            if not self._power_change_event:
                return False
            self._power_change_event = False
            return True

    def wait_for_wake(self, timeout_seconds: float) -> bool:
        """Wait until a thresholded MQTT change requests an early wake."""
        return self._wake_event.wait(timeout=max(0.0, float(timeout_seconds)))

    def consume_wake_event(self) -> bool:
        """Return whether a wake event was pending and clear only the wake signal."""
        if not self._wake_event.is_set():
            return False
        self._wake_event.clear()
        return True

    def clear_wake_event(self) -> None:
        """Clear any pending wake signal without touching the control event."""
        self._wake_event.clear()

    def _normalize_payload(self, payload: dict[str, Any]) -> tuple[dict[str, Any], Optional[int]]:
        normalized = dict(payload)
        total_power_raw = _get_json_value(normalized, self.total_power_path)
        if total_power_raw is None:
            normalized["total_power"] = None
            return normalized, None

        total_power = None
        try:
            total_power = int(round(float(total_power_raw)))
        except (TypeError, ValueError):
            total_power = None
        normalized["total_power"] = total_power
        return normalized, total_power

    def _on_connect(self, client, userdata, flags, rc, properties=None):
        with self._lock:
            self._connected = (rc == 0)
        if rc == 0:
            client.subscribe(self.topic)

    def _on_disconnect(self, client, userdata, rc, properties=None):
        with self._lock:
            self._connected = False

    def _on_message(self, client, userdata, message):
        try:
            raw_payload = message.payload.decode("utf-8")
            payload = json.loads(raw_payload)
            if not isinstance(payload, dict):
                return

            if _get_json_value(payload, self.total_power_path) is None:
                return

            normalized_payload, total_power = self._normalize_payload(payload)
        except (UnicodeDecodeError, json.JSONDecodeError, TypeError, ValueError):
            return

        received_at = time.time()
        with self._lock:
            if total_power is None:
                return
            previous_power = self._last_total_power
            delta_watts = None if previous_power is None else abs(total_power - previous_power)
            self._last_raw_payload = raw_payload
            self._last_payload = normalized_payload
            self._last_total_power = total_power
            self._last_message_timestamp = received_at
            self._message_count += 1
            self._last_delta_watts = delta_watts
            self._last_triggered_change = (
                previous_power is None
                or (delta_watts is not None and delta_watts > self.change_threshold_watts)
            )
            if self._last_triggered_change:
                self._power_change_event = True
                self._wake_event.set()
