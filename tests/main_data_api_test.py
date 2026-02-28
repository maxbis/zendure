#!/usr/bin/env python3
"""
Tests for main/data/api/data_api.php.

Covers GET schedule (resolved and raw), schedule with date (e.g. tomorrow),
and other type/list/price/file options. Validates HTTP 200 and API output shape.

With --production URL, runs the same requests against local and production and
compares responses; reports any deviation and total deviation count.

Usage:
  python tools/tests/main_data_api_test.py [BASE_URL]
  python tools/tests/main_data_api_test.py http://localhost/zendure/main/data/api/data_api.php
  python tools/tests/main_data_api_test.py --production https://zendure.qool.ovh/main/data/api/data_api.php
"""

import argparse
import json
import sys
from datetime import date, timedelta
from typing import Any, Callable, Dict, List, Set, Tuple

try:
    import requests
except ImportError:
    print("This script requires the 'requests' library. Install with: pip install requests")
    sys.exit(1)

DEFAULT_BASE = "http://localhost/zendure/main/data/api/data_api.php"
DEFAULT_PRODUCTION = "https://zendure.qool.ovh/main/data/api/data_api.php"

# Keys (top-level or final path segment) to ignore when comparing local vs production
# (e.g. server time and file mtimes differ)
COMPARE_IGNORE_KEYS: Set[str] = {"timestamp", "currentHour", "currentTime"}


def run_get(base_url: str, params: Dict[str, str], timeout: int) -> Dict[str, Any]:
    """GET data_api.php with params. Return dict with url, status_code, ok, body, error."""
    url = base_url.split("?")[0]
    out = {
        "url": url,
        "params": params,
        "status_code": None,
        "ok": False,
        "body": None,
        "error": None,
    }
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


def run_post(
    base_url: str,
    params: Dict[str, str],
    json_body: Dict[str, Any],
    timeout: int,
) -> Dict[str, Any]:
    """POST data_api.php with params and JSON body. Return same shape as run_get."""
    url = base_url.split("?")[0]
    out = {
        "url": url,
        "params": params,
        "status_code": None,
        "ok": False,
        "body": None,
        "error": None,
    }
    try:
        r = requests.post(url, params=params, json=json_body, timeout=timeout)
        out["status_code"] = r.status_code
        out["ok"] = 200 <= r.status_code < 300
        try:
            out["body"] = r.json()
        except Exception:
            out["body"] = r.text[:500] if r.text else None
    except requests.exceptions.RequestException as e:
        out["error"] = str(e)
    return out


def run_delete(
    base_url: str,
    params: Dict[str, str],
    json_body: Dict[str, Any],
    timeout: int,
) -> Dict[str, Any]:
    """DELETE data_api.php with params and JSON body (for schedule key). Return same shape as run_get."""
    url = base_url.split("?")[0]
    out = {
        "url": url,
        "params": params,
        "status_code": None,
        "ok": False,
        "body": None,
        "error": None,
    }
    try:
        r = requests.delete(url, params=params, json=json_body, timeout=timeout)
        out["status_code"] = r.status_code
        out["ok"] = 200 <= r.status_code < 300
        try:
            out["body"] = r.json()
        except Exception:
            out["body"] = r.text[:500] if r.text else None
    except requests.exceptions.RequestException as e:
        out["error"] = str(e)
    return out


def validate_schedule_resolved(result: Dict[str, Any]) -> Tuple[bool, List[str]]:
    """Expect success, date, currentHour, currentTime, resolved, entries."""
    failures = []
    if result.get("error"):
        failures.append(f"Request error: {result['error']}")
        return False, failures
    if result.get("status_code") != 200:
        failures.append(f"Expected HTTP 200, got {result.get('status_code')}")
    body = result.get("body")
    if not isinstance(body, dict):
        failures.append("Response body should be a JSON object")
        return len(failures) == 0, failures
    if not body.get("success"):
        failures.append("Expected success=true in resolved schedule response")
    for key in ("date", "currentHour", "currentTime", "resolved", "entries"):
        if key not in body:
            failures.append(f"Expected key '{key}' in resolved schedule response")
    if "entries" in body and not isinstance(body["entries"], list):
        failures.append("Expected 'entries' to be a list")
    return len(failures) == 0, failures


