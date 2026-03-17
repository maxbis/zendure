#!/usr/bin/env python3
"""
Convert legacy scalar charge_schedule.json entries into object entries.

Old format:
{
  "********1800": "netzero",
  "202603140400": 12
}

New format:
{
  "********1800": { "value": "netzero" },
  "202603140400": { "value": 12 }
}

Usage:
  python3 tools/convert_charge_schedule_format.py
  python3 tools/convert_charge_schedule_format.py --dry-run
  python3 tools/convert_charge_schedule_format.py --file /path/to/charge_schedule.json
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
from pathlib import Path
from typing import Any


REPO_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_FILE = REPO_ROOT / "main" / "data" / "charge_schedule.json"
VALID_STRING_VALUES = {"auto", "netzero", "netzero+"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Convert legacy scalar charge_schedule.json entries to object entries."
    )
    parser.add_argument(
        "--file",
        default=str(DEFAULT_FILE),
        help=f"Path to charge_schedule.json (default: {DEFAULT_FILE})",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Print what would change without writing the file.",
    )
    return parser.parse_args()


def is_supported_scalar(value: Any) -> bool:
    return isinstance(value, (int, float)) or value in VALID_STRING_VALUES


def normalize_scalar(value: Any) -> Any:
    if isinstance(value, bool):
        raise ValueError("Boolean values are not valid schedule values")
    if isinstance(value, float):
        if value.is_integer():
            return int(value)
        raise ValueError(f"Float schedule values are not supported: {value!r}")
    if isinstance(value, int):
        return value
    if value in VALID_STRING_VALUES:
        return value
    raise ValueError(f"Unsupported schedule value: {value!r}")


def convert_schedule(data: dict[str, Any]) -> tuple[dict[str, Any], int, int]:
    converted: dict[str, Any] = {}
    converted_count = 0
    unchanged_count = 0

    for key, value in data.items():
        if isinstance(value, dict):
            if "value" not in value:
                raise ValueError(f"Entry '{key}' is an object but missing required 'value' field")
            converted[key] = value
            unchanged_count += 1
            continue

        if not is_supported_scalar(value):
            raise ValueError(
                f"Entry '{key}' uses unsupported legacy scalar value {value!r}; aborting conversion"
            )

        converted[key] = {"value": normalize_scalar(value)}
        converted_count += 1

    return converted, converted_count, unchanged_count


def atomic_write_json(path: Path, payload: dict[str, Any]) -> None:
    fd, temp_path = tempfile.mkstemp(prefix=path.name + ".", suffix=".tmp", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, indent=4)
            handle.write("\n")
        os.replace(temp_path, path)
    except Exception:
        try:
            os.unlink(temp_path)
        except FileNotFoundError:
            pass
        raise


def main() -> int:
    args = parse_args()
    path = Path(args.file).expanduser().resolve()

    if not path.exists():
        print(f"File not found: {path}", file=sys.stderr)
        return 1

    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        print(f"Invalid JSON in {path}: {exc}", file=sys.stderr)
        return 1

    if not isinstance(raw, dict):
        print(f"Expected top-level JSON object in {path}", file=sys.stderr)
        return 1

    try:
        converted, converted_count, unchanged_count = convert_schedule(raw)
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return 1

    if args.dry_run:
        print(f"Dry run for {path}")
        print(f"Would convert: {converted_count}")
        print(f"Already object format: {unchanged_count}")
        print(json.dumps(converted, indent=4))
        return 0

    atomic_write_json(path, converted)
    print(f"Converted file: {path}")
    print(f"Converted legacy scalar entries: {converted_count}")
    print(f"Already object format: {unchanged_count}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
