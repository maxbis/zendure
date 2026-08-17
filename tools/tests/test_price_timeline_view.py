#!/usr/bin/env python
"""Structural checks for the Detail and Overview price timeline views."""

from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
PRICE_PLAN_PARTIAL = REPO_ROOT / "app" / "partials" / "price-plan.php"
PRICE_PLAN_JS = REPO_ROOT / "app" / "assets" / "js" / "price-plan.js"
APP_CSS = REPO_ROOT / "app" / "assets" / "css" / "app.css"
ICON_SPRITE = REPO_ROOT / "themes" / "graphite-signal-dark" / "assets" / "icons" / "sprite.svg"


def test_price_timeline_exposes_an_accessible_header_view_toggle():
    source = PRICE_PLAN_PARTIAL.read_text(encoding="utf-8")

    header = source[source.index('<header class="app-section-heading">'):source.index("</header>")]
    assert 'data-role="price-view-toggle"' in header
    assert 'aria-label="Show 48-hour overview"' in header
    assert 'aria-pressed="false"' in header
    assert 'data-role="price-view-icon"' in header
    assert 'data-role="price-view-status" aria-live="polite"' in source


def test_overview_preference_and_read_only_state_are_wired():
    source = PRICE_PLAN_JS.read_text(encoding="utf-8")

    assert 'const TIMELINE_VIEW_STORAGE_KEY = "zendure.priceTimelineView";' in source
    assert "component.dataset.timelineView = nextView;" in source
    assert 'button.disabled = overview;' in source
    assert 'if (isTimelineOverview()) return;' in source
    assert 'window.localStorage.setItem(TIMELINE_VIEW_STORAGE_KEY, nextView);' in source
    assert "timelineCenterSnapshot()" in source
    assert "restoreTimelineCenter(centerKey)" in source
    assert '"Show detailed interactive timeline"' in source
    assert '"Show 48-hour overview"' in source
    assert 'chart-bars-${overviewActive ? "overview" : "detail"}' in source


def test_overview_uses_half_width_columns_and_compact_header_control():
    source = APP_CSS.read_text(encoding="utf-8")

    overview_selector = '.app-price-plan[data-timeline-view="overview"] .app-price-timeline'
    assert overview_selector in source
    assert "grid-template-columns: repeat(48, minmax(18px, 1fr));" in source
    assert "min-width: 864px;" in source
    assert ".app-price-view-toggle" in source
    assert "min-height: 34px;" in source
    assert 'content: attr(data-compact-time);' in source
    assert "pointer-events: none;" in source


def test_mobile_overview_is_denser_and_uses_distinct_bar_chart_icons():
    css_source = APP_CSS.read_text(encoding="utf-8")
    icon_source = ICON_SPRITE.read_text(encoding="utf-8")

    assert "grid-template-columns: repeat(48, minmax(12px, 1fr));" in css_source
    assert "min-width: 576px;" in css_source
    assert '<symbol id="chart-bars-overview"' in icon_source
    assert '<symbol id="chart-bars-detail"' in icon_source


def test_overview_hides_solar_time_badges_without_hiding_markers():
    source = APP_CSS.read_text(encoding="utf-8")

    assert '.app-price-plan[data-timeline-view="overview"] .app-price-solar-marker__badge' in source
    assert ".app-price-solar-marker {" in source
    assert "border-inline-start: 1px dashed" in source


def test_timeline_uses_compact_day_and_numeric_date_format():
    source = PRICE_PLAN_JS.read_text(encoding="utf-8")

    assert 'const weekdays = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];' in source
    assert 'return `${weekdays[date.getDay()]} ${pad(date.getDate())}-${pad(date.getMonth() + 1)}`;' in source
    assert "dayHeadingLabel.textContent = formatTimelineDate(day.date);" in source
    assert 'dayHeading.setAttribute("aria-label", `${day.label}, ${formatDate(day.date)}`);' in source


def test_overview_bar_row_expands_without_changing_timeline_height():
    source = APP_CSS.read_text(encoding="utf-8")

    assert "--app-price-bar-row: minmax(0, 1fr);" in source
    assert "min-height: 220px;" in source


def test_mobile_header_reserves_full_width_for_both_icon_buttons():
    source = APP_CSS.read_text(encoding="utf-8")

    assert "grid-template-columns: minmax(0, 1fr) auto 44px 44px;" in source
    assert "gap: 4px 8px;" in source