def validate_schedule_raw(result: Dict[str, Any]) -> Tuple[bool, List[str]]:
    """Expect success, data, file, timestamp (raw schedule = no resolved)."""
    failures = []
    if result.get("error"):
        failures.append(f"Request error: {result['error']}")
        return False, failures
    if result.get("status_code") != 200:
        failures.append(f"Expected HTTP 200, got {result.get('status_code')}")
    body = result.get("body")
    if not isinstance(body, dict):
        failures.append("Response body should be a JSON object")
        return len(failures) == 0, failures
    if not body.get("success"):
        failures.append("Expected success=true in raw schedule response")
    for key in ("data", "file", "timestamp"):
        if key not in body:
            failures.append(f"Expected key '{key}' in raw schedule response")
    return len(failures) == 0, failures


def validate_list_or_files(result: Dict[str, Any], key_files: str = "files") -> Tuple[bool, List[str]]:
    """Expect success, files (or key_files), count."""
    failures = []
    if result.get("error"):
        failures.append(f"Request error: {result['error']}")
        return False, failures
    if result.get("status_code") != 200:
        failures.append(f"Expected HTTP 200, got {result.get('status_code')}")
    body = result.get("body")
    if not isinstance(body, dict):
        failures.append("Response body should be a JSON object")
        return len(failures) == 0, failures
    if not body.get("success"):
        failures.append("Expected success=true")
    if key_files not in body:
        failures.append(f"Expected key '{key_files}' in response")
    if "count" not in body:
        failures.append("Expected key 'count' in response")
    return len(failures) == 0, failures


def validate_data_or_error(result: Dict[str, Any], expect_data_key: bool = True) -> Tuple[bool, List[str]]:
    """Expect 200, JSON object with success; if success then data/file/timestamp or error key."""
    failures = []
    if result.get("error"):
        failures.append(f"Request error: {result['error']}")
        return False, failures
    if result.get("status_code") != 200:
        failures.append(f"Expected HTTP 200, got {result.get('status_code')}")
    body = result.get("body")
    if not isinstance(body, dict):
        failures.append("Response body should be a JSON object")
        return len(failures) == 0, failures
    if "success" not in body:
        failures.append("Expected key 'success' in response")
    if expect_data_key and body.get("success") and "data" not in body and "error" not in body:
        if "file" not in body:
            failures.append("Expected 'data' or 'file' (or 'error') in success response")
    return len(failures) == 0, failures


def validate_error_response(result: Dict[str, Any], status_ok: bool = True) -> Tuple[bool, List[str]]:
    """Expect JSON with success=false and error key (optionally still HTTP 200)."""
    failures = []
    if result.get("error"):
        failures.append(f"Request error: {result['error']}")
        return False, failures
    if status_ok and result.get("status_code") != 200:
        failures.append(f"Expected HTTP 200 for API error response, got {result.get('status_code')}")
    body = result.get("body")
    if not isinstance(body, dict):
        failures.append("Response body should be a JSON object")
        return len(failures) == 0, failures
    if body.get("success") is not False:
        failures.append("Expected success=false")
    if "error" not in body:
        failures.append("Expected key 'error' in error response")
    return len(failures) == 0, failures


# Schedule key used for add/list/delete test (YYYYMMDDHHmm; far future to avoid collision)
SCHEDULE_TEST_KEY = "203012010000"
SCHEDULE_TEST_VALUE = 99


