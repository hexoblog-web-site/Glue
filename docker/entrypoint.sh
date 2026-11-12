#!/bin/sh
###############################################################################
# Sugar-Box 容器入口
#
#   1. 把可变文件统一收到 /data（容器重建不丢数据）
#   2. 等数据库就绪、按环境变量生成 config.php、建表、建管理员
#   3. 交出控制权，起 Apache
#
# 只处理「首次启动」；之后每次重启只做第 1、2 步的幂等检查，不会覆盖后台改过的设置。
###############################################################################
set -e

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-zan}"
DB_USER="${DB_USER:-zan}"
DB_PREFIX="${DB_PREFIX:-zan_}"
export DB_HOST DB_PORT DB_NAME DB_USER DB_PREFIX

echo "[zan] 启动中：DB=${DB_HOST}:${DB_PORT}/${DB_NAME}"

mkdir -p /data

# 配置与运行期文件都落到 /data，代码目录保持只读语义
if [ -f /var/www/html/config.php ] && [ ! -L /var/www/html/config.php ]; then
    echo "[zan] 发现镜像内 config.php，迁移到 /data"
    mv /var/www/html/config.php /data/config.php
fi
ln -sf /data/config.php /var/www/html/config.php

if [ -d /var/www/html/data ] && [ ! -L /var/www/html/data ]; then
    mkdir -p /data/app-data
    cp -a /var/www/html/data/. /data/app-data/ 2>/dev/null || true
    rm -rf /var/www/html/data
fi
mkdir -p /data/app-data
ln -sfn /data/app-data /var/www/html/data

# 后台登录节流、更新状态需要可写
chown -R www-data:www-data /data

if ! php /usr/local/bin/zan-boot.php; then
    echo "[zan] 启动自检失败，容器继续启动（页面会显示具体错误）" >&2
fi

chown -R www-data:www-data /data

exec "$@"
