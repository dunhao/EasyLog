#!/usr/bin/env bash
# EasyLog Query Service 启动脚本
# 共享 services/admin 的 Laravel 安装

set -euo pipefail

WORK_DIR="$(cd "$(dirname "$0")/../admin" && pwd)"
cd "$WORK_DIR"

PORT="${QUERY_PORT:-9515}"
HOST="${OCTANE_HOST:-0.0.0.0}"
WORKERS="${OCTANE_WORKERS:-4}"
MAX_REQUESTS="${OCTANE_MAX_REQUESTS:-10000}"

echo "Starting EasyLog Query Service on ${HOST}:${PORT} (${WORKERS} workers)"
exec php artisan octane:start \
    --server=swoole \
    --host="$HOST" \
    --port="$PORT" \
    --workers="$WORKERS" \
    --max-requests="$MAX_REQUESTS"
