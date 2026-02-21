#!/usr/bin/env python3
"""
Test script for automate_www.py HTTP API (port 1611).

Calls all endpoints exposed by automate/automate_www.py, prints output,
validates responses, and prints a summary. Run with automate_www.py serving
on the same host (default: http://localhost:1611).

Usage:
  python test/automate_www_api_test.py [BASE_URL]
  python test/automate_www_api_test.py http://localhost:1611
  python test/automate_www_api_test.py http://81.204.237.36:1611
"""

import argparse
import json
import sys
from typing import Any, List, Tuple

try:
    import requests
except ImportError:
    print("This script requires the 'requests' library. Install with: pip install requests")
    sys.exit(1)

DEFAULT_BASE = "http://localhost:1611"

# Endpoints from automate_www.py (API_PATH_*)
ENDPOINTS = [
    ("/api/test", {}),
    ("/api/p1", {}),
    ("/api/p1", {"max_age": "0"}),
    ("/api/p1", {"maxAge": "60"}),
    ("/api/zendure", {}),
    ("/api/zendure", {"max_age": "0"}),
    ("/api/zendure", {"maxAge": "60"}),
    ("/api/status", {}),
    ("/api/all", {}),
    ("/api/automation_status", {}),
    ("/api/wh_per_hour", {}),
    ("/api/refresh", {}),
]


def run_one(base_url: str, path: str, params: dict, timeout: int) -> dict:
    """Perform one GET request; return dict with name, url, status_code, ok, body, error."""
    url = base_url.rstrip("/") + path
    name = path + ("?" + "&".join(f"{k}={v}" for k, v in params.items()) if params else "")
    out = {"name": name, "url": url, "status_code": None, "ok": False, "body": None, "error": None}
    try:
        r = requests.get(url, params=params, timeout=timeout)
        out["status_code"] = r.status_code
        out["ok"] = 200 <= r.status_code < 300
        try:
            out["body"] = r.json()
        except Exception:
            out["body"] = r.text[:500] if r.text else None
    except requests.exceptions.RequestException as e:
        out["error"] = str(e)
    return out


def validate_test(result: dict) -> Tuple[bool, List[str]]:
    """
    Validate a single test result. Returns (passed, list of failure reasons).
    """
    failures = []
    if result.get("error"):
        failures.append(f"Request error: {result['error']}")
        return False, failures

    code = result.get("status_code")
    body = result.get("body")
    name = result.get("name", "")

    # /api/refresh can return 200, 503 (not available), or 500 (error)
    if "/api/refresh" in name:
        if code not in (200, 503, 500):
            failures.append(f"Expected status 200, 503, or 500; got {code}")
        if code == 200 and not isinstance(body, dict):
            failures.append("Expected JSON object when status is 200")
        if code == 200 and body is not None and "ok" not in body:
            failures.append("Expected 'ok' key in response when status is 200")
        return len(failures) == 0, failures

    # /api/wh_per_hour can return 200 (dict with dates) or 200 with error key if DB missing
    if "/api/wh_per_hour" in name:
        if code != 200:
            failures.append(f"Expected status 200; got {code}")
        if code == 200 and body is not None and isinstance(body, dict) and "error" in body:
            # Acceptable: DB not available
            pass
        elif code == 200 and body is not None and not isinstance(body, dict):
            failures.append("Expected JSON object")
        return len(failures) == 0, failures

    # All other endpoints expect 200 and JSON
    if code != 200:
        failures.append(f"Expected status 200; got {code}")

    if body is None and code == 200:
        failures.append("Empty or non-JSON response")

    if name == "/api/test" and isinstance(body, dict):
        if body.get("status") != "ok":
            failures.append("Expected status 'ok' in /api/test")
        if "endpoints" not in body:
            failures.append("Expected 'endpoints' in /api/test")

    if name == "/api/p1" or (name.startswith("/api/p1") and "max" in name):
        if code == 200 and body is not None:
            if not isinstance(body, dict):
                failures.append("Expected JSON object")
            elif body.get("readings") is not None and not isinstance(body.get("readings"), (dict, type(None))):
                failures.append("Expected 'readings' to be dict or null")
            if "timestamp" not in body and body.get("readings") is not None:
                failures.append("Expected 'timestamp' when readings present")

    if name == "/api/zendure" or (name.startswith("/api/zendure") and "max" in name):
        if code == 200 and body is not None:
            if not isinstance(body, dict):
                failures.append("Expected JSON object")
            elif body.get("readings") is not None and not isinstance(body.get("readings"), (dict, type(None))):
                failures.append("Expected 'readings' to be dict or null")

    if name == "/api/status" and code == 200 and body is not None:
        if not isinstance(body, dict):
            failures.append("Expected JSON object")
        # status can be null or object with eventType, oldValue, newValue, timestamp
        if body is not None and not isinstance(body, dict):
            failures.append("Expected object or null for /api/status")

    if name == "/api/all" and code == 200 and body is not None:
        if not isinstance(body, dict):
            failures.append("Expected JSON object")
        for key in ("p1", "zendure", "status"):
            if key not in body:
                failures.append(f"Expected '{key}' in /api/all")

    if name == "/api/automation_status" and code == 200 and body is not None:
        if not isinstance(body, dict):
            failures.append("Expected JSON object")
        for key in ("success", "lastChanges", "lastAlive", "runningTime", "entryCount", "lastUpdate"):
            if key not in body:
                failures.append(f"Expected '{key}' in /api/automation_status")

    return len(failures) == 0, failures


