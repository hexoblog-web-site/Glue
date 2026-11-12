# syntax=docker/dockerfile:1

###############################################################################
# Sugar-Box 3.0 · 纯点赞服务
#
#   docker build -t sugar-box:3.0.0 .
#   docker run -d --name zan -p 8080:80 \
#     -e DB_HOST=... -e DB_NAME=zan -e DB_USER=zan -e DB_PASS=... \
#     -e ADMIN_EMAIL=me@example.com -e ADMIN_PASSWORD=... \
#     -v zan_data:/data sugar-box:3.0.0
#
# 只需要 PHP + MySQL，没有 Node、没有编译、没有额外的守护进程。
###############################################################################

FROM php:8.3-apache

LABEL org.opencontainers.image.title="Sugar-Box" \
      org.opencontainers.image.description="Hexo 文章点赞服务（PHP + MySQL），只做点赞，不采集访客数据" \
      org.opencontainers.image.version="3.0.0" \
      org.opencontainers.image.licenses="MIT"

# pdo_mysql：唯一的 PHP 扩展依赖；opcache 已内置
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

# PHP 运行时参数（按 2G 内存的小机器调过）
COPY docker/php.ini /usr/local/etc/php/conf.d/99-zan.ini

# Apache：关目录浏览、加安全响应头
COPY docker/apache.conf /etc/apache2/conf-available/zan.conf
RUN a2enconf zan

# 应用本体
COPY --chown=www-data:www-data . /var/www/html

# 启动脚本：等数据库 → 生成 config.php → 建表建管理员 → 起 Apache
COPY docker/entrypoint.sh /usr/local/bin/zan-entrypoint
COPY docker/zan-boot.php /usr/local/bin/zan-boot.php
RUN chmod +x /usr/local/bin/zan-entrypoint \
    && sed -i 's/\r$//' /usr/local/bin/zan-entrypoint /usr/local/bin/zan-boot.php \
    && mkdir -p /data \
    && chown -R www-data:www-data /data

# 数据持久化：config.php 与运行期文件都在这里，容器重建不丢
VOLUME ["/data"]

ENV DB_HOST=db \
    DB_PORT=3306 \
    DB_NAME=zan \
    DB_USER=zan \
    DB_PREFIX=zan_ \
    TZ=Asia/Shanghai

WORKDIR /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/index.php") === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/zan-entrypoint"]
CMD ["apache2-foreground"]
