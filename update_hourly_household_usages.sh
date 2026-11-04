#!/usr/bin/env bash

# Rebuild the generated hourly household-usage profile from historical readings.
python3 -m planner.update_household_usage_profile
