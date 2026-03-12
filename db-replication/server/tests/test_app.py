import sqlite3
from pathlib import Path

from fastapi.testclient import TestClient

from app import create_app
from config import Settings, TableConfig


def create_test_db(path: Path) -> None:
    connection = sqlite3.connect(path)
    connection.execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)")
    connection.execute("INSERT INTO users (name) VALUES ('Ada'), ('Grace'), ('Linus')")
    connection.execute("CREATE TABLE audit_log (event_id TEXT PRIMARY KEY, body TEXT)")
    connection.execute("INSERT INTO audit_log (event_id, body) VALUES ('a1', 'hello')")
    connection.commit()
    connection.close()


def make_client(tmp_path: Path) -> TestClient:
    db_path = tmp_path / "replicator.sqlite"
    create_test_db(db_path)
    settings = Settings(
        sqlite_db_path=str(db_path),
        access_token="secret-token",
        allowed_tables={
            "users": TableConfig(),
            "audit_log": TableConfig(),
        },
    )
    return TestClient(create_app(settings))


def test_requires_access_token(tmp_path: Path):
    client = make_client(tmp_path)
    response = client.get("/tables")
    assert response.status_code == 401
    assert response.json()["error"] == "unauthorized"


def test_tables_returns_allowlisted_tables(tmp_path: Path):
    client = make_client(tmp_path)
    response = client.get("/tables", params={"access_token": "secret-token"})
    assert response.status_code == 200
    body = response.json()
    assert [table["name"] for table in body["tables"]] == ["audit_log", "users"]
    users = next(table for table in body["tables"] if table["name"] == "users")
    audit_log = next(table for table in body["tables"] if table["name"] == "audit_log")
    assert users["supports_incremental"] is True
    assert audit_log["supports_incremental"] is False
    assert response.headers["Cache-Control"] == "no-store"


def test_schema_returns_columns(tmp_path: Path):
    client = make_client(tmp_path)
    response = client.get("/tables/users/schema", params={"access_token": "secret-token"})
    assert response.status_code == 200
    body = response.json()
    assert body["primary_key"] == "id"
    assert body["replication_key"] == "id"
    assert [column["name"] for column in body["columns"]] == ["id", "name"]


def test_rows_paginates_with_next_cursor(tmp_path: Path):
    client = make_client(tmp_path)
    first_page = client.get(
        "/tables/users/rows",
        params={"access_token": "secret-token", "after": 0, "limit": 2},
    )
    assert first_page.status_code == 200
    first_body = first_page.json()
    assert first_body["count"] == 2
    assert [row["id"] for row in first_body["rows"]] == [1, 2]
    assert first_body["next_cursor"] == 2
    assert first_body["has_more"] is True

    second_page = client.get(
        "/tables/users/rows",
        params={"access_token": "secret-token", "after": first_body["next_cursor"], "limit": 2},
    )
    assert second_page.status_code == 200
    second_body = second_page.json()
    assert [row["id"] for row in second_body["rows"]] == [3]
    assert second_body["next_cursor"] == 3
    assert second_body["has_more"] is False


def test_rows_rejects_unsupported_table(tmp_path: Path):
    client = make_client(tmp_path)
    response = client.get(
        "/tables/audit_log/rows",
        params={"access_token": "secret-token", "after": 0, "limit": 10},
    )
    assert response.status_code == 409
    assert response.json()["error"] == "unsupported_table"


def test_rows_rejects_limit_above_max(tmp_path: Path):
    client = make_client(tmp_path)
    response = client.get(
        "/tables/users/rows",
        params={"access_token": "secret-token", "after": 0, "limit": 1001},
    )
    assert response.status_code == 400
    assert response.json()["error"] == "invalid_request"


def test_validation_errors_return_bad_request(tmp_path: Path):
    client = make_client(tmp_path)
    response = client.get(
        "/tables/users/rows",
        params={"access_token": "secret-token", "after": -1, "limit": 10},
    )
    assert response.status_code == 400
    assert response.json()["error"] == "invalid_request"
