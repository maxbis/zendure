#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPLICATION_CLIENT_DIR="${REPO_ROOT}/db-replication/client"
REPLICATION_ENV="${REPLICATION_CLIENT_DIR}/.env"
REPORTING_MIGRATION="${REPO_ROOT}/daily_report/sql/add_battery_flow_pnl_columns.sql"

EXPECTED_LOCAL_ROOT="${ZENDURE_SYNC_LOCAL_ROOT:-/Users/maxbisschop/dev/www/zendure}"
EXPECTED_LOCAL_HOST="${ZENDURE_SYNC_LOCAL_HOST:-maxs-macbook-air-2}"

QOOL_SSH_HOST="${ZENDURE_SYNC_QOOL_HOST:-max@vmi3072769.contaboserver.net}"
QOOL_SSH_PORT="${ZENDURE_SYNC_QOOL_PORT:-1611}"
QOOL_REPO_ROOT="${ZENDURE_SYNC_QOOL_ROOT:-/var/www/qool/zendure}"

DRY_RUN=false

usage() {
    cat <<'EOF'
Usage: tools/sync_from_qool.sh [--dry-run]

Synchronize the local development checkout in two stages:
  1. Replicate status_updates from the Pi5 until the source reports no more rows.
  2. Ensure the localhost reporting schema is current.
  3. Copy runtime JSON, JSONL, and log files from qool to this checkout.

Options:
  --dry-run  Validate the local-only guards and show what would run without
             changing the local database or files.
  -h, --help Show this help text.

Optional environment overrides:
  ZENDURE_SYNC_LOCAL_ROOT   Expected absolute local checkout path.
  ZENDURE_SYNC_LOCAL_HOST   Expected local hostname without a domain suffix.
  ZENDURE_SYNC_QOOL_HOST    SSH destination (default from deploy.sh).
  ZENDURE_SYNC_QOOL_PORT    SSH port (default: 1611).
  ZENDURE_SYNC_QOOL_ROOT    Remote repository root.
EOF
}

log() {
    printf '[sync] %s\n' "$*"
}

fail() {
    printf '[sync] ERROR: %s\n' "$*" >&2
    exit 1
}

read_env_value() {
    local key="$1"
    local value

    value="$(sed -nE "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*(.*)[[:space:]]*$/\\1/p" "${REPLICATION_ENV}" | tail -n 1)"
    value="${value%$'\r'}"

    if [[ "${value}" == \"*\" && "${value}" == *\" ]]; then
        value="${value:1:${#value}-2}"
    elif [[ "${value}" == \'*\' && "${value}" == *\' ]]; then
        value="${value:1:${#value}-2}"
    fi

    printf '%s' "${value}"
}

assert_local_destination() {
    local current_host
    local mariadb_host

    [[ "${REPO_ROOT}" == "${EXPECTED_LOCAL_ROOT}" ]] || fail \
        "Refusing to run outside ${EXPECTED_LOCAL_ROOT}; current checkout is ${REPO_ROOT}."

    current_host="$(hostname)"
    case "${current_host}" in
        "${EXPECTED_LOCAL_HOST}"|"${EXPECTED_LOCAL_HOST}."*) ;;
        *) fail "Refusing to run on host '${current_host}'; expected '${EXPECTED_LOCAL_HOST}'." ;;
    esac

    [[ -f "${REPLICATION_ENV}" ]] || fail \
        "Replication configuration is missing: ${REPLICATION_ENV}"

    mariadb_host="$(read_env_value MARIADB_HOST)"
    case "${mariadb_host}" in
        127.0.0.1|localhost|::1) ;;
        '') fail "MARIADB_HOST is missing from ${REPLICATION_ENV}." ;;
        *) fail "Refusing to replicate to non-loopback MARIADB_HOST '${mariadb_host}'." ;;
    esac

    log "Local-only guard passed: host=${current_host}, database=${mariadb_host}."
}