def run_schedule_add_list_delete(
    base_url: str,
    timeout: int,
) -> Tuple[bool, List[str], Dict[str, Any]]:
    """
    POST a schedule entry, GET schedule and verify it appears, DELETE it, GET and verify gone.
    Returns (passed, failures, step_results).
    """
    failures: List[str] = []
    steps: Dict[str, Dict[str, Any]] = {}

    params_post = {"type": "schedule"}
    body_add = {"key": SCHEDULE_TEST_KEY, "value": SCHEDULE_TEST_VALUE}
    body_delete = {"key": SCHEDULE_TEST_KEY}

    # 1. POST add entry
    steps["post_add"] = run_post(base_url, params_post, body_add, timeout)
    r = steps["post_add"]
    if r.get("error"):
        failures.append(f"POST add: {r['error']}")
        return False, failures, steps
    if r.get("status_code") != 200:
        failures.append(f"POST add: HTTP {r.get('status_code')}")
    body = r.get("body")
    if isinstance(body, dict) and not body.get("success"):
        failures.append(f"POST add: {body.get('error', 'unknown')}")
    if failures:
        return False, failures, steps

    # 2. GET schedule and check entry is present
    steps["get_after_add"] = run_get(base_url, {"type": "schedule"}, timeout)
    r = steps["get_after_add"]
    if r.get("error"):
        failures.append(f"GET after add: {r['error']}")
        return False, failures, steps
    body = r.get("body")
    if not isinstance(body, dict) or not body.get("success"):
        failures.append("GET after add: success or body missing")
    else:
        data = body.get("data")
        if not isinstance(data, dict):
            failures.append("GET after add: data is not a dict")
        elif data.get(SCHEDULE_TEST_KEY) != SCHEDULE_TEST_VALUE:
            failures.append(
                f"GET after add: expected data['{SCHEDULE_TEST_KEY}'] == {SCHEDULE_TEST_VALUE}, got {data.get(SCHEDULE_TEST_KEY)}"
            )
    if failures:
        return False, failures, steps

    # 3. DELETE entry
    steps["delete"] = run_delete(base_url, params_post, body_delete, timeout)
    r = steps["delete"]
    if r.get("error"):
        failures.append(f"DELETE: {r['error']}")
        return False, failures, steps
    body = r.get("body")
    if isinstance(body, dict) and not body.get("success"):
        failures.append(f"DELETE: {body.get('error', 'unknown')}")
    if failures:
        return False, failures, steps

    # 4. GET schedule and check entry is gone
    steps["get_after_delete"] = run_get(base_url, {"type": "schedule"}, timeout)
    r = steps["get_after_delete"]
    if r.get("error"):
        failures.append(f"GET after delete: {r['error']}")
        return False, failures, steps
    body = r.get("body")
    if not isinstance(body, dict) or not body.get("success"):
        failures.append("GET after delete: success or body missing")
    else:
        data = body.get("data")
        if isinstance(data, dict) and SCHEDULE_TEST_KEY in data:
            failures.append(
                f"GET after delete: entry '{SCHEDULE_TEST_KEY}' should be gone, got {data.get(SCHEDULE_TEST_KEY)}"
            )

    return len(failures) == 0, failures, steps


def format_body(body: Any, max_len: int = 600) -> str:
    if body is None:
        return "(null)"
    s = json.dumps(body, indent=2, default=str)
    if len(s) > max_len:
        s = s[:max_len] + "\n... (truncated)"
    return s


def _path_tail(path: str) -> str:
    """Last path segment for ignore check (e.g. 'timestamp' from 'resolved.entries[0].timestamp')."""
    if not path:
        return ""
    segment = path.split(".")[-1].split("[")[0]
    return segment


