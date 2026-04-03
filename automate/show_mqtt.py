#!/usr/bin/env python3
"""
Standalone MQTT debug watcher for automate.

Shows the raw topic/payload arriving from the broker and, when possible,
the parsed `total_power` value using the same mqttPowerMeter config fields
as automate_mqtt.py.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Any

from config_loader import load_config as load_config_json

try:
    import paho.mqtt.client as mqtt
except ImportError:
    mqtt = None


CONFIG_KEY_MQTT_POWER_METER = "mqttPowerMeter"
DEFAULT_BROKER_PORT = 1883
DEFAULT_KEEPALIVE_SECONDS = 60


def _find_config_file() -> Path:
    script_dir = Path(__file__).parent
    config_path = script_dir / "config" / "config.jsonc"
    if not config_path.exists():
        raise FileNotFoundError(
            f"Config file not found: {config_path}\n"
            "Automate uses automate/config/config.jsonc only."
        )
    return config_path


def _load_config(config_path: Path) -> dict[str, Any]:
    return load_config_json(config_path)


def _parse_int(raw: Any, default: int) -> int:
    try:
        return int(raw)
    except (TypeError, ValueError):
        return default


def _normalize_optional_string(raw: Any) -> str | None:
    if raw is None:
        return None
    value = str(raw).strip()
    return value or None


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


def _format_now() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def _print_line(message: str) -> None:
    print(f"[{_format_now()}] {message}", flush=True)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Show raw MQTT messages and parsed power values for automate.",
    )
    parser.add_argument(
        "--config",
        default=str(_find_config_file()),
        help="Path to automate config.jsonc",
    )
    parser.add_argument(
        "--topic",
        help="Override topic from config. Use '#' to inspect everything.",
    )
    parser.add_argument(
        "--all-topics",
        action="store_true",
        help="Subscribe to '#' instead of the configured mqttPowerMeter.topic",
    )
    parser.add_argument(
        "--raw",
        action="store_true",
        help="Also print the full raw payload body for each message.",
    )
    args = parser.parse_args()

    if mqtt is None:
        print("paho-mqtt is not installed", file=sys.stderr)
        return 1

    config_path = Path(args.config).resolve()
    config = _load_config(config_path)
    mqtt_config = config.get(CONFIG_KEY_MQTT_POWER_METER) or {}

    enabled = bool(mqtt_config.get("enabled", False))
    broker_host = str(mqtt_config.get("brokerHost", "")).strip()
    broker_port = _parse_int(mqtt_config.get("brokerPort"), DEFAULT_BROKER_PORT)
    username = _normalize_optional_string(mqtt_config.get("username"))
    password = _normalize_optional_string(mqtt_config.get("password"))
    configured_topic = str(mqtt_config.get("topic", "")).strip()
    total_power_path = str(mqtt_config.get("totalPowerPath", "total_act_power")).strip()

    if not enabled:
        print("mqttPowerMeter.enabled is false in config", file=sys.stderr)
        return 1
    if not broker_host:
        print("mqttPowerMeter.brokerHost is missing", file=sys.stderr)
        return 1

    topic = "#"
    if not args.all_topics:
        topic = (args.topic or configured_topic or "").strip()
    if not topic:
        print("No topic configured. Set mqttPowerMeter.topic or pass --topic.", file=sys.stderr)
        return 1

    _print_line(f"Config: {config_path}")
    _print_line(
        f"Connecting to MQTT broker {broker_host}:{broker_port}, topic={topic}, totalPowerPath={total_power_path}"
    )

    client = mqtt.Client()
    if username is not None:
        client.username_pw_set(username, password)

    state = {
        "count": 0,
        "connected_at": None,
    }

    def on_connect(client, userdata, flags, rc):
        state["connected_at"] = time.time()
        _print_line(f"Connected rc={rc}; subscribing to {topic}")
        client.subscribe(topic)

    def on_disconnect(client, userdata, rc):
        _print_line(f"Disconnected rc={rc}")

    def on_message(client, userdata, msg):
        state["count"] += 1
        payload_text = msg.payload.decode("utf-8", errors="replace")
        parsed_value = None
        parse_error = None
        method_name = None
        try:
            payload_json = json.loads(payload_text)
            raw_method_name = payload_json.get("method")
            if raw_method_name is not None:
                method_name = str(raw_method_name)
            raw_total_power = _get_json_value(payload_json, total_power_path)
            if raw_total_power is not None:
                parsed_value = int(round(float(raw_total_power)))
        except Exception as exc:  # noqa: BLE001
            parse_error = str(exc)

        method_text = "unknown" if not method_name else method_name
        _print_line(
            f"message #{state['count']} topic={msg.topic} method={method_text}"
        )
        if args.raw:
            _print_line(f"raw payload={payload_text}")

        if parsed_value is not None:
            _print_line(
                f"parsed total_power={parsed_value}W method={method_text} path={total_power_path}"
            )
        elif parse_error is not None:
            _print_line(
                f"parsed total_power=<none> method={method_text} parse_error={parse_error}"
            )
        else:
            _print_line(
                f"parsed total_power=<none> method={method_text} path={total_power_path}"
            )

    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message

    try:
        client.connect(broker_host, broker_port, DEFAULT_KEEPALIVE_SECONDS)
        client.loop_forever()
    except KeyboardInterrupt:
        _print_line("Stopping on Ctrl+C")
        return 0
    except Exception as exc:  # noqa: BLE001
        print(f"MQTT watcher failed: {exc}", file=sys.stderr)
        return 1
    finally:
        try:
            client.disconnect()
        except Exception:
            pass

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
