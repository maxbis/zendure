import json
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any


DEFAULT_PAGE_SIZE = 250
MAX_PAGE_SIZE = 1000
DEFAULT_BUSY_TIMEOUT_MS = 5000
DEFAULT_HOST = "127.0.0.1"
DEFAULT_PORT = 1612
DEFAULT_SERVICE_NAME = "sqlite-replication-source"
DEFAULT_VERSION = "1.0.0"
ENV_FILE_NAME = ".env"


@dataclass(frozen=True)
class TableConfig:
    enabled: bool = True
    replication_key: str | None = None
    notes: str | None = None


@dataclass(frozen=True)
class Settings:
    sqlite_db_path: str
    access_token: str
    allowed_tables: dict[str, TableConfig]
    default_page_size: int = DEFAULT_PAGE_SIZE
    max_page_size: int = MAX_PAGE_SIZE
    busy_timeout_ms: int = DEFAULT_BUSY_TIMEOUT_MS
    health_requires_token: bool = False
    host: str = DEFAULT_HOST
    port: int = DEFAULT_PORT
    service_name: str = DEFAULT_SERVICE_NAME
    version: str = DEFAULT_VERSION


def _parse_bool(value: str | None, default: bool) -> bool:
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def _load_dotenv_file() -> None:
    env_path = Path(__file__).resolve().parent / ENV_FILE_NAME
    if not env_path.exists():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if value and len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        os.environ.setdefault(key, value)


def _parse_table_mapping(raw: str | None) -> dict[str, TableConfig]:
    if not raw:
        return {}

    try:
        parsed: Any = json.loads(raw)
    except json.JSONDecodeError:
        parsed = [item.strip() for item in raw.split(",") if item.strip()]

    if isinstance(parsed, list):
        return {name: TableConfig() for name in parsed}

    if not isinstance(parsed, dict):
        raise ValueError("ALLOWED_TABLES must be a JSON object, JSON array, or comma-separated list")

    tables: dict[str, TableConfig] = {}
    for table_name, config in parsed.items():
        if isinstance(config, bool):
            tables[table_name] = TableConfig(enabled=config)
            continue

        if not isinstance(config, dict):
            raise ValueError(f"Invalid config for table '{table_name}'")

        tables[table_name] = TableConfig(
            enabled=bool(config.get("enabled", True)),
            replication_key=config.get("replication_key"),
            notes=config.get("notes"),
        )

    return tables


def load_settings() -> Settings:
    _load_dotenv_file()
    sqlite_db_path = os.getenv("SQLITE_DB_PATH")
    access_token = os.getenv("ACCESS_TOKEN")

    if not sqlite_db_path:
        raise ValueError("SQLITE_DB_PATH is required")
    if not access_token:
        raise ValueError("ACCESS_TOKEN is required")

    default_page_size = int(os.getenv("DEFAULT_PAGE_SIZE", DEFAULT_PAGE_SIZE))
    max_page_size = int(os.getenv("MAX_PAGE_SIZE", MAX_PAGE_SIZE))
    if default_page_size < 1:
        raise ValueError("DEFAULT_PAGE_SIZE must be >= 1")
    if max_page_size < 1:
        raise ValueError("MAX_PAGE_SIZE must be >= 1")
    if default_page_size > max_page_size:
        raise ValueError("DEFAULT_PAGE_SIZE must be <= MAX_PAGE_SIZE")

    return Settings(
        sqlite_db_path=sqlite_db_path,
        access_token=access_token,
        allowed_tables=_parse_table_mapping(os.getenv("ALLOWED_TABLES")),
        default_page_size=default_page_size,
        max_page_size=max_page_size,
        busy_timeout_ms=int(os.getenv("SQLITE_BUSY_TIMEOUT_MS", DEFAULT_BUSY_TIMEOUT_MS)),
        health_requires_token=_parse_bool(os.getenv("HEALTH_REQUIRES_TOKEN"), False),
        host=os.getenv("HOST", DEFAULT_HOST),
        port=int(os.getenv("PORT", DEFAULT_PORT)),
        service_name=os.getenv("SERVICE_NAME", DEFAULT_SERVICE_NAME),
        version=os.getenv("SERVICE_VERSION", DEFAULT_VERSION),
    )
