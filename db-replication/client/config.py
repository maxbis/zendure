from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


ENV_FILE_NAME = ".env"
DEFAULT_BATCH_SIZE = 250
DEFAULT_MARIADB_PORT = 3306


@dataclass(frozen=True)
class Settings:
    server_base_url: str
    access_token: str
    mariadb_host: str
    mariadb_port: int
    mariadb_database: str
    mariadb_user: str
    mariadb_password: str
    batch_size: int = DEFAULT_BATCH_SIZE


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


def _require_env(name: str) -> str:
    value = os.getenv(name)
    if not value:
        raise ValueError(f"{name} is required")
    return value


def load_settings() -> Settings:
    _load_dotenv_file()

    batch_size = int(os.getenv("BATCH_SIZE", DEFAULT_BATCH_SIZE))
    if batch_size < 1:
        raise ValueError("BATCH_SIZE must be >= 1")

    return Settings(
        server_base_url=_require_env("SERVER_BASE_URL").rstrip("/"),
        access_token=_require_env("ACCESS_TOKEN"),
        mariadb_host=_require_env("MARIADB_HOST"),
        mariadb_port=int(os.getenv("MARIADB_PORT", DEFAULT_MARIADB_PORT)),
        mariadb_database=_require_env("MARIADB_DATABASE"),
        mariadb_user=_require_env("MARIADB_USER"),
        mariadb_password=_require_env("MARIADB_PASSWORD"),
        batch_size=batch_size,
    )
