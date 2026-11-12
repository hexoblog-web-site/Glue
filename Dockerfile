# syntax=docker/dockerfile:1

###############################################################################
# Glue 3.0 · 纯点赞服务（Nginx + PHP-FPM，不用 Apache）
#
#   docker build -t glue:3.0.0 .
#   docker run -d --name zan -p 8080:80 \
#     -e DB_HOST=... -e DB_NAME=zan -e DB_USER=zan -e DB_PASS=... \
#     -e ADMIN_EMAIL=me@example.com -e ADMIN_PASSWORD=... \
#     -v zan_data:/data glue:3.0.0
#
# 容器内只有一个 Nginx 进程 + 一组 PHP-FPM worker，没有 Apache、没有 Node。
###############################################################################

FROM php:8.3-fpm

LABEL org.opencontainers.image.title="Glue" \
      org.opencontainers.image.description="Hexo 文章点赞服务（Nginx + PHP-FPM + MySQL），只做点赞，不采集访客数据" \
      org.opencontainers.image.version="3.0.0" \
      org.opencontainers.image.licenses="MIT"

# Nginx 作为唯一的 Web 服务器；pdo_mysql 是唯一的 PHP 扩展依赖
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default /var/www/html/*

# PHP 运行时参数（按 2G 内存的小机器调过）
COPY docker/php.ini /usr/local/etc/php/conf.d/99-zan.ini

# PHP-FPM：只监听 127.0.0.1:9000，挡掉直接把 FastCGI 端口暴露出去的情况
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-zan.conf

# Nginx 站点配置（默认 nginx.conf 已经 include /etc/nginx/conf.d/*.conf）
COPY docker/nginx.conf /etc/nginx/conf.d/zan.conf

# 应用本体
COPY --chown=www-data:www-data . /var/www/html

# 启动脚本：等数据库 → 生成 config.php → 建表建管理员 → 同时拉起 php-fpm 与 nginx
COPY docker/entrypoint.sh /usr/local/bin/zan-entrypoint
COPY docker/zan-boot.php /usr/local/bin/zan-boot.php
RUN chmod +x /usr/local/bin/zan-entrypoint \
    && sed -i 's/\r$//' /usr/local/bin/zan-entrypoint /usr/local/bin/zan-boot.php \
    && mkdir -p /data /var/log/nginx /var/lib/nginx/body /var/lib/nginx/fastcgi \
                /var/lib/nginx/proxy /var/lib/nginx/scgi /var/lib/nginx/uwsgi \
    && chown -R www-data:www-data /data /var/log/nginx /var/lib/nginx

# 数据持久化：config.php 与运行期文件都在这里，容器重建不丢
VOLUME ["/data"]

ENV DB_HOST=db \
    DB_PORT=3306 \
    DB_NAME=zan \
    DB_USER=zan \
    DB_PREFIX=zan_ \
    ZAN_CONFIG_FILE=/data/config.php \
    ZAN_DATA_DIR=/data/app-data \
    TZ=Asia/Shanghai

WORKDIR /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/index.php") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/zan-entrypoint"]
