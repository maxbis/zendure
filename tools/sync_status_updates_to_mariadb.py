#!/usr/bin/env python3
"""
Sync one delta batch from the automation API into MariaDB/MySQL.

Flow per run:
1) Read MAX(id) from target table in MariaDB.
2) Call delta endpoint with after_id=max_id and limit=BATCH_SIZE.
3) Upsert returned rows into MariaDB.

All settings are constants in this file for now.
"""

from __future__ import annotations

import json
import sys
import urllib.parse
import urllib.request
from typing import Any

try:
    import mysql.connector
    from mysql.connector import Error as MySqlError
except Exception as exc:  # pragma: no cover - import-time guard
    print("Missing dependency: mysql-connector-python", file=sys.stderr)
    print("Install with: pip install mysql-connector-python", file=sys.stderr)
    raise SystemExit(2) from exc


# ---------------------------------------------------------------------------
# Constants (move to config later)
# ---------------------------------------------------------------------------
BATCH_SIZE = 500
API_ENDPOINT = "http://127.0.0.1:1611/api/status_updates_delta"
API_TOKEN = ""  # Optional. Set when endpoint token protection is enabled.

DB_HOST = "127.0.0.1"
DB_PORT = 3306
DB_NAME = "zendure"
DB_USER = "zendure_user"
DB_PASSWORD = "change-me"
DB_TABLE = "status_updates"


def build_delta_url(after_id: int, limit: int) -> str:
    query = {
        "after_id": str(after_id),
        "limit": str(limit),
    }
    if API_TOKEN:
        query["token"] = API_TOKEN
    return f"{API_ENDPOINT}?{urllib.parse.urlencode(query)}"


def fetch_delta(after_id: int, limit: int) -> dict[str, Any]:
    url = build_delta_url(after_id, limit)
    req = urllib.request.Request(url, method="GET")
    if API_TOKEN:
        req.add_header("X-API-Token", API_TOKEN)

    with urllib.request.urlopen(req, timeout=15) as resp:
        body = resp.read().decode("utf-8")
        payload = json.loads(body)

    if not isinstance(payload, dict):
        raise RuntimeError("Delta API returned non-object JSON")
    if "rows" not in payload or not isinstance(payload["rows"], list):
        raise RuntimeError("Delta API response missing 'rows' list")
    return payload


def connect_db():
    return mysql.connector.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        autocommit=False,
    )


def ensure_table(conn) -> None:
    sql = f"""
    CREATE TABLE IF NOT EXISTS `{DB_TABLE}` (
      `id` BIGINT NOT NULL,
      `type` VARCHAR(32) NOT NULL,
      `old_value` LONGTEXT NULL,
      `new_value` LONGTEXT NULL,
      `p1_total_power` INT NULL,
      `electric_level` INT NULL,
      `timestamp` BIGINT NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_{DB_TABLE}_timestamp` (`timestamp`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """
    with conn.cursor() as cur:
        cur.execute(sql)
    conn.commit()


def get_max_id(conn) -> int:
    sql = f"SELECT COALESCE(MAX(id), 0) FROM `{DB_TABLE}`"
    with conn.cursor() as cur:
        cur.execute(sql)
        row = cur.fetchone()
    if not row:
        return 0
    return int(row[0] or 0)


def upsert_rows(conn, rows: list[dict[str, Any]]) -> int:
    if not rows:
        return 0

    sql = f"""
    INSERT INTO `{DB_TABLE}`
      (`id`, `type`, `old_value`, `new_value`, `p1_total_power`, `electric_level`, `timestamp`)
    VALUES
      (%s, %s, %s, %s, %s, %s, %s)
    ON DUPLICATE KEY UPDATE
      `type` = VALUES(`type`),
      `old_value` = VALUES(`old_value`),
      `new_value` = VALUES(`new_value`),
      `p1_total_power` = VALUES(`p1_total_power`),
      `electric_level` = VALUES(`electric_level`),
      `timestamp` = VALUES(`timestamp`)
    """

    values = []
    for r in rows:
        values.append(
            (
                int(r.get("id")),
                str(r.get("type") or ""),
                r.get("old_value"),
                r.get("new_value"),
                r.get("p1_total_power"),
                r.get("electric_level"),
                int(r.get("timestamp")),
            )
        )

    with conn.cursor() as cur:
        cur.executemany(sql, values)
    conn.commit()
    return len(values)


def main() -> int:
    conn = None
    try:
        conn = connect_db()
        ensure_table(conn)
        current_max_id = get_max_id(conn)
        payload = fetch_delta(after_id=current_max_id, limit=BATCH_SIZE)
        rows = payload.get("rows", [])
        inserted = upsert_rows(conn, rows)

        max_id_returned = payload.get("max_id_returned", current_max_id)
        has_more = bool(payload.get("has_more", False))

        print(f"Current MariaDB max id : {current_max_id}")
        print(f"Fetched rows           : {len(rows)}")
        print(f"Upserted rows          : {inserted}")
        print(f"Max id returned        : {max_id_returned}")
        print(f"Has more               : {has_more}")
        return 0
    except urllib.error.HTTPError as e:
        print(f"HTTP error from delta endpoint: {e.code} {e.reason}", file=sys.stderr)
        return 1
    except urllib.error.URLError as e:
        print(f"Network error calling delta endpoint: {e}", file=sys.stderr)
        return 1
    except MySqlError as e:
        print(f"MariaDB/MySQL error: {e}", file=sys.stderr)
        return 1
    except Exception as e:
        print(f"Sync failed: {e}", file=sys.stderr)
        return 1
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


if __name__ == "__main__":
    raise SystemExit(main())

