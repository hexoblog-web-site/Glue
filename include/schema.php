<?php
/**
 * 表结构：安装程序与运行时共用同一份 DDL。
 *
 * page     一个页面一行，存标题（可选）
 * log      一条点赞一行，(page_key, visitor_hash) 唯一 —— 一人一篇只能点一次
 * session  一个访客一行：浏览器、系统、设备、语言、地区（照 Umami 的 session 表）
 * event    一次浏览一行：页面、来源、标题、会话、访问（照 Umami 的 website_event）
 * view     1.4 及更早版本的阅读表，只作为升级时的迁移来源，不再写入
 * admin    后台账号
 *
 * 统计照 Umami 的写入模型：浏览器不存身份，session 由服务端按
 * IP + UA + 当期盐推导；每次浏览落一条 event，指标全在查询侧算：
 *   浏览次数 = SUM(每个访问的事件数)
 *   访客数   = COUNT(DISTINCT session_id)
 *   访问次数 = COUNT(DISTINCT visit_id)
 *   跳出     = 只有一条事件的访问数
 *   停留     = SUM(每条访问的 max_time - min_time)
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

        "CREATE TABLE IF NOT EXISTS `{$prefix}view` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `page_key` VARCHAR(191) NOT NULL,
            `visitor_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `visit_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            `uniq` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq` (`uniq`),
            KEY `page_key` (`page_key`),
            KEY `page_day` (`page_key`, `created_at`),
            KEY `visit_id` (`visit_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$prefix}session` (
            `session_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `website_id` VARCHAR(64) NOT NULL DEFAULT '',
            `browser` VARCHAR(20) NOT NULL DEFAULT '',
            `os` VARCHAR(20) NOT NULL DEFAULT '',
            `device` VARCHAR(20) NOT NULL DEFAULT '',
            `screen` VARCHAR(11) NOT NULL DEFAULT '',
            `language` VARCHAR(35) NOT NULL DEFAULT '',
            `country` CHAR(2) NOT NULL DEFAULT '',
            `region` VARCHAR(32) NOT NULL DEFAULT '',
            `city` VARCHAR(64) NOT NULL DEFAULT '',
            `distinct_id` VARCHAR(50) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`session_id`),
            KEY `created_at` (`created_at`),
            KEY `browser` (`browser`),
            KEY `os` (`os`),
            KEY `device` (`device`),
            KEY `country` (`country`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `{$prefix}event` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `website_id` VARCHAR(64) NOT NULL DEFAULT '',
            `session_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `visit_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `url_path` VARCHAR(191) NOT NULL,
            `url_query` VARCHAR(255) NOT NULL DEFAULT '',
            `referrer_path` VARCHAR(191) NOT NULL DEFAULT '',
            `referrer_query` VARCHAR(255) NOT NULL DEFAULT '',
            `referrer_domain` VARCHAR(191) NOT NULL DEFAULT '',
            `page_title` VARCHAR(255) NOT NULL DEFAULT '',
            `hostname` VARCHAR(100) NOT NULL DEFAULT '',
            `event_type` TINYINT NOT NULL DEFAULT 1,
            `event_name` VARCHAR(50) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_id` (`event_id`),
            KEY `page_time` (`url_path`, `created_at`),
            KEY `created_at` (`created_at`),
            KEY `session_id` (`session_id`),
            KEY `visit_id` (`visit_id`),
            KEY `referrer_domain` (`referrer_domain`)
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
