<?php
/**
 * 唯一接口。
 *
 *   GET  ?action=query&keys[]=/a&keys[]=/b   批量读单页点赞数（匿名、不写库）
 *   POST  action=like&key=/a&path=/a&vid=z   点赞，写库后返回最新计数
 *
 * 点赞只允许内容页：前端只把 data-zan-scope="content" 的容器渲染成可点按钮，
 * 服务端再要求 path 非根、且与 key 一致（或显式 scope=content），列表页只能读数字。
 *
 * 返回统一为 {code, message, ver, data}；业务错误一律 HTTP 200 + code。
 *   code 0 成功 / 2 已经点过 / 403 不是内容页 / 400 参数有误 / 429 太频繁 / 500 服务端异常
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require_once dirname(__DIR__) . '/include/schema.php';

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
/* 不用 $_REQUEST：它的组成受 request_order 影响，容易把同名 Cookie 混进来 */
$action = zan_post_str('action');
if ($action === '') {
    $action = zan_get_str('action');
}
if ($action === '') {
    $action = ($method === 'POST' ? 'like' : 'query');
}

/* query 只返回公开计数，直接放开 *，不怕白名单漏配；
   like 仍只认白名单，挡住别的站点伪造点赞并读结果。 */
zan_cors($action === 'query');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!zan_installed()) {
    zan_json(500, '尚未安装，请先打开 install/');
}

try {
    $pdo = zan_db();
} catch (PDOException $e) {
    zan_json(500, '数据库连接失败');
}

/* ---------------------------------------------------------------- 批量读 */
if ($action === 'query') {
    if ($method !== 'GET') {
        zan_json(400, '读取请用 GET');
    }

    $raw = isset($_GET['keys']) ? $_GET['keys'] : array();
    if (!is_array($raw)) {
        $raw = array($raw);
    }
    $keys = array();
    foreach (array_slice($raw, 0, 30) as $item) {
        $k = zan_norm_key($item);
        if ($k !== '') {
            $keys[$k] = true;
        }
    }
    $keys = array_keys($keys);
    if (!$keys) {
        zan_json(0, 'ok', array());
    }

    $likes = array();
    try {
        $holders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare('SELECT `page_key`, COUNT(*) AS likes FROM ' . zan_table('log')
            . ' WHERE `page_key` IN (' . $holders . ') GROUP BY `page_key`');
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $likes[$row['page_key']] = (int) $row['likes'];
        }
    } catch (PDOException $e) {
        zan_json(500, '读取失败');
    }

    $data = array();
    foreach ($keys as $k) {
        $data[$k] = array('likes' => isset($likes[$k]) ? $likes[$k] : 0);
    }
    zan_json(0, 'ok', $data);
}

/* ---------------------------------------------------------------- 点赞 */
if ($action === 'like') {
    if ($method !== 'POST') {
        zan_json(400, '点赞请用 POST');
    }

    $key   = zan_norm_key(zan_post_str('key'));
    $vid   = zan_post_str('vid');
    $title = zan_post_str('title');

    if ($key === '') {
        zan_json(400, '无效的文章标识');
    }

    /* 只有内容页本身能点赞：列表页、首页、侧栏只允许显示数字 */
    zan_content_guard($key);

    $hash = zan_visitor_hash($vid);
    $uniq = hash('sha256', $key . '|' . $hash);

    $title = trim(preg_replace('/\s+/u', ' ', strip_tags($title)));
    if (mb_strlen($title, 'UTF-8') > 190) {
        $title = mb_substr($title, 0, 190, 'UTF-8');
    }

    try {
        /* 粗粒度限流：同一个人一小时内最多 60 次，挡住脚本刷数 */
        $rl = $pdo->prepare('SELECT COUNT(*) FROM ' . zan_table('log')
            . ' WHERE `visitor_hash` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $rl->execute(array($hash));
        if ((int) $rl->fetchColumn() >= 60) {
            zan_json(429, '操作太频繁，请稍后再试');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare('INSERT IGNORE INTO ' . zan_table('log')
            . ' (`page_key`, `visitor_hash`, `uniq`, `created_at`) VALUES (?, ?, ?, NOW())');
        $stmt->execute(array($key, $hash, $uniq));
        $isNew = $stmt->rowCount() > 0;

        $page = $pdo->prepare('INSERT INTO ' . zan_table('page')
            . ' (`page_key`, `title`, `created_at`, `updated_at`) VALUES (?, ?, NOW(), NOW())'
            . ' ON DUPLICATE KEY UPDATE `updated_at` = NOW(), `title` = IF(VALUES(`title`) = \'\', `title`, VALUES(`title`))');
        $page->execute(array($key, $title));

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM ' . zan_table('log') . ' WHERE page_key = ?');
        $cnt->execute(array($key));
        $likes = (int) $cnt->fetchColumn();

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        zan_json(500, '写入失败，请稍后再试');
    }

    if ($isNew) {
        zan_json(0, '点赞成功', array('likes' => $likes, 'liked' => true));
    }
    zan_json(2, '已经点过赞了', array('likes' => $likes, 'liked' => true));
}

zan_json(400, '无效的请求');
