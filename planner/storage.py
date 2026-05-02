from __future__ import annotations

import json
import threading
from pathlib import Path
from typing import Dict, List, Optional, Tuple

from planner.forecast import normalize_load_forecast_payload
from planner.models import LoadForecastRecord


class LoadForecastStore:
    def __init__(self, path: Path, default_template_path: Optional[Path] = None):
        self.path = path
        self.default_template_path = default_template_path
        self._lock = threading.Lock()

    def _ensure_dir(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)

    def load_all(self) -> Dict[str, LoadForecastRecord]:
        with self._lock:
            return self._load_all_unlocked()

    def get(self, date: str) -> Optional[LoadForecastRecord]:
        with self._lock:
            records = self._load_all_unlocked()
            return records.get(str(date))

    def put(self, record: LoadForecastRecord) -> None:
        with self._lock:
            records = self._load_all_unlocked()
            records[record.date] = record
            self._write_all_unlocked(records)

    def load_all_for_dates(
        self,
        dates: List[str],
        timezone: str,
        now_iso: str,
    ) -> Tuple[Dict[str, LoadForecastRecord], List[str]]:
        with self._lock:
            records = self._load_all_unlocked()
            template = self._load_default_template_unlocked(timezone, now_iso)
            defaulted_dates: List[str] = []
            for date in dates:
                if date in records or template is None:
                    continue
                records[date] = LoadForecastRecord(
                    date=date,
                    timezone=template.timezone,
                    baseline_load_w_by_hour=list(template.baseline_load_w_by_hour),
                    incidentals=dict(template.incidentals),
                    updated_at=now_iso,
                )
                defaulted_dates.append(date)
            return records, defaulted_dates

    def _load_all_unlocked(self) -> Dict[str, LoadForecastRecord]:
        if not self.path.exists():
            return {}
        try:
            raw = json.loads(self.path.read_text(encoding="utf-8"))
        except Exception:
            return {}
        if not isinstance(raw, dict):
            return {}
        result: Dict[str, LoadForecastRecord] = {}
        for date, payload in raw.items():
            if not isinstance(payload, dict):
                continue
            baseline = payload.get("baseline_load_w_by_hour")
            incidentals = payload.get("incidentals")
            if not isinstance(baseline, list) or len(baseline) != 24 or not isinstance(incidentals, dict):
                continue
            result[str(date)] = LoadForecastRecord(
                date=str(payload.get("date", date)),
                timezone=str(payload.get("timezone", "Europe/Amsterdam")),
                baseline_load_w_by_hour=[float(value) for value in baseline],
                incidentals={str(key): float(value) for key, value in incidentals.items()},
                updated_at=str(payload.get("updated_at", "")),
            )
        return result

    def _write_all_unlocked(self, records: Dict[str, LoadForecastRecord]) -> None:
        self._ensure_dir()
        serializable = {date: record.to_dict() for date, record in records.items()}
        tmp_path = self.path.with_suffix(self.path.suffix + ".tmp")
        tmp_path.write_text(
            json.dumps(serializable, indent=2, sort_keys=True),
            encoding="utf-8",
        )
        tmp_path.replace(self.path)

    def _load_default_template_unlocked(self, timezone: str, now_iso: str) -> Optional[LoadForecastRecord]:
        if self.default_template_path is None or not self.default_template_path.exists():
            return None
        try:
            raw = json.loads(self.default_template_path.read_text(encoding="utf-8"))
        except Exception:
            return None
        if not isinstance(raw, dict):
            return None

        payload = dict(raw)
        payload.setdefault("date", "1970-01-01")
        payload.setdefault("timezone", timezone)
        try:
            return normalize_load_forecast_payload(payload, now_iso)
        except ValueError:
            return None