def _compare_values(
    local_val: Any,
    prod_val: Any,
    path: str,
    ignore_keys: Set[str],
) -> List[str]:
    """Recursive comparison; returns list of deviation messages."""
    tail = _path_tail(path)
    if tail in ignore_keys:
        return []

    if type(local_val) != type(prod_val):
        return [
            f"{path}: type differs ({type(local_val).__name__} vs {type(prod_val).__name__})"
        ]

    if isinstance(local_val, dict):
        all_keys = set(local_val) | set(prod_val)
        devs: List[str] = []
        for k in all_keys:
            p = f"{path}.{k}" if path else k
            if k not in local_val:
                devs.append(f"{p}: only in production")
            elif k not in prod_val:
                devs.append(f"{p}: only in local")
            else:
                devs.extend(
                    _compare_values(local_val[k], prod_val[k], p, ignore_keys)
                )
        return devs

    if isinstance(local_val, list):
        if len(local_val) != len(prod_val):
            return [
                f"{path}: list length {len(local_val)} vs {len(prod_val)}"
            ]
        try:
            local_sorted = sorted(
                local_val,
                key=lambda x: json.dumps(x, sort_keys=True, default=str),
            )
            prod_sorted = sorted(
                prod_val,
                key=lambda x: json.dumps(x, sort_keys=True, default=str),
            )
        except (TypeError, ValueError):
            local_sorted = local_val
            prod_sorted = prod_val
        devs = []
        for i, (a, b) in enumerate(zip(local_sorted, prod_sorted)):
            devs.extend(
                _compare_values(a, b, f"{path}[{i}]", ignore_keys)
            )
        return devs

    if local_val != prod_val:
        local_repr = repr(local_val)[:60]
        prod_repr = repr(prod_val)[:60]
        return [f"{path}: local={local_repr} vs prod={prod_repr}"]
    return []


def compare_results(
    local_result: Dict[str, Any],
    prod_result: Dict[str, Any],
    ignore_keys: Set[str] = COMPARE_IGNORE_KEYS,
) -> List[str]:
    """Compare local and production GET results. Return list of deviation messages."""
    deviations: List[str] = []

    if local_result.get("error") and prod_result.get("error"):
        if local_result["error"] != prod_result["error"]:
            deviations.append(
                f"request error: local={local_result['error']} vs prod={prod_result['error']}"
            )
        return deviations
    if local_result.get("error"):
        deviations.append(f"local request error: {local_result['error']}")
        return deviations
    if prod_result.get("error"):
        deviations.append(f"production request error: {prod_result['error']}")
        return deviations

    local_code = local_result.get("status_code")
    prod_code = prod_result.get("status_code")
    if local_code != prod_code:
        deviations.append(f"HTTP status: local={local_code} vs prod={prod_code}")

    local_body = local_result.get("body")
    prod_body = prod_result.get("body")
    if not isinstance(local_body, dict) or not isinstance(prod_body, dict):
        if type(local_body) != type(prod_body) or local_body != prod_body:
            deviations.append(
                "body: type or value differs (non-dict or unequal)"
            )
        return deviations

    deviations.extend(
        _compare_values(local_body, prod_body, "", ignore_keys)
    )
    return deviations


