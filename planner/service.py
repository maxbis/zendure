from __future__ import annotations

import argparse
import http.server
import json
import socketserver
from datetime import datetime
from typing import Any, Dict, Optional

from zoneinfo import ZoneInfo

from planner.clients import UpstreamError, fetch_battery_state, fetch_price_payload, fetch_shortwave_payload
from planner.config import PlannerSettings, load_settings
from planner.forecast import select_horizon_dates
from planner.forecast import normalize_load_forecast_payload
from planner.models import LoadForecastRecord, PlannerResult
from planner.optimizer import generate_plan
from planner.storage import LoadForecastStore


class PlannerApp:
    def __init__(self, settings: PlannerSettings):
        self.settings = settings
        self.store = LoadForecastStore(
            settings.load_forecast_path,
            settings.default_load_forecast_template_path,
        )

    def now(self) -> datetime:
        return datetime.now(ZoneInfo(self.settings.timezone))

    def handle_load_forecast_payload(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        record = normalize_load_forecast_payload(payload, self.now().isoformat())
        self.store.put(record)
        return {"ok": True, "stored": record.to_dict()}

    def _load_forecasts(self) -> Dict[str, LoadForecastRecord]:
        return self.store.load_all()

    def _load_forecasts_for_horizon(
        self,
        horizon_dates: list[str],
        now_iso: str,
    ) -> tuple[Dict[str, LoadForecastRecord], list[str]]:
        return self.store.load_all_for_dates(horizon_dates, self.settings.timezone, now_iso)

    def build_plan(self) -> PlannerResult:
        now = self.now()
        horizon_dates = select_horizon_dates(now, self.settings)
        load_forecasts, defaulted_dates = self._load_forecasts_for_horizon(
            horizon_dates,
            now.isoformat(),
        )
        prices = fetch_price_payload(self.settings)
        battery_state = fetch_battery_state(self.settings)
        shortwave = fetch_shortwave_payload(self.settings)
        result = generate_plan(
            now=now,
            settings=self.settings,
            battery_state=battery_state,
            load_forecasts=load_forecasts,
            import_today=prices.import_today,
            import_tomorrow=prices.import_tomorrow,
            export_today=prices.export_today,
            export_tomorrow=prices.export_tomorrow,
            shortwave_payload=shortwave,
        )
        if defaulted_dates:
            warnings = result.meta.setdefault("warnings", [])
            for date in defaulted_dates:
                warning = f"Using default load forecast template for {date}"
                if warning not in warnings:
                    warnings.append(warning)
        return result

    def build_health_payload(self) -> Dict[str, Any]:
        forecast_records = self._load_forecasts()
        payload: Dict[str, Any] = {
            "ok": True,
            "service": "planner",
            "timezone": self.settings.timezone,
            "loadForecastDates": sorted(forecast_records.keys()),
            "upstreams": {},
        }
        for name, loader in (
            ("prices", lambda: fetch_price_payload(self.settings)),
            ("battery", lambda: fetch_battery_state(self.settings)),
            ("shortwave", lambda: fetch_shortwave_payload(self.settings)),
        ):
            try:
                loader()
                payload["upstreams"][name] = {"ok": True}
            except Exception as exc:
                payload["ok"] = False
                payload["upstreams"][name] = {"ok": False, "error": str(exc)}
        return payload


class PlannerTCPServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True
    block_on_close = False

    def __init__(self, server_address, request_handler_class, app: PlannerApp):
        super().__init__(server_address, request_handler_class)
        self.app = app


class PlannerRequestHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.0"

    def _send_json(self, payload: Dict[str, Any], status: int = 200) -> None:
        body = json.dumps(payload, sort_keys=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def _read_json_body(self) -> Dict[str, Any]:
        content_length = int(self.headers.get("Content-Length", "0") or 0)
        raw = self.rfile.read(content_length) if content_length > 0 else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError as exc:
            raise ValueError(f"Invalid JSON body: {exc}") from exc
        if not isinstance(payload, dict):
            raise ValueError("JSON body must be an object")
        return payload

    def do_GET(self) -> None:
        if self.path == "/planner/health":
            self._send_json(self.server.app.build_health_payload())  # type: ignore[attr-defined]
            return
        if self.path == "/planner/plan":
            try:
                result = self.server.app.build_plan()  # type: ignore[attr-defined]
            except UpstreamError as exc:
                self._send_json({"success": False, "error": str(exc)}, 503)
                return
            except Exception as exc:
                self._send_json({"success": False, "error": str(exc)}, 500)
                return
            self._send_json(result.debug_payload(), 200 if result.success else 503)
            return
        if self.path == "/schedule/resolved":
            try:
                result = self.server.app.build_plan()  # type: ignore[attr-defined]
            except UpstreamError as exc:
                self._send_json({"success": False, "error": str(exc)}, 503)
                return
            except Exception as exc:
                self._send_json({"success": False, "error": str(exc)}, 500)
                return
            self._send_json(result.compatibility_payload(), 200 if result.success else 503)
            return
        if self.path in ("/", "/planner"):
            self._send_json(
                {
                    "ok": True,
                    "service": "planner",
                    "endpoints": [
                        {"path": "/schedule/resolved", "method": "GET"},
                        {"path": "/planner/load-forecast", "method": "POST"},
                        {"path": "/planner/plan", "method": "GET"},
                        {"path": "/planner/health", "method": "GET"},
                    ],
                }
            )
            return
        self.send_error(404, "Not Found")

    def do_POST(self) -> None:
        if self.path != "/planner/load-forecast":
            self.send_error(404, "Not Found")
            return
        try:
            payload = self._read_json_body()
            response = self.server.app.handle_load_forecast_payload(payload)  # type: ignore[attr-defined]
        except ValueError as exc:
            self._send_json({"ok": False, "error": str(exc)}, 400)
            return
        except Exception as exc:
            self._send_json({"ok": False, "error": str(exc)}, 500)
            return
        self._send_json(response, 200)

    def log_message(self, msg_format: str, *args: Any) -> None:
        return


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Standalone planner service")
    parser.add_argument("--host", default=None, help="Override bind host")
    parser.add_argument("--port", type=int, default=None, help="Override bind port")
    return parser


def main(argv: Optional[list[str]] = None) -> int:
    parser = build_arg_parser()
    args = parser.parse_args(argv)
    settings = load_settings()
    if args.host:
        settings = PlannerSettings(**{**settings.__dict__, "service_host": args.host})
    if args.port:
        settings = PlannerSettings(**{**settings.__dict__, "service_port": int(args.port)})
    app = PlannerApp(settings)
    with PlannerTCPServer((settings.service_host, settings.service_port), PlannerRequestHandler, app) as server:
        print(f"Planner service listening on http://{settings.service_host}:{settings.service_port}")
        server.serve_forever()
    return 0
