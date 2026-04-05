"""CLI entry point; delegates to the same launcher as `python db_replication_server.py`."""

from __future__ import annotations

if __name__ == "__main__":
    import uvicorn

    from config import load_settings
    from db_replication_server import create_app

    settings = load_settings()
    uvicorn.run(
        create_app(settings),
        host=settings.host,
        port=settings.port,
        access_log=False,
    )
