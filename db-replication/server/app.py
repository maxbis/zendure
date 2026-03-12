from __future__ import annotations

import logging
import time
import uuid
from contextlib import contextmanager

from fastapi import Depends, FastAPI, HTTPException, Query, Request, Response, status
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from config import Settings, load_settings
from models import ErrorResponse, HealthResponse, RowsResponse, SchemaResponse, TablesResponse
from replication import fetch_rows
from sqlite_introspection import connect_readonly, get_table_meta, list_configured_tables


logger = logging.getLogger("sqlite_replicator")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")


def create_app(settings: Settings | None = None) -> FastAPI:
    app_settings = settings or load_settings()
    app = FastAPI(title=app_settings.service_name, version=app_settings.version)

    @app.middleware("http")
    async def request_context_middleware(request: Request, call_next):
        request_id = request.headers.get("x-request-id", str(uuid.uuid4()))
        start = time.perf_counter()
        response = await call_next(request)
        duration_ms = round((time.perf_counter() - start) * 1000, 2)

        sanitized_query = "&".join(
            f"{key}=REDACTED" if key == "access_token" else f"{key}={value}"
            for key, value in request.query_params.multi_items()
        )
        path = request.url.path + (f"?{sanitized_query}" if sanitized_query else "")
        logger.info(
            "request_id=%s method=%s path=%s status=%s duration_ms=%s",
            request_id,
            request.method,
            path,
            response.status_code,
            duration_ms,
        )

        response.headers["x-request-id"] = request_id
        if request.url.path != "/health" or app_settings.health_requires_token:
            response.headers["Cache-Control"] = "no-store"
        return response

    @app.exception_handler(HTTPException)
    async def http_exception_handler(_: Request, exc: HTTPException):
        detail = exc.detail
        if isinstance(detail, dict) and {"error", "message"}.issubset(detail):
            payload = detail
        else:
            payload = {
                "error": "http_error",
                "message": str(detail),
                "details": None,
            }
        return JSONResponse(status_code=exc.status_code, content=ErrorResponse(**payload).model_dump())

    @app.exception_handler(RequestValidationError)
    async def validation_exception_handler(_: Request, exc: RequestValidationError):
        payload = ErrorResponse(
            error="invalid_request",
            message="Request validation failed",
            details=exc.errors(),
        )
        return JSONResponse(status_code=status.HTTP_400_BAD_REQUEST, content=payload.model_dump())

    @app.exception_handler(Exception)
    async def unhandled_exception_handler(_: Request, exc: Exception):
        logger.exception("Unhandled application error")
        payload = ErrorResponse(
            error="internal_error",
            message="Internal server error",
            details=str(exc),
        )
        return JSONResponse(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, content=payload.model_dump())

    def require_token(access_token: str | None = Query(default=None)) -> None:
        if access_token != app_settings.access_token:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail={
                    "error": "unauthorized",
                    "message": "Missing or invalid access token",
                    "details": None,
                },
            )

    def optional_health_token(access_token: str | None = Query(default=None)) -> None:
        if app_settings.health_requires_token:
            require_token(access_token)

    @contextmanager
    def readonly_connection():
        connection = connect_readonly(app_settings)
        try:
            yield connection
        finally:
            connection.close()

    def require_allowed_table(table: str):
        table_config = app_settings.allowed_tables.get(table)
        if not table_config or not table_config.enabled:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail={
                    "error": "unknown_table",
                    "message": f"Table '{table}' is not configured for replication",
                    "details": None,
                },
            )
        return table_config

    @app.get("/health", response_model=HealthResponse)
    def health(_: None = Depends(optional_health_token)):
        with readonly_connection():
            return HealthResponse(
                status="ok",
                service=app_settings.service_name,
                version=app_settings.version,
            )

    @app.get("/tables", response_model=TablesResponse)
    def tables(_: None = Depends(require_token)):
        with readonly_connection() as connection:
            metadata = list_configured_tables(connection, app_settings)

        return TablesResponse(
            tables=[
                {
                    "name": table.name,
                    "sync_mode": "pk_cursor",
                    "primary_key": table.primary_key,
                    "replication_key": table.replication_key,
                    "supports_incremental": table.supports_incremental,
                    "notes": table.notes,
                }
                for table in metadata
            ]
        )

    @app.get("/tables/{table}/schema", response_model=SchemaResponse)
    def table_schema(table: str, _: None = Depends(require_token)):
        table_config = require_allowed_table(table)
        with readonly_connection() as connection:
            table_meta = get_table_meta(connection, table, table_config)

        return SchemaResponse(
            table=table_meta.name,
            columns=[
                {
                    "name": column.name,
                    "sqlite_type": column.sqlite_type,
                    "nullable": column.nullable,
                    "default": column.default,
                    "ordinal": column.ordinal,
                }
                for column in table_meta.columns
            ],
            primary_key=table_meta.primary_key,
            replication_key=table_meta.replication_key,
        )

    @app.get("/tables/{table}/rows", response_model=RowsResponse)
    def table_rows(
        table: str,
        after: int = Query(default=0, ge=0),
        limit: int = Query(default=app_settings.default_page_size, ge=1),
        _: None = Depends(require_token),
    ):
        if limit > app_settings.max_page_size:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail={
                    "error": "invalid_request",
                    "message": f"limit must be between 1 and {app_settings.max_page_size}",
                    "details": {"limit": limit},
                },
            )

        table_config = require_allowed_table(table)
        with readonly_connection() as connection:
            table_meta = get_table_meta(connection, table, table_config)
            rows, next_cursor, has_more = fetch_rows(connection, table_meta, after, limit)

        return RowsResponse(
            table=table_meta.name,
            replication_key=table_meta.replication_key or "",
            rows=rows,
            count=len(rows),
            next_cursor=next_cursor,
            has_more=has_more,
        )

    return app


try:
    app = create_app()
except ValueError as exc:
    logger.warning("Application not fully configured: %s", exc)
    app = FastAPI(title="sqlite-replication-source")

    @app.get("/health")
    def unconfigured_health(response: Response):
        response.status_code = status.HTTP_500_INTERNAL_SERVER_ERROR
        return ErrorResponse(
            error="not_configured",
            message=str(exc),
            details=None,
        ).model_dump()


if __name__ == "__main__":
    import uvicorn

    settings = load_settings()
    uvicorn.run(
        create_app(settings),
        host=settings.host,
        port=settings.port,
        access_log=False,
    )
