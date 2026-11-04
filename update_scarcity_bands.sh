#!/usr/bin/env bash

# Recalculate and publish the scarcity bands as part of a one-shot optimizer run.
python3 -m planner.shadow --once
