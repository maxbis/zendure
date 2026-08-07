#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

SERVER="max@vmi3072769.contaboserver.net"
SSH_PORT="1611"
REMOTE_DIR="/var/www/qool/zendure"
BRANCH="$(git branch --show-current)"
COMMIT_MESSAGE="Deploy $(date '+%Y-%m-%d %H:%M:%S')"

if [[ -z "${BRANCH}" ]]; then
  echo "Cannot deploy from a detached HEAD." >&2
  exit 1
fi

if [[ ! "${BRANCH}" =~ ^[A-Za-z0-9._/-]+$ ]]; then
  echo "Cannot deploy branch with an unsupported name: ${BRANCH}" >&2
  exit 1
fi

git add --all

if git diff --cached --quiet; then
  echo "No local changes to commit. Continuing with the current commit."
else
  git commit -m "${COMMIT_MESSAGE}"
fi

git push origin "${BRANCH}"

ssh -p "${SSH_PORT}" "${SERVER}" \
  "cd '${REMOTE_DIR}' && test \"\$(git branch --show-current)\" = '${BRANCH}' && git pull --ff-only origin '${BRANCH}'"

echo "Deployment of ${BRANCH} completed successfully."
