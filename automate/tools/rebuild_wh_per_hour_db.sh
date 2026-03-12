#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUTOMATE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DATA_DIR="${AUTOMATE_DIR}/data"

SOURCE_DB="${1:-${DATA_DIR}/status_updates.db}"
DEST_DB="${2:-${DATA_DIR}/status_updates_compressed.db}"
TMP_DB="${DEST_DB}.tmp"
LOG_PREFIX="[rebuild_wh_per_hour_db]"

echo "${LOG_PREFIX} source=${SOURCE_DB}"
echo "${LOG_PREFIX} dest=${DEST_DB}"

if [[ ! -f "${SOURCE_DB}" ]]; then
  echo "${LOG_PREFIX} source DB not found: ${SOURCE_DB}" >&2
  exit 1
fi

mkdir -p "$(dirname "${DEST_DB}")"
rm -f "${TMP_DB}"

python3 "${SCRIPT_DIR}/compress_status_updates_db.py" "${SOURCE_DB}" "${TMP_DB}"

sqlite3 "${TMP_DB}" ".indexes status_updates" >/dev/null

mv "${TMP_DB}" "${DEST_DB}"

echo "${LOG_PREFIX} rebuilt ${DEST_DB}"
