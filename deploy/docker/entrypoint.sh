#!/usr/bin/env bash
# EasyLog 应用容器统一入口
# 角色：admin / ingestion / query / writer / worker
# 通过 SERVICE_ROLE 环境变量切换
#
# 职责：
# 1. 首次启动：composer install + 生成 key + migrate（仅 admin 角色）
# 2. 按 SERVICE_ROLE 启动对应进程

set -euo pipefail

ROLE="${SERVICE_ROLE:-admin}"
WORK_DIR="/var/www/html"
cd "$WORK_DIR"

# 1. 依赖安装（admin 角色负责，vendor 通过共享卷供其它角色使用）
#    触发条件：vendor 缺失，或 composer.lock 比 vendor 安装清单新（拉取新依赖后无需重建镜像）
if [ "$ROLE" = "admin" ] && [ -f "composer.json" ]; then
    NEED_INSTALL=0
    if [ ! -d "vendor" ]; then
        NEED_INSTALL=1
    elif [ -f "composer.lock" ] && [ "composer.lock" -nt "vendor/composer/installed.json" ]; then
        NEED_INSTALL=1
    fi
    if [ "$NEED_INSTALL" = "1" ]; then
        echo "[entrypoint] installing composer dependencies..."
        if [ -n "${COMPOSER_AUTH:-}" ]; then
            export COMPOSER_AUTH
        fi
        composer install --no-interaction --optimize-autoloader --no-dev \
            || echo "[entrypoint] composer install failed (网络受限时请手工进入容器执行)"
    fi
fi

# 2. 应用密钥（仅 admin 生成；compose 已通过环境变量注入 APP_KEY 时跳过）
if [ "$ROLE" = "admin" ] && [ ! -f ".env" ]; then
    echo "[entrypoint] copying .env.example -> .env"
    cp .env.example .env 2>/dev/null || true
    if [ -z "${APP_KEY:-}" ]; then
        php artisan key:generate --force
    fi
fi

# 3. 数据库迁移（仅 admin 角色执行；其它角色依赖 admin 完成）
#    MySQL：DB_HOST 非空；lite 档：DB_CONNECTION=sqlite（无 DB_HOST）
if [ "$ROLE" = "admin" ] && { [ -n "${DB_HOST:-}" ] || [ "${DB_CONNECTION:-mysql}" = "sqlite" ]; }; then
    # SQLite 路径目录可能不存在（如 lite 档指向 storage/app/easylog/），先建
    if [ "${DB_CONNECTION:-mysql}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ "${DB_DATABASE}" != ":memory:" ]; then
        mkdir -p "$(dirname "${DB_DATABASE}")"
    fi
    echo "[entrypoint] running migrations..."
    php artisan migrate --force || echo "[entrypoint] migration failed, continuing..."
    php artisan db:seed --force --class=DatabaseSeeder || true
fi

# 4. 按 ROLE 启动对应进程
echo "[entrypoint] starting role: ${ROLE}"
case "$ROLE" in
    admin)
        # lite 档（无 worker 容器）可选：EASYLOG_ALERT_LOOPS=1 时由本容器附带拉起告警巡检
        if [ "${EASYLOG_ALERT_LOOPS:-0}" = "1" ]; then
            echo "[entrypoint] starting alert evaluator + notifier loops (admin role)"
            php artisan easylog:alert-evaluator --interval=60 > /dev/null 2>&1 &
            php artisan easylog:dispatch-alert-notifications --interval=30 > /dev/null 2>&1 &
        fi
        exec php artisan octane:start \
            --server=swoole --host=0.0.0.0 --port="${OCTANE_PORT:-9515}" \
            --workers="${OCTANE_WORKERS:-4}" --max-requests="${OCTANE_MAX_REQUESTS:-10000}"
        ;;
    ingestion)
        exec php artisan octane:start \
            --server=swoole --host=0.0.0.0 --port="${OCTANE_PORT:-9514}" \
            --workers="${OCTANE_WORKERS:-4}" --max-requests="${OCTANE_MAX_REQUESTS:-10000}"
        ;;
    query)
        exec php artisan octane:start \
            --server=swoole --host=0.0.0.0 --port="${OCTANE_PORT:-9515}" \
            --workers="${OCTANE_WORKERS:-4}" --max-requests="${OCTANE_MAX_REQUESTS:-10000}"
        ;;
    writer)
        exec php artisan easylog:writer
        ;;
    worker)
        # 告警评估与通知投递为轻量轮询进程，随 worker 容器一并启动（后台）；
        # 主进程仍是 MQ 消费者，容器退出由 restart 策略整体拉起
        php artisan easylog:alert-evaluator --interval=60 > /dev/null 2>&1 &
        php artisan easylog:dispatch-alert-notifications --interval=30 > /dev/null 2>&1 &
        exec php artisan easylog:consume
        ;;
    *)
        echo "[entrypoint] unknown SERVICE_ROLE: $ROLE"
        exit 1
        ;;
esac
