#!/usr/bin/env python3
"""
Visualize Open-Meteo hourly shortwave radiation data as a standalone HTML chart.

Usage:
  python tools/visualize_shortwave_radiation.py input.json
  python tools/visualize_shortwave_radiation.py input.json --output radiation.html
  cat input.json | python tools/visualize_shortwave_radiation.py --stdin

The script expects an Open-Meteo response containing:
  hourly.time
  hourly.shortwave_radiation
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from datetime import datetime
from html import escape
from pathlib import Path

CHART_WIDTH = 1200
CHART_HEIGHT = 420
PADDING_LEFT = 70
PADDING_RIGHT = 20
PADDING_TOP = 20
PADDING_BOTTOM = 60


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Render Open-Meteo shortwave radiation data to a standalone HTML chart."
    )
    parser.add_argument("input", nargs="?", help="Path to the Open-Meteo JSON file.")
    parser.add_argument(
        "--stdin",
        action="store_true",
        help="Read the Open-Meteo JSON payload from stdin.",
    )
    parser.add_argument(
        "--output",
        help="Output HTML path. Defaults to <input>.html or shortwave_radiation_chart.html.",
    )
    return parser.parse_args()


def load_payload(args: argparse.Namespace) -> dict:
    if args.stdin:
        return json.load(sys.stdin)
    if not args.input:
        raise SystemExit("Provide an input JSON file or use --stdin.")
    with open(args.input, "r", encoding="utf-8") as handle:
        return json.load(handle)


def validate_payload(payload: dict) -> tuple[list[str], list[float], str]:
    hourly = payload.get("hourly")
    units = payload.get("hourly_units", {})
    if not isinstance(hourly, dict):
        raise SystemExit("Invalid payload: missing 'hourly' object.")

    times = hourly.get("time")
    values = hourly.get("shortwave_radiation")
    if not isinstance(times, list) or not isinstance(values, list):
        raise SystemExit("Invalid payload: missing 'hourly.time' or 'hourly.shortwave_radiation'.")
    if len(times) != len(values):
        raise SystemExit("Invalid payload: time/value array length mismatch.")
    if not times:
        raise SystemExit("Invalid payload: no hourly data found.")

    try:
        numeric_values = [float(value) for value in values]
    except (TypeError, ValueError) as exc:
        raise SystemExit(f"Invalid payload: non-numeric radiation value found: {exc}") from exc

    unit = str(units.get("shortwave_radiation", "W/m²"))
    return times, numeric_values, unit


def build_points(values: list[float]) -> str:
    plot_width = CHART_WIDTH - PADDING_LEFT - PADDING_RIGHT
    plot_height = CHART_HEIGHT - PADDING_TOP - PADDING_BOTTOM
    max_value = max(values) if values else 0.0
    scale_max = max(50.0, math.ceil(max_value / 50.0) * 50.0)

    points: list[str] = []
    for index, value in enumerate(values):
        x = PADDING_LEFT if len(values) == 1 else PADDING_LEFT + (index / (len(values) - 1)) * plot_width
        y = PADDING_TOP + plot_height - ((value / scale_max) * plot_height)
        points.append(f"{x:.2f},{y:.2f}")
    return " ".join(points)


def build_y_axis(values: list[float], unit: str) -> str:
    plot_height = CHART_HEIGHT - PADDING_TOP - PADDING_BOTTOM
    plot_width = CHART_WIDTH - PADDING_LEFT - PADDING_RIGHT
    max_value = max(values) if values else 0.0
    scale_max = max(50.0, math.ceil(max_value / 50.0) * 50.0)

    lines: list[str] = []
    for step in range(6):
        tick_value = (scale_max / 5.0) * step
        y = PADDING_TOP + plot_height - (step / 5.0) * plot_height
        lines.append(
            f'<line x1="{PADDING_LEFT}" y1="{y:.2f}" x2="{PADDING_LEFT + plot_width}" y2="{y:.2f}" '
            'stroke="#d7e0ea" stroke-width="1" />'
        )
        lines.append(
            f'<text x="{PADDING_LEFT - 12}" y="{y + 5:.2f}" text-anchor="end" '
            'font-size="12" fill="#4b5b6b">'
            f"{tick_value:.0f}</text>"
        )
    lines.append(
        f'<text x="18" y="{PADDING_TOP + 10}" font-size="12" fill="#4b5b6b">{escape(unit)}</text>'
    )
    return "\n".join(lines)


def build_x_axis(times: list[str]) -> str:
    plot_width = CHART_WIDTH - PADDING_LEFT - PADDING_RIGHT
    labels: list[str] = []
    step = max(1, len(times) // 8)
    for index in range(0, len(times), step):
        x = PADDING_LEFT if len(times) == 1 else PADDING_LEFT + (index / (len(times) - 1)) * plot_width
        label = format_label(times[index])
        labels.append(
            f'<line x1="{x:.2f}" y1="{CHART_HEIGHT - PADDING_BOTTOM}" x2="{x:.2f}" y2="{CHART_HEIGHT - PADDING_BOTTOM + 6}" '
            'stroke="#5e6b78" stroke-width="1" />'
        )
        labels.append(
            f'<text x="{x:.2f}" y="{CHART_HEIGHT - PADDING_BOTTOM + 24}" text-anchor="middle" '
            'font-size="12" fill="#4b5b6b">'
            f"{escape(label)}</text>"
        )
    return "\n".join(labels)


def format_label(timestamp: str) -> str:
    dt = datetime.fromisoformat(timestamp)
    return dt.strftime("%d %b %H:%M")


def build_summary(payload: dict, times: list[str], values: list[float], unit: str) -> str:
    peak_index = max(range(len(values)), key=values.__getitem__)
    non_zero_hours = sum(1 for value in values if value > 0)
    timezone_name = payload.get("timezone", "unknown")
    avg_value = sum(values) / len(values)
    return f"""