def format_body(body: Any, max_len: int = 800) -> str:
    """Format response body for display; truncate if large."""
    if body is None:
        return "(null)"
    s = json.dumps(body, indent=2, default=str)
    if len(s) > max_len:
        s = s[:max_len] + "\n... (truncated)"
    return s


def main() -> int:
    parser = argparse.ArgumentParser(description="Test automate_www.py HTTP API")
    parser.add_argument(
        "base_url",
        nargs="?",
        default=DEFAULT_BASE,
        help=f"Base URL (default: {DEFAULT_BASE})",
    )
    parser.add_argument(
        "--timeout",
        type=int,
        default=15,
        help="Request timeout in seconds (default: 15)",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Only print summary, not full response bodies",
    )
    args = parser.parse_args()
    base = args.base_url.rstrip("/")

    print("=" * 60)
    print("automate_www API tests")
    print("=" * 60)
    print(f"Base URL: {base}")
    print(f"Timeout:  {args.timeout}s")
    print()

    results = []
    for path, params in ENDPOINTS:
        result = run_one(base, path, params, args.timeout)
        passed, failures = validate_test(result)
        result["passed"] = passed
        result["failures"] = failures
        results.append(result)

        status = "PASS" if passed else "FAIL"
        print(f"[{status}] {result['name']}")
        print(f"  URL: {result['url']}")
        if params:
            print(f"  Params: {params}")
        print(f"  HTTP: {result['status_code'] or result.get('error', 'N/A')}")
        if result.get("error"):
            print(f"  Error: {result['error']}")
        if failures:
            for f in failures:
                print(f"  Validation: {f}")
        if not args.quiet:
            body_str = format_body(result.get("body"))
            print("  Response:")
            for line in body_str.splitlines():
                print("    " + line)
        print()

    # Summary
    passed_count = sum(1 for r in results if r["passed"])
    failed_count = len(results) - passed_count
    print("=" * 60)
    print("SUMMARY")
    print("=" * 60)
    print(f"Total:  {len(results)}")
    print(f"Passed: {passed_count}")
    print(f"Failed: {failed_count}")
    if failed_count:
        print("\nFailed tests:")
        for r in results:
            if not r["passed"]:
                print(f"  - {r['name']}: {r['failures']}")
    print("=" * 60)
    return 0 if failed_count == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
