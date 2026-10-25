# EasyLog PHP 应用镜像
# 统一基础镜像，admin/ingestion/query/writer/worker 共用
# 通过 entrypoint 切换启动角色
#
# 基线安全策略：
# - php:8.3-cli-bookworm 为浮动小版本标签，重建镜像即获取最新安全补丁
#   （例：2026-10-21 CVE-2026-109618/109619 修复于 8.3.36）；
# - PHP 8.2 安全支持止于 2026-12-31，故基线取 8.3（源码兼容 ^8.2）；
# - 定期（至少每月）或收到安全通告时执行 docker build 滚动补丁；
#   生产建议 pin 具体 patch 版本或 digest 并纳入升级流程。

FROM php:8.3-cli-bookworm

# 系统依赖
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip zip libzip-dev libonig-dev libssl-dev \
        libcurl4-openssl-dev libicu-dev libxml2-dev libbrotli-dev \
        librdkafka-dev \
        nginx supervisor \
    && rm -rf /var/lib/apt/lists/*

# PHP 扩展（pdo_sqlite/sqlite3 官方镜像默认已编译，无需安装）
RUN docker-php-ext-install -j"$(nproc)" \
        zip pdo_mysql mysqli pcntl intl opcache bcmath sockets

# Swoole（带 swoole_pgsql 可选，此处仅基础扩展）
# 注：ActiveMQ 消费走 stomp-php 纯 PHP composer 包（无需 pecl stomp 扩展，bookworm 也无 libstomp 包）
RUN pecl install swoole rdkafka redis \
    && docker-php-ext-enable swoole rdkafka redis

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Octane 配置
ENV COMPOSER_ALLOW_XDEBUG=1 \
    COMPOSER_NO_INTERACTION=1 \
    OCTANE_SERVER=swoole \
    PHP_OPCACHE_ENABLE=1

# 工作目录
WORKDIR /var/www/html

# 默认入口由 compose 通过 command 覆盖
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/99-easylog.ini
COPY deploy/docker/entrypoint.sh /usr/local/bin/easylog-entrypoint
RUN chmod +x /usr/local/bin/easylog-entrypoint

# ---------------------------------------------------------------------------
# 应用代码
#
# 原先镜像里只有 php.ini / entrypoint，没有任何应用代码，完全依赖 compose 的
# bind mount 提供。这意味着 K8s 部署（无挂载）必然 CrashLoopBackOff。
# 这里把 services/admin 打进镜像，使镜像自包含；compose 的 bind mount 会在
# 开发态覆盖它，行为不变。
#
# vendor 由下面的 composer install 预装：K8s 下 writer / worker 等角色不会执行
# entrypoint 里的 admin-only 安装分支，镜像必须自带依赖，否则同样起不来。
# ---------------------------------------------------------------------------
COPY services/admin /var/www/html

RUN set -eux; \
    composer install --no-interaction --no-dev --optimize-autoloader --no-scripts; \
    mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache; \
    chmod -R 775 storage bootstrap/cache

ENTRYPOINT ["easylog-entrypoint"]
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=9514"]
