from __future__ import annotations

from datetime import date, datetime, timedelta
import json
import math
import os
from pathlib import Path
from typing import Any, Iterable, Sequence

from planner.models import BatteryState
from planner.rolling_optimizer import RollingInputSlot


MINIMUM_HISTORY_SAMPLES = 168
PRICE_DISTRIBUTION_TARGET_BINS = 24


def _load_env_file(path: Path) -> None:
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8-sig").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        name, value = line.split("=", 1)
        name, value = name.strip(), value.strip()
        if name and name not in os.environ:
            os.environ[name] = value.strip("'\"")


def weighted_percentile(rows: Sequence[tuple[float, float]], percentile: float) -> float:
    ordered = sorted((float(value), float(weight)) for value, weight in rows if weight > 0)
    if not ordered:
        raise ValueError("At least one weighted price is required")
    target = sum(weight for _value, weight in ordered) * max(0.0, min(100.0, percentile)) / 100.0
    cumulative = 0.0
    for value, weight in ordered:
        cumulative += weight
        if cumulative >= target:
            return value
    return ordered[-1][0]


def build_price_distribution(
    rows: Sequence[tuple[float, float]], *, target_bins: int = PRICE_DISTRIBUTION_TARGET_BINS
) -> dict[str, Any]:
    values = [(float(value), float(weight)) for value, weight in rows if weight > 0]
    if not values:
        return {"bin_width_eur_per_kwh": None, "sample_count": 0, "buckets": []}

    minimum = min(value for value, _weight in values)
    maximum = max(value for value, _weight in values)
    raw_width = max(0.001, (maximum - minimum) / max(1, target_bins))
    magnitude = 10.0 ** math.floor(math.log10(raw_width))
    normalized = raw_width / magnitude
    nice_factor = min((1.0, 2.0, 2.5, 5.0, 10.0), key=lambda candidate: abs(math.log(normalized / candidate)))
    width = nice_factor * magnitude
    lower = math.floor(minimum / width) * width
    upper = math.ceil(maximum / width) * width
    if upper <= lower:
        upper = lower + width
    bucket_count = max(1, int(round((upper - lower) / width)))
    buckets = [{"hour_count": 0, "weight": 0.0} for _index in range(bucket_count)]
    for value, weight in values:
        index = min(bucket_count - 1, max(0, int(math.floor((value - lower) / width))))
        buckets[index]["hour_count"] += 1
        buckets[index]["weight"] += weight

    total_count = len(values)
    total_weight = sum(weight for _value, weight in values)
    cumulative_weight = 0.0
    published = []
    for index, bucket in enumerate(buckets):
        cumulative_weight += bucket["weight"]
        published.append({
            "from_eur_per_kwh": round(lower + index * width, 6),
            "to_eur_per_kwh": round(lower + (index + 1) * width, 6),
            "hour_count": bucket["hour_count"],
            "raw_percent": round(bucket["hour_count"] / total_count * 100.0, 6),
            "weighted_percent": round(bucket["weight"] / total_weight * 100.0, 6),
            "cumulative_weighted_percent": round(cumulative_weight / total_weight * 100.0, 6),
        })
    return {
        "bin_width_eur_per_kwh": round(width, 6),
        "sample_count": total_count,
        "buckets": published,
    }


def load_weighted_price_history(
    *, price_root: Path, through_date: date, days: int, half_life_days: float
) -> tuple[list[tuple[float, float]], dict[str, Any]]:
    start_date = through_date - timedelta(days=days - 1)
    rows: list[tuple[float, float]] = []
    dates_loaded: list[str] = []
    for offset in range(days):
        current = start_date + timedelta(days=offset)
        path = price_root / current.strftime("%Y%m") / f"price{current:%Y%m%d}.json"
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        if not isinstance(payload, dict):
            continue
        day_values = []
        for raw in payload.values():
            if isinstance(raw, bool) or not isinstance(raw, (int, float)):
                continue
            value = float(raw)
            if math.isfinite(value):
                day_values.append(value)
        if day_values:
            age_days = (through_date - current).days
            weight = 0.5 ** (age_days / half_life_days)
            rows.extend((value, weight) for value in day_values)
            dates_loaded.append(current.isoformat())
    return rows, {
        "source": "json_price_files",
        "from": start_date.isoformat(),
        "through": through_date.isoformat(),
        "dates_loaded": dates_loaded,
        "sample_count": len(rows),
        "half_life_days": half_life_days,
    }