replicate_until_caught_up() {
    local batch=0
    local total_inserted=0
    local inserted
    local has_more
    local output_file
    local pipeline_status

    output_file="$(mktemp -t zendure-replication.XXXXXX)"
    trap 'rm -f "${output_file:-}"' RETURN

    log "Replicating Pi5 status_updates into localhost MariaDB."

    while true; do
        batch=$((batch + 1))
        : > "${output_file}"
        log "Replication batch ${batch} started."

        set +e
        (
            cd "${REPLICATION_CLIENT_DIR}"
            python main.py
        ) 2>&1 | tee "${output_file}"
        pipeline_status="${PIPESTATUS[0]}"
        set -e

        [[ "${pipeline_status}" -eq 0 ]] || fail \
            "Replication batch ${batch} failed with exit code ${pipeline_status}."

        inserted="$(sed -nE 's/.*Replication completed inserted=([0-9]+).*/\1/p' "${output_file}" | tail -n 1)"
        has_more="$(sed -nE 's/.*Received table=status_updates count=[0-9]+ next_cursor=[0-9]+ has_more=(True|False).*/\1/p' "${output_file}" | tail -n 1)"

        [[ "${inserted}" =~ ^[0-9]+$ ]] || fail \
            "Could not read the inserted-row count from replication batch ${batch}."
        [[ "${has_more}" == "True" || "${has_more}" == "False" ]] || fail \
            "Could not read the source completion status from replication batch ${batch}."

        total_inserted=$((total_inserted + inserted))
        log "Replication batch ${batch} finished: inserted=${inserted}, total=${total_inserted}, has_more=${has_more}."

        if [[ "${has_more}" == "False" ]]; then
            log "Pi5 replication is caught up after ${batch} batch(es); inserted ${total_inserted} row(s)."
            break
        fi
    done

    rm -f "${output_file}"
    trap - RETURN
}

apply_local_reporting_schema() {
    [[ -f "${REPORTING_MIGRATION}" ]] || fail \
        "Reporting migration is missing: ${REPORTING_MIGRATION}"

    log "Ensuring the localhost reporting schema is current."
    php -r '
        require $argv[1];
        daily_report_bootstrap_env();
        $host = getenv("MARIADB_HOST") ?: "127.0.0.1";
        if (!in_array($host, ["127.0.0.1", "localhost", "::1"], true)) {
            fwrite(STDERR, "Refusing to migrate a non-loopback MariaDB host.\n");
            exit(2);
        }
        $port = (int)(getenv("MARIADB_PORT") ?: "3306");
        $database = getenv("MARIADB_DATABASE") ?: "sqlite_replication";
        $user = getenv("MARIADB_USER") ?: "root";
        $password = getenv("MARIADB_PASSWORD") ?: "";
        $sql = file_get_contents($argv[2]);
        if ($sql === false) {
            fwrite(STDERR, "Could not read reporting migration.\n");
            exit(3);
        }
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $host, $port, $database),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec($sql);
        echo "Reporting schema migration completed.\n";
    ' "${REPO_ROOT}/daily_report/load_env.php" "${REPORTING_MIGRATION}"
}

sync_remote_directory() {
    local relative_dir="$1"
    local remote_path="${QOOL_REPO_ROOT}/${relative_dir}"
    local local_path="${REPO_ROOT}/${relative_dir}"

    if ! ssh -p "${QOOL_SSH_PORT}" "${QOOL_SSH_HOST}" "test -d '${remote_path}'"; then
        log "Skipping missing remote directory: ${relative_dir}"
        return
    fi

    mkdir -p "${local_path}"
    log "Copying ${relative_dir}"
    rsync \
        --archive \
        --compress \
        --human-readable \
        --itemize-changes \
        --progress \
        --prune-empty-dirs \
        --include='*/' \
        --include='*.json' \
        --include='*.jsonl' \
        --include='*.log' \
        --exclude='*' \
        -e "ssh -p ${QOOL_SSH_PORT}" \
        "${QOOL_SSH_HOST}:${remote_path}/" \
        "${local_path}/"
}

sync_runtime_files() {
    local relative_dir
    local runtime_dirs=(
        "main/data"
        "planner/data"
        "daily_report/data"
        "data"
        "run_schedule/data"
        "automate/log"
        "logs"
    )

    log "Copying runtime JSON and log files from qool."
    for relative_dir in "${runtime_dirs[@]}"; do
        sync_remote_directory "${relative_dir}"
    done
    log "Runtime file synchronization completed."
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            usage >&2
            fail "Unknown argument: $1"
            ;;
    esac
    shift
done

assert_local_destination

command -v python >/dev/null 2>&1 || fail "python is required."
command -v php >/dev/null 2>&1 || fail "php is required."
command -v ssh >/dev/null 2>&1 || fail "ssh is required."
command -v rsync >/dev/null 2>&1 || fail "rsync is required."

if [[ "${DRY_RUN}" == "true" ]]; then
    log "Dry run: would drain Pi5 status_updates replication first."
    log "Dry run: would then apply the idempotent localhost reporting migration."
    log "Dry run: would then copy JSON, JSONL, and log files from ${QOOL_SSH_HOST}:${QOOL_REPO_ROOT}."
    exit 0
fi

replicate_until_caught_up
apply_local_reporting_schema
sync_runtime_files
log "Synchronization from Pi5 and qool completed successfully."
