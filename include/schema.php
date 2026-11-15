<?php
/**
 * 表结构：安装程序与运行时共用同一份 DDL。
 *
 * page   一个页面一行，存标题（可选）
 * log    一条点赞一行，(page_key, visitor_hash) 唯一 —— 一人一篇只能点一次
 * admin  后台账号
 *
 * 只有这三张表：本程序只做点赞，不记录任何浏览、会话或访客画像。
 * 数据库里除了加盐哈希后的匿名指纹，没有别的访客信息。
 */
if (!defined('ZAN_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

function zan_schema_sql($prefix)
{
    return array(
        "CREATE TABLE IF NOT EXISTS `{$prefix}page` (
            `page_key` VARCHAR(191) NOT NULL,
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`page_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$prefix}log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `page_key` VARCHAR(191) NOT NULL,
            `visitor_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `uniq` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq` (`uniq`),
            KEY `page_key` (`page_key`),
            KEY `visitor_hash` (`visitor_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$prefix}admin` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(191) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    );
}

function zan_create_tables(PDO $pdo, $prefix)
{
    foreach (zan_schema_sql($prefix) as $sql) {
        $pdo->exec($sql);
    }
}