<div class="stats">
  <div class="stat"><span>Hours</span><strong>{len(values)}</strong></div>
  <div class="stat"><span>Peak</span><strong>{values[peak_index]:.0f} {escape(unit)}</strong></div>
  <div class="stat"><span>Peak time</span><strong>{escape(times[peak_index])}</strong></div>
  <div class="stat"><span>Average</span><strong>{avg_value:.1f} {escape(unit)}</strong></div>
  <div class="stat"><span>Sunlight hours</span><strong>{non_zero_hours}</strong></div>
  <div class="stat"><span>Timezone</span><strong>{escape(str(timezone_name))}</strong></div>
</div>
"""


def render_html(payload: dict, times: list[str], values: list[float], unit: str) -> str:
    polyline_points = build_points(values)
    y_axis = build_y_axis(values, unit)
    x_axis = build_x_axis(times)
    summary = build_summary(payload, times, values, unit)
    title = "Open-Meteo Shortwave Radiation"
    start_label = format_label(times[0])
    end_label = format_label(times[-1])

    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{title}</title>
  <style>
    :root {{
      --bg: #f4f8fb;
      --panel: #ffffff;
      --line: #d08b00;
      --fill: rgba(208, 139, 0, 0.18);
      --axis: #5e6b78;
      --text: #1e2a35;
      --muted: #607080;
      --shadow: 0 20px 60px rgba(31, 45, 61, 0.12);
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      padding: 32px;
      background:
        radial-gradient(circle at top left, rgba(255, 197, 61, 0.16), transparent 32%),
        linear-gradient(180deg, #eef5fb 0%, #f9fbfd 100%);
      color: var(--text);
      font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }}
    .wrap {{ max-width: 1280px; margin: 0 auto; }}
    .panel {{
      background: var(--panel);
      border-radius: 20px;
      padding: 24px;
      box-shadow: var(--shadow);
    }}
    h1 {{ margin: 0 0 4px; font-size: 28px; }}
    p {{ margin: 0; color: var(--muted); }}
    .stats {{
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 12px;
      margin: 20px 0 24px;
    }}
    .stat {{
      padding: 14px 16px;
      border-radius: 14px;
      background: #f7fafc;
      border: 1px solid #e5edf4;
    }}
    .stat span {{ display: block; color: var(--muted); font-size: 12px; margin-bottom: 4px; }}
    .stat strong {{ font-size: 18px; }}
    svg {{ width: 100%; height: auto; display: block; }}
    .plot-area {{ fill: var(--fill); }}
    .plot-line {{ fill: none; stroke: var(--line); stroke-width: 3; stroke-linejoin: round; stroke-linecap: round; }}
    .axis-line {{ stroke: var(--axis); stroke-width: 1.5; }}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="panel">
      <h1>{title}</h1>
      <p>{escape(start_label)} to {escape(end_label)}</p>
      {summary}
      <svg viewBox="0 0 {CHART_WIDTH} {CHART_HEIGHT}" role="img" aria-label="Shortwave radiation chart">
        {y_axis}
        <line class="axis-line" x1="{PADDING_LEFT}" y1="{CHART_HEIGHT - PADDING_BOTTOM}" x2="{CHART_WIDTH - PADDING_RIGHT}" y2="{CHART_HEIGHT - PADDING_BOTTOM}" />
        <line class="axis-line" x1="{PADDING_LEFT}" y1="{PADDING_TOP}" x2="{PADDING_LEFT}" y2="{CHART_HEIGHT - PADDING_BOTTOM}" />
        <polygon class="plot-area" points="{PADDING_LEFT},{CHART_HEIGHT - PADDING_BOTTOM} {polyline_points} {CHART_WIDTH - PADDING_RIGHT},{CHART_HEIGHT - PADDING_BOTTOM}" />
        <polyline class="plot-line" points="{polyline_points}" />
        {x_axis}
      </svg>
    </div>
  </div>
</body>
</html>
"""


def resolve_output_path(args: argparse.Namespace) -> Path:
    if args.output:
        return Path(args.output)
    if args.input:
        return Path(args.input).with_suffix(".html")
    return Path("shortwave_radiation_chart.html")


def main() -> int:
    args = parse_args()
    payload = load_payload(args)
    times, values, unit = validate_payload(payload)
    output_path = resolve_output_path(args)
    html = render_html(payload, times, values, unit)
    output_path.write_text(html, encoding="utf-8")
    print(f"Wrote chart to {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
