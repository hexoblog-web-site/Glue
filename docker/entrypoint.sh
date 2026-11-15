#!/bin/sh
###############################################################################
# Glue 容器入口（Nginx + PHP-FPM）
#
#   1. 把可变文件统一收到 /data（容器重建不丢数据）
#   2. 等数据库就绪、按环境变量生成 config.php、建表、建管理员
#   3. 同时拉起 php-fpm 与 nginx，任一进程挂掉就让容器退出由 Docker 重启
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

# nginx 临时目录（apt 装的包通常会建好，这里兜个底）
mkdir -p /var/lib/nginx/body /var/lib/nginx/fastcgi /var/lib/nginx/proxy /var/lib/nginx/scgi /var/lib/nginx/uwsgi /var/log/nginx
chown -R www-data:www-data /var/lib/nginx /var/log/nginx 2>/dev/null || true

# 传了自定义命令就照办，方便 `docker run 镜像 php -v` / `nginx -t` 这类调试；
# 不传参数（compose 的默认情况）才进下面的双进程模式。
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

###############################################################################
# 双进程模型
#
#   php-fpm -F   前台跑 master（worker 由它自己管）
#   nginx        前台跑 master
#
# 用 shell 当 PID 1 看着两个子进程：谁先没了就整套退出，
# 交给 docker 的 restart: unless-stopped 重新拉起来——比留个半死的容器好排查。
###############################################################################
php-fpm -F &
FPM_PID=$!

nginx -g 'daemon off;' &
NGINX_PID=$!

terminate() {
    echo "[zan] 收到停止信号，正在关闭 php-fpm 与 nginx"
    kill -TERM "$NGINX_PID" "$FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
    wait "$FPM_PID" 2>/dev/null || true
    exit 0
}
trap terminate TERM INT QUIT

# 给一个短暂窗口让配置错误之类的快速失败及时暴露
sleep 2
if ! kill -0 "$FPM_PID" 2>/dev/null; then
    echo "[zan] php-fpm 启动失败，请看上面的报错" >&2
    kill -TERM "$NGINX_PID" 2>/dev/null || true
    exit 1
fi
if ! kill -0 "$NGINX_PID" 2>/dev/null; then
    echo "[zan] nginx 启动失败，请看上面的报错" >&2
    kill -TERM "$FPM_PID" 2>/dev/null || true
    exit 1
fi

echo "[zan] php-fpm (pid $FPM_PID) 与 nginx (pid $NGINX_PID) 已就绪，监听 :80"

while :; do
    sleep 5
    if ! kill -0 "$FPM_PID" 2>/dev/null; then
        echo "[zan] php-fpm 意外退出，容器退出等待重启" >&2
        kill -TERM "$NGINX_PID" 2>/dev/null || true
        exit 1
    fi
    if ! kill -0 "$NGINX_PID" 2>/dev/null; then
        echo "[zan] nginx 意外退出，容器退出等待重启" >&2
        kill -TERM "$FPM_PID" 2>/dev/null || true
        exit 1
    fi
done
