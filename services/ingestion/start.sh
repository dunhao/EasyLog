#!/usr/bin/env bash
# EasyLog Ingestion Gateway 启动脚本
# 共享 services/admin 的 Laravel 安装

set -euo pipefail

WORK_DIR="$(cd "$(dirname "$0")/../admin" && pwd)"
cd "$WORK_DIR"

PORT="${INGESTION_PORT:-9514}"
HOST="${OCTANE_HOST:-0.0.0.0}"
WORKERS="${OCTANE_WORKERS:-4}"
MAX_REQUESTS="${OCTANE_MAX_REQUESTS:-10000}"

echo "Starting EasyLog Ingestion Gateway on ${HOST}:${PORT} (${WORKERS} workers)"
exec php artisan octane:start \
    --server=swoole \
    --host="$HOST" \
    --port="$PORT" \
    --workers="$WORKERS" \
    --max-requests="$MAX_REQUESTS"