def load_database_price_history(
    *, repo_root: Path, through_date: date, days: int, half_life_days: float
) -> tuple[list[tuple[float, float]], dict[str, Any]] | None:
    _load_env_file(repo_root / "daily_report" / ".env")
    try:
        import pymysql

        connection = pymysql.connect(
            host=os.getenv("MARIADB_HOST", "127.0.0.1"),
            port=int(os.getenv("MARIADB_PORT", "3306")),
            user=os.getenv("MARIADB_USER", "root"),
            password=os.getenv("MARIADB_PASSWORD", ""),
            database=os.getenv("MARIADB_DATABASE", "sqlite_replication"),
            connect_timeout=2,
            read_timeout=3,
            cursorclass=pymysql.cursors.DictCursor,
        )
    except Exception:
        return None
    start_date = through_date - timedelta(days=days - 1)
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT local_date, consumer_eur_per_kwh FROM price_ticks "
                "WHERE local_date BETWEEN %s AND %s ORDER BY local_date, local_hour",
                (start_date.isoformat(), through_date.isoformat()),
            )
            records = cursor.fetchall()
    except Exception:
        return None
    finally:
        connection.close()
    rows: list[tuple[float, float]] = []
    dates_loaded: set[str] = set()
    for record in records:
        try:
            local_date = record["local_date"]
            current = local_date if isinstance(local_date, date) else date.fromisoformat(str(local_date))
            value = float(record["consumer_eur_per_kwh"])
        except (KeyError, TypeError, ValueError):
            continue
        if not math.isfinite(value):
            continue
        weight = 0.5 ** ((through_date - current).days / half_life_days)
        rows.append((value, weight))
        dates_loaded.add(current.isoformat())
    return rows, {
        "source": "price_ticks",
        "from": start_date.isoformat(),
        "through": through_date.isoformat(),
        "dates_loaded": sorted(dates_loaded),
        "sample_count": len(rows),
        "half_life_days": half_life_days,
    }


def _predicted_refill(
    battery_state: BatteryState,
    slots: Iterable[RollingInputSlot],
    *, charge_efficiency: float,
    cheap_price: float,
) -> dict[str, float]:
    capacity_wh = float(battery_state.usable_capacity_wh)
    current_wh = capacity_wh * battery_state.soc_percent / 100.0
    target_wh = capacity_wh * battery_state.max_charge_level_percent / 100.0
    required_wh = max(0.0, target_wh - current_wh)
    remaining_wh = required_wh
    solar_internal_wh = 0.0
    grid_internal_wh = 0.0
    for slot in slots:
        if remaining_wh <= 1e-9:
            break
        duration = slot.duration_hours
        if duration <= 0:
            continue
        max_input_w = float(battery_state.max_charge_power_w)
        solar_input_w = min(max_input_w, max(0.0, slot.pv_w - slot.load_w))
        solar_wh = min(remaining_wh, solar_input_w * duration * charge_efficiency)
        solar_internal_wh += solar_wh
        remaining_wh -= solar_wh
        if remaining_wh <= 1e-9 or slot.import_price_eur_per_kwh > cheap_price + 1e-12:
            continue
        grid_input_w = max(0.0, max_input_w - solar_input_w)
        grid_wh = min(remaining_wh, grid_input_w * duration * charge_efficiency)
        grid_internal_wh += grid_wh
        remaining_wh -= grid_wh
    total_wh = solar_internal_wh + grid_internal_wh
    factor = 1.0 if required_wh <= 1e-9 else min(1.0, total_wh / required_wh)
    return {
        "energy_required_kwh": required_wh / 1000.0,
        "predicted_solar_refill_kwh": solar_internal_wh / 1000.0,
        "predicted_cheap_grid_refill_kwh": grid_internal_wh / 1000.0,
        "predicted_total_refill_kwh": total_wh / 1000.0,
        "expected_refill_factor": factor,
        "expected_refill_percent": factor * 100.0,
    }


