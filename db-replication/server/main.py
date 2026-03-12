import uvicorn

from app import create_app
from config import load_settings


def run() -> None:
    settings = load_settings()
    uvicorn.run(
        create_app(settings),
        host=settings.host,
        port=settings.port,
        access_log=False,
    )


if __name__ == "__main__":
    run()
