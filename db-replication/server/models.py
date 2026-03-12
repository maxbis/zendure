from typing import Any

from pydantic import BaseModel


class ErrorResponse(BaseModel):
    error: str
    message: str
    details: Any | None = None


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str


class TableSummary(BaseModel):
    name: str
    sync_mode: str
    primary_key: str | None
    replication_key: str | None
    supports_incremental: bool
    notes: str | None = None


class TablesResponse(BaseModel):
    tables: list[TableSummary]


class ColumnInfo(BaseModel):
    name: str
    sqlite_type: str
    nullable: bool
    default: Any | None = None
    ordinal: int


class SchemaResponse(BaseModel):
    table: str
    columns: list[ColumnInfo]
    primary_key: str | None
    replication_key: str | None


class RowsResponse(BaseModel):
    table: str
    replication_key: str
    rows: list[dict[str, Any]]
    count: int
    next_cursor: int
    has_more: bool
