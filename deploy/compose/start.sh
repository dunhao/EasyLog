#!/usr/bin/env bash
#
# EasyLog 启动脚本（三档按需启动）
# ------------------------------------------------------------------
# 用法：
#   ./start.sh              # lite 档（默认）：app + frontend 两容器，SQLite 全自包含，零外部依赖
#   ./start.sh core         # core 档：redis/es/zk/kafka + 5 应用角色 + frontend（需宿主机 MySQL）
#   ./start.sh full         # full 档：core + rabbitmq + worker（外部 MQ 消费 + 命令行告警巡检）
#   ./start.sh --with-mq    # 同 full（兼容旧参数）
#   ./start.sh --all        # 同 full（兼容旧参数）
#   ./start.sh reset        # 先停全部容器释放内存，再起 lite 档（OOM 恢复用）
#   ./start.sh stop         # 停全部容器（不删卷）
#   ./start.sh status       # 仅打印各服务状态与访问地址
#
# 说明：
#   - 档位即 COMPOSE_PROFILES：lite / core / core,full，脚本内自动设置并覆盖 .env。
#   - lite 档零外部依赖：元数据与日志都在 SQLite（storage 卷持久化），内存 ~200MB。
#   - core/full 档依赖宿主机 MySQL（.env 的 EASYLOG_DB_*）与 vm.max_map_count>=262144。
#   - 访问地址里的 HOST IP 可用 EASYLOG_HOST_IP 覆盖，否则自动探测。
#
set -euo pipefail

# 切到脚本所在目录，保证无论从哪调用都能找到 docker-compose.yml
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ---- 参数解析 ----
MODE="lite"
case "${1:-}" in
  ""|lite)      MODE="lite" ;;
  core)         MODE="core" ;;
  full)         MODE="full" ;;
  --with-mq)    MODE="full" ;;
  --all)        MODE="all" ;;
  reset)        MODE="reset" ;;
  stop)         MODE="stop" ;;
  status)       MODE="status" ;;
  -h|--help|help)
    grep '^#' "$0" | sed 's/^#\s\?//' | sed -n '3,16p'
    exit 0 ;;
  *)
    echo "未知参数: $1" >&2
    echo "用法: $0 [lite|core|full|--with-mq|--all|reset|stop|status]" >&2
    exit 1 ;;
esac

# ES 需要 vm.max_map_count >= 262144，低于此值 ES 会启动失败（仅 core/full 档）
check_max_map_count() {
  local need=262144
  local cur
  cur=$(sysctl -n vm.max_map_count 2>/dev/null || echo 0)
  if [ "$cur" -lt "$need" ] 2>/dev/null; then
    echo "⚠️  vm.max_map_count=$cur 低于 ES 要求的 $need，ES 可能启动失败。"
    echo "    建议执行（需 sudo）： sudo sysctl -w vm.max_map_count=$need"
    echo "    永久生效： echo 'vm.max_map_count=$need' | sudo tee -a /etc/sysctl.conf"
  fi
}

detect_host_ip() {
  if [ -n "${EASYLOG_HOST_IP:-}" ]; then
    echo "$EASYLOG_HOST_IP"; return
  fi
  local ip
  ip=$(ipconfig getifaddr en0 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}' || true)
  if [ -z "$ip" ]; then
    ip=$(ip -4 addr show 2>/dev/null | awk '/inet / && $2 !~ /^127\./ {print $2; exit}' | cut -d/ -f1)
  fi
  echo "${ip:-127.0.0.1}"
}

HOST_IP="$(detect_host_ip)"

# ---- 档位 → COMPOSE_PROFILES / frontend 反代上游 ----
profiles_for_mode() {
  case "$1" in
    lite) echo "lite" ;;
    core) echo "core" ;;
    full|all) echo "core,full" ;;
    *) echo "" ;;
  esac
}
api_upstream_for_mode() {
  case "$1" in
    lite) echo "app:9515" ;;
    *)    echo "query:9515" ;;
  esac
}

# ---- 准备 .env（密钥外置：APP_KEY / DB_PASSWORD 不写死在 compose 里）----
prepare_env_file() {
  if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
    echo ">>> 已从 .env.example 生成 .env"
  elif [ ! -f .env ]; then
    touch .env
  fi
  # APP_KEY 缺失时自动生成（Laravel 格式：base64(32 字节)）
  if ! grep -q '^EASYLOG_APP_KEY=base64:' .env 2>/dev/null; then
    local key
    key="EASYLOG_APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')"
    if grep -q '^EASYLOG_APP_KEY=' .env 2>/dev/null; then
      sed -i.bak "s|^EASYLOG_APP_KEY=.*|$key|" .env && rm -f .env.bak
    else
      printf '%s\n' "$key" >> .env
    fi
    echo ">>> 已生成随机 EASYLOG_APP_KEY 写入 .env"
  fi
}

# core/full 档额外校验：宿主机 MySQL 密码必填（compose 文件级插值不做强校验，此处兜底）
require_db_password() {
  if ! grep -Eq '^EASYLOG_DB_PASSWORD=.+' .env 2>/dev/null; then
    echo "❌ core/full 档需要宿主机 MySQL：请在 deploy/compose/.env 中设置 EASYLOG_DB_PASSWORD。" >&2
    echo "   （若只想轻量体验，直接 ./start.sh 起 lite 档，无需 MySQL）" >&2
    exit 1
  fi
}

