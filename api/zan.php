<?php
/**
 * 唯一接口。
 *
 *   GET  ?action=query&keys[]=/a&keys[]=/b   批量读单页计数（匿名、不写库）
 *   GET  ?action=site                        全站累计（pv / 访客 / 访问次数）
 *   POST  action=pageview&url=/a&title=...   记一条浏览事件（Umami 的 send 模型）
 *   POST  action=like&key=/a&path=/a&vid=z   点赞，写库后返回最新计数
 *   POST  action=view&key=/a&path=/a         1.4 及更早 SDK 的旧入口，等同 pageview
 *
 * 统计在所有页面都记（首页、归档、标签页都算），身份完全由服务端推导：
 *   session = hash(站点 + IP + UA + 当期盐 + site_secret)
 *   visit   = hash(session + 小时桶)
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

/* 只读接口（query / site）返回的只是公开计数，直接放开 *，不怕白名单漏配；
   写接口仍只认白名单，挡住别的站点伪造点赞并读结果。 */
zan_cors(in_array($action, array('query', 'site'), true));

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

zan_stats_bootstrap($pdo);

/** 把前端传来的 url / key / path 归一化成页面路径与查询串 */
function zan_api_target()
{
    $raw = '';
    foreach (array('url', 'key', 'path') as $k) {
        $v = zan_post_str($k);
        if ($v !== '') {
            $raw = $v;
            break;
        }
    }
    if ($raw === '') {
        return array('', '');
    }
    $query = '';
    $p = parse_url($raw);
    if (is_array($p) && isset($p['query'])) {
        $query = (string) $p['query'];
    }
    return array(zan_norm_key($raw), substr($query, 0, 255));
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
    $stats = array();
    try {
        $holders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare('SELECT `page_key`, COUNT(*) AS likes FROM ' . zan_table('log')
            . ' WHERE `page_key` IN (' . $holders . ') GROUP BY `page_key`');
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $likes[$row['page_key']] = (int) $row['likes'];
        }

        if (zan_stats_ready()) {
            $vs = $pdo->prepare('SELECT `url_path` AS k, ' . zan_page_stat_cols() . ' FROM ' . zan_table('event')
                . ' WHERE `url_path` IN (' . $holders . ') AND `event_type` = 1 GROUP BY `url_path`');
            $vs->execute($keys);
            foreach ($vs->fetchAll() as $row) {
                $stats[$row['k']] = array(
                    'pv'     => (int) $row['pv'],
                    'uv'     => (int) $row['uv'],
                    'visits' => (int) $row['visits'],
                );
            }
        }
    } catch (PDOException $e) {
        zan_json(500, '读取失败');
    }

    $data = array();
    foreach ($keys as $k) {
        $s = isset($stats[$k]) ? $stats[$k] : array('pv' => 0, 'uv' => 0, 'visits' => 0);
        $data[$k] = array(
            'likes'     => isset($likes[$k]) ? $likes[$k] : 0,
            /* views 按当前口径给，前端不用判断；另外两个原样给出，方便自定义展示 */
            'views'     => zan_view_display($s['pv'], $s['uv']),
            'pageviews' => $s['pv'],
            'visitors'  => $s['uv'],
            'visits'    => $s['visits'],
        );
    }
    zan_json(0, 'ok', $data);
}

/* ---------------------------------------------------------------- 全站累计 */
if ($action === 'site') {
    if ($method !== 'GET') {
        zan_json(400, '读取请用 GET');
    }
    $s = zan_site_stats($pdo, '1970-01-01 00:00:00', '2099-12-31 23:59:59');
    zan_json(0, 'ok', array(
        'views'     => zan_view_display($s['pv'], $s['uv']),
        'pageviews' => $s['pv'],
        'visitors'  => $s['uv'],
        'visits'    => $s['visits'],
    ));
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

/* ---------------------------------------------------------------- 浏览事件 */
if ($action === 'pageview' || $action === 'view') {
    if ($method !== 'POST') {
        zan_json(400, '上报浏览请用 POST');
    }

    list($path, $urlQuery) = zan_api_target();
    if ($path === '') {
        zan_json(400, '无效的页面标识');
    }

    $title    = zan_post_str('title');
    $referrer = zan_post_str('referrer');
    $screen   = zan_post_str('screen');
    $language = zan_post_str('language');
    $hostname = zan_post_str('hostname');
    if ($hostname === '' && !empty($_SERVER['HTTP_HOST'])) {
        $hostname = (string) $_SERVER['HTTP_HOST'];
    }

    $title = trim(preg_replace('/\s+/u', ' ', strip_tags($title)));
    if (mb_strlen($title, 'UTF-8') > 250) {
        $title = mb_substr($title, 0, 250, 'UTF-8');
    }

    $session = zan_session_id();
    $counted = false;

    try {
        /* 开关关掉、预取请求、爬虫，一律只读不写：前端照样能显示数字 */
        $canCount = zan_stats_ready() && zan_views_on() && zan_view_skip_reason() === '';

        if ($canCount) {
            /* 同一个会话一小时最多 300 次，挡脚本刷量 */
            $rl = $pdo->prepare('SELECT COUNT(*) FROM ' . zan_table('event')
                . ' WHERE `session_id` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $rl->execute(array($session));
            if ((int) $rl->fetchColumn() >= 300) {
                zan_json(429, '操作太频繁，请稍后再试');
            }

            zan_stats_record($pdo, $path, $title, $urlQuery, $referrer, $hostname, $screen, $language);
            $counted = true;
        }
    } catch (PDOException $e) {
        zan_json(500, '写入失败，请稍后再试');
    }

    $stat = zan_page_stats($pdo, $path);

    $likes = 0;
    try {
        $l = $pdo->prepare('SELECT COUNT(*) FROM ' . zan_table('log') . ' WHERE `page_key` = ?');
        $l->execute(array($path));
        $likes = (int) $l->fetchColumn();
    } catch (PDOException $e) {
    }

    $data = array(
        'views'     => zan_view_display($stat['pv'], $stat['uv']),
        'pageviews' => $stat['pv'],
        'visitors'  => $stat['uv'],
        'visits'    => $stat['visits'],
        'likes'     => $likes,
        'counted'   => $counted,
    );

    /* 页面上有全站计数器时才顺带算全站，省掉每次浏览一次全表 distinct */
    if (!empty($_POST['want_site'])) {
        $s = zan_site_stats($pdo, '1970-01-01 00:00:00', '2099-12-31 23:59:59');
        $data['site'] = array(
            'views'     => zan_view_display($s['pv'], $s['uv']),
            'pageviews' => $s['pv'],
            'visitors'  => $s['uv'],
            'visits'    => $s['visits'],
        );
    }

    zan_json(0, $counted ? '已记录' : 'ok', $data);
}

zan_json(400, '无效的请求');
