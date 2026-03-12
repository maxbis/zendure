#!/usr/bin/env python3
"""
Minimal HTTP server for testing /api/wh_per_hour performance in isolation.

Serves:
  GET /api/wh_per_hour
  GET /health

Uses the same compute_wh_per_hour() implementation as automate_www.py, but does
not start the full automation runtime.
"""

from __future__ import annotations

import http.server
import json
import os
import socketserver
import sys
import time
from pathlib import Path
from urllib.parse import urlparse


SCRIPT_DIR = Path(__file__).resolve().parent
AUTOMATE_DIR = SCRIPT_DIR.parent
if str(AUTOMATE_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATE_DIR))

from automate_www import WH_PER_HOUR_DAYS_DEFAULT, compute_wh_per_hour  # noqa: E402
from config_loader import load_config  # noqa: E402


PORT = 1612


def load_wh_per_hour_db_path() -> str:
    config_path = AUTOMATE_DIR / "config" / "config.jsonc"
    if config_path.exists():
        cfg = load_config(config_path)
        data_dir = str(cfg.get("dataDir", "./data/"))
        raw_db = os.path.join(data_dir.rstrip("/").rstrip("\\"), "status_updates.db")
        return str(cfg.get("whPerHourDbPath", raw_db))
    return str(AUTOMATE_DIR / "data" / "status_updates.db")


class WhPerHourOnlyHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.0"

    def _send_json(self, payload: dict, status: int = 200) -> None:
        body = json.dumps(payload, sort_keys=True).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path == "/health":
            self._send_json({"ok": True, "port": PORT})
            return
        if parsed.path != "/api/wh_per_hour":
            self._send_json({"error": "Not Found"}, 404)
            return

        db_path = load_wh_per_hour_db_path()
        if not os.path.exists(db_path):
            self._send_json({"error": f"DB not found: {db_path}"}, 503)
            return

        started = time.perf_counter()
        data = compute_wh_per_hour(db_path, int(time.time()), WH_PER_HOUR_DAYS_DEFAULT)
        elapsed_ms = (time.perf_counter() - started) * 1000.0
        print(
            f"[serve_wh_per_hour_only] db={db_path} elapsed_ms={elapsed_ms:.2f}",
            flush=True,
        )
        self._send_json(data)

    def log_message(self, msg_format, *args):
        pass


class ThreadedTCPServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def main() -> int:
    db_path = load_wh_per_hour_db_path()
    print(f"[serve_wh_per_hour_only] listening on http://0.0.0.0:{PORT}", flush=True)
    print(f"[serve_wh_per_hour_only] wh_per_hour_db_path={db_path}", flush=True)
    with ThreadedTCPServer(("", PORT), WhPerHourOnlyHandler) as server:
        server.serve_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
