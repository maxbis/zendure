from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class ServerTable:
    name: str
    sync_mode: str
    primary_key: str | None
    replication_key: str | None
    supports_incremental: bool
    notes: str | None = None


@dataclass(frozen=True)
class ServerColumn:
    name: str
    sqlite_type: str
    nullable: bool
    default: Any | None
    ordinal: int


@dataclass(frozen=True)
class ServerSchema:
    table: str
    columns: list[ServerColumn]
    primary_key: str | None
    replication_key: str | None


@dataclass(frozen=True)
class RowsBatch:
    table: str
    replication_key: str
    rows: list[dict[str, Any]]
    count: int
    next_cursor: int
    has_more: bool


class ServerApiError(RuntimeError):
    pass


class ServerApi:
    def __init__(self, base_url: str, access_token: str, timeout: float = 30.0) -> None:
        self.base_url = base_url.rstrip("/")
        self.access_token = access_token
        self.timeout = timeout

    def _get(self, path: str, params: dict[str, Any] | None = None) -> dict[str, Any]:
        query = {"access_token": self.access_token}
        if params:
            query.update(params)

        url = f"{self.base_url}{path}?{urllib.parse.urlencode(query)}"
        request = urllib.request.Request(url, method="GET")
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                raw_body = response.read().decode("utf-8")
                return json.loads(raw_body)
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")
            raise ServerApiError(f"Server request failed with HTTP {exc.code}: {body}") from exc
        except urllib.error.URLError as exc:
            raise ServerApiError(f"Server request failed: {exc.reason}") from exc
        except json.JSONDecodeError as exc:
            raise ServerApiError("Server returned invalid JSON") from exc

    def get_tables(self) -> list[ServerTable]:
        payload = self._get("/tables")
        return [ServerTable(**table) for table in payload.get("tables", [])]

    def get_schema(self, table: str) -> ServerSchema:
        payload = self._get(f"/tables/{urllib.parse.quote(table)}/schema")
        return ServerSchema(
            table=payload["table"],
            columns=[ServerColumn(**column) for column in payload.get("columns", [])],
            primary_key=payload.get("primary_key"),
            replication_key=payload.get("replication_key"),
        )

    def get_rows(self, table: str, after: int, limit: int) -> RowsBatch:
        payload = self._get(
            f"/tables/{urllib.parse.quote(table)}/rows",
            params={"after": after, "limit": limit},
        )
        return RowsBatch(
            table=payload["table"],
            replication_key=payload["replication_key"],
            rows=payload.get("rows", []),
            count=payload["count"],
            next_cursor=payload["next_cursor"],
            has_more=payload["has_more"],
        )
