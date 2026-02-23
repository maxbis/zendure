#!/usr/bin/env bash
# Fetch resolved schedule from data_api (type=schedule&resolved=1).
# Usage: ./fetch_schedule_api.sh [base_url]
# Default base URL: https://zendure.qool.ovh

set -e

BASE_URL="${1:-https://zendure.qool.ovh}"
URL="${BASE_URL}/main/data/api/data_api.php?type=schedule&resolved=1"

curl -sS --fail "$URL" > /dev/null 2>&1
if [ $? -ne 0 ]; then
    echo "Error fetching schedule API"
    exit 1
fi
echo "Schedule API fetched successfully"
exit 0