def build_scarcity_context(
    *,
    now: datetime,
    battery_state: BatteryState,
    slots: list[RollingInputSlot],
    round_trip_efficiency: float,
    normal_terminal_price: float | None,
    config: dict[str, Any],
    price_root: Path,
) -> dict[str, Any]:
    enabled = bool(config.get("enabled", False))
    through_date = now.date() - timedelta(days=1)
    days = int(config["priceHistoryDays"])
    half_life_days = float(config["priceHistoryHalfLifeDays"])
    repo_root = price_root.parents[2]
    database_history = (
        load_database_price_history(
            repo_root=repo_root, through_date=through_date, days=days, half_life_days=half_life_days
        )
        if (repo_root / "common" / "config").is_dir()
        else None
    )
    if database_history is not None and len(database_history[0]) >= MINIMUM_HISTORY_SAMPLES:
        rows, history = database_history
    else:
        rows, history = load_weighted_price_history(
            price_root=price_root,
            through_date=through_date,
            days=days,
            half_life_days=half_life_days,
        )
    base = {
        "algorithm_enabled": enabled,
        "calculation_available": False,
        "unavailable_reason": None,
        "calculated_at": now.isoformat(),
        "normal_terminal_price_eur_per_kwh": normal_terminal_price,
        "price_history": history,
        "refill": None,
        "bands": [],
    }
    if normal_terminal_price is None:
        base["unavailable_reason"] = "terminal_price_unavailable"
        return base
    if len(rows) < MINIMUM_HISTORY_SAMPLES:
        base["unavailable_reason"] = "insufficient_price_history"
        return base
    cheap_price = weighted_percentile(rows, float(config["cheapGridChargePercentile"]))
    percentile_curve = [
        {
            "percentile": float(percentile),
            "price_eur_per_kwh": weighted_percentile(rows, float(percentile)),
        }
        for percentile in range(0, 101, 5)
    ]
    price_distribution = build_price_distribution(rows)
    refill = _predicted_refill(
        battery_state,
        slots,
        charge_efficiency=math.sqrt(max(0.01, min(1.0, round_trip_efficiency))),
        cheap_price=cheap_price,
    )
    cap = float(config["referencePriceCapEurPerKwh"])
    bands = []
    for configured in config["bands"]:
        reference = min(cap, weighted_percentile(rows, float(configured["pricePercentile"])))
        additional = max(0.0, reference - normal_terminal_price)
        active = refill["expected_refill_percent"] <= float(configured["activateWhenExpectedRefillBelowPercent"])
        bands.append({
            "from_soc_percent": int(configured["fromSocPercent"]),
            "to_soc_percent": int(configured["toSocPercent"]),
            "price_percentile": float(configured["pricePercentile"]),
            "reference_price_eur_per_kwh": reference,
            "additional_scarcity_value_eur_per_kwh": additional,
            "activate_when_expected_refill_below_percent": float(configured["activateWhenExpectedRefillBelowPercent"]),
            "active": active,
            "applied": enabled and active,
        })
    base.update({
        "calculation_available": True,
        "cheap_grid_charge_percentile": float(config["cheapGridChargePercentile"]),
        "cheap_grid_charge_price_eur_per_kwh": cheap_price,
        "price_percentile_curve": percentile_curve,
        "price_distribution": price_distribution,
        "refill": {**refill, "horizon_start": slots[0].start.isoformat(), "horizon_end": slots[-1].end.isoformat()},
        "bands": bands,
    })
    return base
