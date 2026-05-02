from __future__ import annotations

from pathlib import Path

from planner.config import PlannerSettings, PriceConversionConfig


def build_test_settings(tmp_path: Path) -> PlannerSettings:
    return PlannerSettings(
        repo_root=tmp_path,
        data_dir=tmp_path / "data",
        load_forecast_path=tmp_path / "data" / "load_forecast.json",
        default_load_forecast_template_path=tmp_path / "data" / "load_forecast_default.json",
        main_config_path=tmp_path / "main-config.json",
        timezone="Europe/Amsterdam",
        service_host="127.0.0.1",
        service_port=8765,
        http_timeout_seconds=2,
        price_api_url="http://example.test/prices",
        automation_all_api_url="http://example.test/api/all",
        shortwave_api_url="http://example.test/shortwave",
        latitude=52.3,
        longitude=4.9,
        base_wh=5760.0,
        min_charge_level=15,
        max_charge_level=96,
        max_charge_power_w=1200,
        max_discharge_power_w=1600,
        arbitrage_min_spread_eur_per_kwh=0.12,
        round_trip_efficiency=0.90,
        cheap_hour_tolerance_eur_per_kwh=0.01,
        expensive_hour_tolerance_eur_per_kwh=0.01,
        netzero_market_price_threshold_eur_per_kwh=0.18,
        negative_export_avoidance_enabled=True,
        price_release_hour_local=14,
        shortwave_radiation_reference_w_m2=1000.0,
        pv_system_capacity_w=5000.0,
        pv_derate_factor=0.85,
        pv_output_clip_w=5000.0,
        min_action_power_w=50,
        price_conversion=PriceConversionConfig(),
    )