def main() -> int:
    parser = argparse.ArgumentParser(description="Test main/data/api/data_api.php")
    parser.add_argument(
        "base_url",
        nargs="?",
        default=DEFAULT_BASE,
        help=f"Local/base data API URL (default: {DEFAULT_BASE})",
    )
    parser.add_argument(
        "--production",
        nargs="?",
        const=DEFAULT_PRODUCTION,
        default=None,
        metavar="URL",
        help=f"If set, same requests run against local and production and responses are compared. URL defaults to {DEFAULT_PRODUCTION}",
    )
    parser.add_argument("--timeout", type=int, default=15, help="Request timeout in seconds")
    parser.add_argument("--quiet", action="store_true", help="Only print summary, not full response bodies")
    args = parser.parse_args()
    base = args.base_url.rstrip("/")
    if "?" in base:
        base = base.split("?")[0]
    production_base = None
    if args.production is not None:
        production_base = (args.production or DEFAULT_PRODUCTION).rstrip("/")
        if "?" in production_base:
            production_base = production_base.split("?")[0]

    tomorrow = (date.today() + timedelta(days=1)).strftime("%Y%m%d")
    today = date.today().strftime("%Y%m%d")

    # (params, description, validator)
    Validator = Callable[[Dict[str, Any]], Tuple[bool, List[str]]]
    cases: List[Tuple[Dict[str, str], str, Validator]] = [
        # Schedule: resolved
        ({"type": "schedule", "resolved": "1"}, "GET schedule resolved=1", validate_schedule_resolved),
        ({"type": "schedule", "format": "resolved"}, "GET schedule format=resolved", validate_schedule_resolved),
        ({"type": "schedule", "resolved": "1", "date": tomorrow}, "GET schedule resolved=1 date=tomorrow", validate_schedule_resolved),
        ({"type": "schedule", "format": "resolved", "date": today}, "GET schedule format=resolved date=today", validate_schedule_resolved),
        # Schedule: raw (no resolve)
        ({"type": "schedule"}, "GET schedule (no resolve)", validate_schedule_raw),
        ({"type": "schedule", "date": tomorrow}, "GET schedule no resolve (date ignored for raw)", validate_schedule_raw),
        # Other types
        ({"type": "list"}, "GET type=list", lambda r: validate_list_or_files(r, "files")),
        ({"type": "list", "pattern": "*.json"}, "GET type=list pattern=*.json", lambda r: validate_list_or_files(r, "files")),
        ({"type": "price", "list": "true"}, "GET type=price list=true", lambda r: validate_list_or_files(r, "files")),
        ({"type": "price", "date": today}, "GET type=price date=today", validate_data_or_error),
        ({"type": "price", "date": tomorrow}, "GET type=price date=tomorrow", validate_data_or_error),
        ({"type": "zendure"}, "GET type=zendure", validate_data_or_error),
        ({"type": "zendure_p1"}, "GET type=zendure_p1", validate_data_or_error),
        ({"type": "automation_status"}, "GET type=automation_status", validate_data_or_error),
        ({"type": "file", "name": "charge_schedule.json"}, "GET type=file name=charge_schedule.json", validate_data_or_error),
        # Error cases
        ({}, "GET missing type", validate_error_response),
        ({"type": "invalid_type"}, "GET type=invalid_type", validate_error_response),
        ({"type": "price"}, "GET type=price missing date", validate_error_response),
        ({"type": "file"}, "GET type=file missing name", validate_error_response),
    ]

    print("=" * 60)
    print("main/data/api/data_api.php – GET tests")
    print("=" * 60)
    print(f"Local URL: {base}")
    if production_base:
        print(f"Production URL: {production_base}")
        print("(Comparing local vs production responses; ignoring: {})".format(", ".join(sorted(COMPARE_IGNORE_KEYS))))
    print(f"Timeout:  {args.timeout}s")
    print(f"Tomorrow: {tomorrow}  Today: {today}")
    print()

    results: List[Dict[str, Any]] = []
    all_deviations: List[Tuple[str, List[str]]] = []  # (description, deviations)

    for params, description, validator in cases:
        result_local = run_get(base, params, args.timeout)
        result_local["description"] = description
        passed, failures = validator(result_local)
        result_local["passed"] = passed
        result_local["failures"] = failures
        result_local["params"] = params
        results.append(result_local)

        if production_base:
            result_prod = run_get(production_base, params, args.timeout)
            deviations = compare_results(result_local, result_prod)
            if deviations:
                all_deviations.append((description, deviations))
            result_local["production_result"] = result_prod
            result_local["deviations"] = deviations

        status = "PASS" if passed else "FAIL"
        print(f"[{status}] {description}")
        print(f"  Local URL: {result_local['url']}")
        print(f"  Params: {params}")
        print(f"  HTTP (local): {result_local.get('status_code') or result_local.get('error', 'N/A')}")
        if production_base:
            prod = result_local.get("production_result", {})
            print(f"  HTTP (prod):  {prod.get('status_code') or prod.get('error', 'N/A')}")
            if result_local.get("deviations"):
                print(f"  Deviations: {len(result_local['deviations'])}")
                for d in result_local["deviations"]:
                    print(f"    - {d}")
        if result_local.get("error"):
            print(f"  Error: {result_local['error']}")
        for f in result_local.get("failures", []):
            print(f"  Validation: {f}")
        if not args.quiet:
            body_str = format_body(result_local.get("body"))
            print("  Response (local):")
            for line in body_str.splitlines():
                print("    " + line)
        print()

    # Schedule add -> list -> delete (mutating test)
    desc_schedule_mutation = "Schedule POST add / GET list / DELETE"
    passed_local, failures_local, steps_local = run_schedule_add_list_delete(base, args.timeout)
    result_mutation: Dict[str, Any] = {
        "description": desc_schedule_mutation,
        "passed": passed_local,
        "failures": failures_local,
        "params": {"type": "schedule", "key": SCHEDULE_TEST_KEY, "value": SCHEDULE_TEST_VALUE},
        "url": base,
        "steps": steps_local,
    }
    if production_base:
        passed_prod, failures_prod, steps_prod = run_schedule_add_list_delete(
            production_base, args.timeout
        )
        result_mutation["passed"] = passed_local and passed_prod
        result_mutation["failures"] = failures_local + (
            [f"production: {f}" for f in failures_prod] if failures_prod else []
        )
        result_mutation["production_passed"] = passed_prod
        result_mutation["production_failures"] = failures_prod
        result_mutation["production_steps"] = steps_prod
    results.append(result_mutation)

    status = "PASS" if result_mutation["passed"] else "FAIL"
    print(f"[{status}] {desc_schedule_mutation}")
    print(f"  Key: {SCHEDULE_TEST_KEY}  Value: {SCHEDULE_TEST_VALUE}")
    print(f"  Local: POST add -> GET (verify) -> DELETE -> GET (verify gone)")
    print(f"  HTTP (local): post={steps_local.get('post_add', {}).get('status_code')}, get={steps_local.get('get_after_add', {}).get('status_code')}, delete={steps_local.get('delete', {}).get('status_code')}, get2={steps_local.get('get_after_delete', {}).get('status_code')}")
    if production_base:
        psteps = result_mutation.get("production_steps") or {}
        print(f"  HTTP (prod):  post={psteps.get('post_add', {}).get('status_code')}, get={psteps.get('get_after_add', {}).get('status_code')}, delete={psteps.get('delete', {}).get('status_code')}, get2={psteps.get('get_after_delete', {}).get('status_code')}")
    for f in result_mutation.get("failures", []):
        print(f"  Validation: {f}")
    if not args.quiet and steps_local:
        for step_name, step_result in steps_local.items():
            body_str = format_body(step_result.get("body"), max_len=200)
            print(f"  {step_name} (local): {body_str}")
    print()

    passed_count = sum(1 for r in results if r["passed"])
    failed_count = len(results) - passed_count
    total_deviations = sum(len(d[1]) for d in all_deviations)

    print("=" * 60)
    print("SUMMARY")
    print("=" * 60)
    print(f"Total tests:  {len(results)}")
    print(f"Passed:       {passed_count}")
    print(f"Failed:       {failed_count}")
    if production_base:
        print(f"Deviations (local vs production): {total_deviations}")
        if all_deviations:
            print("\nDeviations by test:")
            for desc, devs in all_deviations:
                print(f"  {desc}:")
                for d in devs:
                    print(f"    - {d}")
    if failed_count:
        print("\nFailed tests:")
        for r in results:
            if not r["passed"]:
                print(f"  - {r['description']}: {r['failures']}")
    print("=" * 60)
    return 0 if failed_count == 0 and total_deviations == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