case "$MODE" in
  stop|status) prepare_env_file ;;
  reset|lite)  prepare_env_file ;;
  core|full|all)
    prepare_env_file
    require_db_password
    check_max_map_count
    ;;
esac

# 当前档位（reset 起默认 lite 档）
if [ "$MODE" = "reset" ]; then
  ACTIVE_MODE="lite"
else
  ACTIVE_MODE="$MODE"
fi

export COMPOSE_PROFILES="$(profiles_for_mode "$ACTIVE_MODE")"
# shell 环境变量优先级高于 .env，覆盖 frontend 反代上游
export API_UPSTREAM="$(api_upstream_for_mode "$ACTIVE_MODE")"

echo ">>> 档位: $ACTIVE_MODE (COMPOSE_PROFILES=$COMPOSE_PROFILES, API_UPSTREAM=$API_UPSTREAM)"

case "$MODE" in
  stop)
    echo ">>> 停止全部容器（保留数据卷）..."
    docker compose down || true
    exit 0
    ;;
  status)
    docker compose ps
    echo
    echo "访问地址（HOST=$HOST_IP，档位见上）:"
    echo "  控制台:      http://$HOST_IP:9516/"
    echo "  管理/检索:   http://$HOST_IP:9515/up  (lite=admin单容器 / core=admin，宿主映射)"
    echo "  接入心跳:    http://$HOST_IP:9514/api/v1/ingest/heartbeat  (core 档独立网关；lite 档用 9515)"
    echo "  检索入口:    http://$HOST_IP:9517/  (仅 core/full 档)"
    exit 0
    ;;
  reset)
    echo ">>> [reset] 先停全部容器释放内存..."
    docker compose down || true
    ;;
esac

echo ">>> 启动 $ACTIVE_MODE 档服务..."
docker compose up -d

# ---- 等待核心依赖就绪 ----
wait_for() {
  local name="$1"; local cmd="$2"; local timeout="${3:-180}"; local waited=0
  echo -n ">>> 等待 $name 就绪"
  until eval "$cmd" >/dev/null 2>&1; do
    echo -n "."
    sleep 5; waited=$((waited+5))
    if [ "$waited" -ge "$timeout" ]; then
      echo " 超时(${timeout}s)！可用 'docker compose logs $name' 排查。"
      return 1
    fi
  done
  echo " 就绪(${waited}s)"
}

if [ "$ACTIVE_MODE" = "lite" ]; then
  wait_for "app(9515)"    "curl -fs -o /dev/null http://localhost:9515/api/v1/health" 180
  wait_for "frontend(9516)" "curl -fs -o /dev/null http://localhost:9516/" 60
else
  # Redis（TCP ping）
  wait_for "redis"        "docker exec easylog-redis redis-cli ping | grep -q PONG" 60
  # ES（HTTP 200）
  wait_for "elasticsearch" "curl -fs -o /dev/null http://localhost:9200/_cluster/health" 180
  # Kafka（能列出 topic）
  wait_for "kafka"        "docker exec easylog-kafka kafka-topics --bootstrap-server localhost:9092 --list >/dev/null 2>&1" 120
  # 应用 HTTP 端口
  wait_for "admin(9515)"  "curl -fs -o /dev/null http://localhost:9515/up" 120
  wait_for "ingestion(9514)" "curl -fs -o /dev/null http://localhost:9514/api/v1/ingest/heartbeat" 120
  wait_for "query(9517)"  "curl -fs -o /dev/null http://localhost:9517/" 120
  wait_for "frontend(9516)" "curl -fs -o /dev/null http://localhost:9516/" 60
fi

echo
echo "========== EasyLog 启动完成（$ACTIVE_MODE 档） =========="
docker compose ps
echo
echo "访问地址（HOST=$HOST_IP）："
echo "  控制台:      http://$HOST_IP:9516/"
if [ "$ACTIVE_MODE" = "lite" ]; then
  echo "  管理/接入:   http://$HOST_IP:9515/up  (单容器，接入 API 同端口 /api/v1/ingest)"
else
  echo "  管理面健康:  http://$HOST_IP:9515/up"
  echo "  接入心跳:    http://$HOST_IP:9514/api/v1/ingest/heartbeat"
  echo "  检索入口:    http://$HOST_IP:9517/"
  echo "  ES 直查:     http://$HOST_IP:9200/_cat/indices"
fi
if [ "$ACTIVE_MODE" = "full" ]; then
  echo "  RabbitMQ台:  http://$HOST_IP:15672/  (easylog/easylog)"
fi
echo
echo "接入示例："
if [ "$ACTIVE_MODE" = "lite" ]; then
  echo "  curl -s -X POST http://$HOST_IP:9515/api/v1/ingest \\"
  echo "    -H 'X-EasyLog-App: app_demo_001' -H 'Authorization: Bearer sec_demo_001' \\"
  echo "    -H 'Content-Type: application/json' -H 'Accept: application/json' \\"
  echo "    -d '{\"msg\":\"hello\",\"level\":\"ERROR\",\"service\":\"order-api\"}'"
  echo "  （默认账号 admin@easylog.local，首启 seed 密码见容器日志或文档）"
else
  echo "  curl -s -X POST http://$HOST_IP:9514/api/v1/ingest \\"
  echo "    -H 'X-EasyLog-App: app_demo_001' -H 'Authorization: Bearer sec_demo_001' \\"
  echo "    -H 'Content-Type: application/json' -H 'Accept: application/json' \\"
  echo "    -d '{\"msg\":\"hello\",\"level\":\"ERROR\",\"service\":\"order-api\"}'"
fi
