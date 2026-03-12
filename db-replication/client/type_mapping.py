from __future__ import annotations


def map_sqlite_type(sqlite_type: str) -> str:
    normalized = (sqlite_type or "").strip().upper()
    if "INT" in normalized:
        return "BIGINT"
    if "TEXT" in normalized:
        return "LONGTEXT"
    return "LONGTEXT"
