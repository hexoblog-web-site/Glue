<?php
/**
 * 公共层：配置、数据库、标识归一化、访客识别、JSON 输出、后台鉴权与页面框架。
 */
if (!defined('ZAN_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/defs.php';

/* ---------------------------------------------------------------------------
 * 配置
 * ------------------------------------------------------------------------- */

function zan_config_file()
{
    return ZAN_ROOT . 'config.php';
}

function zan_installed()
{
    return is_file(zan_config_file());
}

/**
 * 读取 config.php。文件不存在时返回空数组，由调用方决定怎么处理。
 */
function zan_config()
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = array();
    if (zan_installed()) {
        $data = @include zan_config_file();
        if (is_array($data)) {
            $cfg = $data;
        }
    }
    return $cfg;
}

function zan_cfg($key, $default = '')
{
    $cfg = zan_config();
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/**
 * 把若干项写回 config.php。写入失败返回 false（主机目录只读时会发生）。
 */
function zan_config_save(array $changes)
{
    $cfg = zan_config();
    foreach ($changes as $k => $v) {
        $cfg[$k] = $v;
    }
    $body = "<?php\n/**\n * Sugar-Box 配置（安装向导生成，后台设置页会更新）\n */\nreturn " . var_export($cfg, true) . ";\n";
    $ok = @file_put_contents(zan_config_file(), $body, LOCK_EX) !== false;
    if ($ok) {
        @chmod(zan_config_file(), 0644);
        /* opcache 会把 config.php 里的数组编译进缓存，不失效的话下一个请求还读旧值 */
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(zan_config_file(), true);
        }
    }
    return $ok;
}

/* ---------------------------------------------------------------------------
 * 数据库
 * ------------------------------------------------------------------------- */

function zan_table($name)
{
    return '`' . zan_cfg('table_prefix', 'zan_') . $name . '`';
}

function zan_db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . zan_cfg('db_host', 'localhost')
        . ';dbname=' . zan_cfg('db_name', '')
        . ';charset=utf8mb4';
    $pdo = new PDO($dsn, zan_cfg('db_user', ''), zan_cfg('db_pass', ''), array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));
    return $pdo;
}

/** 数据库连不上时给后台一个能看懂的页面 */
function zan_db_or_die()
{
    try {
        return zan_db();
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>数据库连接失败</title>'
            . '<div style="max-width:560px;margin:80px auto;font:15px/1.7 system-ui,sans-serif">'
            . '<h1 style="font-size:20px">数据库连接失败</h1>'
            . '<p>请检查 <code>config.php</code> 里的数据库账号，或确认 MySQL 正在运行。</p>'
            . '<p style="color:#888">' . zan_h($e->getMessage()) . '</p></div>';
        exit;
    }
}

/* ---------------------------------------------------------------------------
 * 站点设置
 * ------------------------------------------------------------------------- */

function zan_site_name()
{
    $n = trim((string) zan_cfg('site_name', ''));
    return $n !== '' ? $n : ZAN_BRAND;
}

/* ---------------------------------------------------------------------------
 * 地址
 * ------------------------------------------------------------------------- */

/** 程序所在目录，例如 '' 或 '/zan' */
function zan_base_path()
{
    $script = str_replace('\\', '/', (string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'));
    /* Windows 下 dirname('/a.php') 会返回反斜杠，先统一成斜杠再判断 */
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (preg_match('#/(admin|api|install)$#', $dir)) {
        $dir = rtrim(str_replace('\\', '/', dirname($dir)), '/');
    }
    return ($dir === '/' || $dir === '\\') ? '' : $dir;
}

function zan_url($path = '')
{
    return zan_base_path() . '/' . ltrim($path, '/');
}

/** 站点根地址（含协议与主机），用于生成接入代码 */
function zan_abs_url($path = '')
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    return ($https ? 'https://' : 'http://') . $host . zan_url($path);
}

/* ---------------------------------------------------------------------------
 * 文章标识归一化（与 zan.js 的 normKey 保持一致）
 * ------------------------------------------------------------------------- */

function zan_norm_key($raw)
{
    $p = trim((string) $raw);
    if ($p === '') {
        return '';
    }
    $p = preg_replace('/[?#].*$/', '', $p);
    $p = preg_replace('#^[a-z][a-z0-9+.-]*://[^/]+#i', '', $p);
    $p = preg_replace('#/index\.html?$#i', '/', $p);
    $p = preg_replace('#/{2,}#', '/', $p);
    if (strlen($p) > 1) {
        $p = rtrim($p, '/');
    }
    if ($p === '') {
        $p = '/';
    }
    if ($p[0] !== '/') {
        $p = '/' . $p;
    }
    /* 只接受路径字符；中文、空格等按 UTF-8 放行，控制字符一律拒绝 */
    if (!preg_match('#^/[\x{20}-\x{7e}\x{4e00}-\x{9fa5}\x{3000}-\x{303f}\x{ff00}-\x{ffef}]*$#u', $p)) {
        return '';
    }
    if (mb_strlen($p, 'UTF-8') > 191) {
        return '';
    }
    return $p;
}

/**
 * 点赞只允许发生在内容页本身。
 *
 * 前端那一层：容器默认只读，只有写了 data-zan-scope="content" 的才渲染成按钮
 * （见 api/js/zan.js 的 canWrite）。这里补上服务端那一层：
 *   - path 为空或 /（站点根、以及任何没声明路径的请求）→ 拒绝
 *   - path ≠ key 且没写 scope=content → 拒绝（列表页借别人的 key 写数据）
 *   - 旧版 SDK 不传 path → 拒绝（前端升级后自然恢复）
 *
 * 统计不受这个限制：浏览事件在任何页面都记，见 zan_stats_record()。
 *
 * 这一层挡的是「页面用错容器」和「随手拼请求」，不构成安全边界；
 * 真正的防刷仍靠访客去重与限流。
 */
function zan_content_guard($key)
{
    $path  = zan_norm_key(isset($_POST['path']) ? $_POST['path'] : '');
    $scope = isset($_POST['scope']) ? (string) $_POST['scope'] : '';

    if ($path === '' || $path === '/') {
        zan_json(403, '只能在内容页点赞');
    }

    /* 模板显式声明「这是内容页自己的容器」（自定义 key 时用）：
       只要求请求确实来自某个内容页，不再强求 key 等于路径 */
    if ($scope === 'content') {
        return;
    }

    if ($path !== $key) {
        zan_json(403, '只能在内容页点赞');
    }
}

/* ---------------------------------------------------------------------------
 * 访客
 * ------------------------------------------------------------------------- */

function zan_client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $trusted = (array) zan_cfg('trusted_proxies', array());
    if ($trusted && in_array($ip, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $first = trim($parts[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return $ip;
}

function zan_vid_ok($vid)
{
    return is_string($vid) && preg_match('/^[A-Za-z0-9_-]{6,64}$/', $vid);
}

/**
 * 点赞用的访客指纹：浏览器 vid 优先（换网络不掉票），没有就退回 IP+UA。
 * 两种都加 site_secret 再哈希，数据库里看不到原始 IP。
 *
 * 注意：只有点赞用它。阅读统计不碰浏览器存储，见下面的 zan_session_id()。
 */
function zan_visitor_hash($vid)
{
    $secret = zan_stats_secret();
    if (zan_vid_ok($vid)) {
        return hash('sha256', 'v1|' . $vid . '|' . $secret);
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300) : '';
    return hash('sha256', 'v1|' . zan_client_ip() . '|' . $ua . '|' . $secret);
}

/* ---------------------------------------------------------------------------
 * 网站统计（Sugar-Box 2.0，写入模型照 Umami）
 *
 *   salt      = hash(当期时间桶)                          一个周期换一次
 *   sessionId = hash(站点 + IP + UA + salt + 密钥)         一个周期内同一个人
 *   visitId   = hash(sessionId + 小时桶)                   同一小时算一次访问
 *
 * 与 Umami 的 send/route + lib/crypto 一一对应：
 *   - 浏览器不存任何统计标识，session 完全由服务端推导
 *   - 每次浏览写一条事件，pv / 访客 / 访问次数全在查询侧 distinct
 *   - 盐不是随机值，是当期时间桶的哈希，密钥放在 site_secret
 * 点「赞」仍然用浏览器里的 vid（换网络不掉票），两套身份互不影响。
 * ------------------------------------------------------------------------- */

/** 站点 id：进 session 哈希，和 Umami 的 websiteId 同位 */
function zan_website_id()
{
    $id = trim((string) zan_cfg('site_id', ''));
    if ($id !== '') {
        return $id;
    }
    return substr(hash('sha256', 'sugar-box-site|' . zan_config_file()), 0, 32);
}

/** 统计用的密钥：安装时生成的 site_secret */
function zan_stats_secret()
{
    $s = (string) zan_cfg('site_secret', '');
    if ($s !== '') {
        return $s;
    }
    /* 手工改过 config.php 把 site_secret 弄丢了：用配置路径兜底，
       同一个站点永远算出同一个值，总比空串让 IP+UA 可被反推要好 */
    return hash('sha256', 'zan-fallback|' . zan_config_file());
}

/** 盐的轮换周期：day / week / month（默认 month，与 Umami 一致） */
function zan_salt_rotation()
{
    $r = strtolower((string) zan_cfg('salt_rotation', 'month'));
    return in_array($r, array('day', 'week', 'month'), true) ? $r : 'month';
}

/** 当期时间桶的起点（UTC），对应 Umami 的 startOfMonth / startOfWeek / startOfDay */
function zan_period_start($ts)
{
    $ts = (int) $ts;
    if (zan_salt_rotation() === 'day') {
        return gmdate('Y-m-d 00:00:00', $ts);
    }
    if (zan_salt_rotation() === 'week') {
        /* 周一为一周起点，与 date-fns 的 startOfWeek 默认一致 */
        $w = (int) gmdate('N', $ts);
        return gmdate('Y-m-d 00:00:00', $ts - ($w - 1) * 86400);
    }
    return gmdate('Y-m-01 00:00:00', $ts);
}

function zan_salt_bucket($ts = null)
{
    return zan_period_start($ts === null ? time() : $ts);
}

function zan_session_salt($ts = null)
{
    return hash('sha256', 'zan-salt|' . zan_salt_rotation() . '|' . zan_salt_bucket($ts));
}

/** 会话 id：同一站点 + 同一 IP + 同一 UA 在本周期内恒定 */
function zan_session_id($ts = null)
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300) : '';
    return hash('sha256', 's2|' . zan_website_id() . '|' . zan_client_ip() . '|' . $ua
        . '|' . zan_session_salt($ts) . '||' . zan_stats_secret());
}

/** 访问 id：同一会话按小时切段，与 Umami 的 uuid(sessionId, startOfHour) 同构 */
function zan_visit_id($session, $ts = null)
{
    $ts = $ts === null ? time() : (int) $ts;
    $hour = hash('sha256', gmdate('D, d M Y H:00:00', $ts) . ' GMT');
    return hash('sha256', 'v2|' . $session . '|' . $hour);
}

/* ---------------------------------------------------------------------------
 * 浏览器 / 系统 / 设备 / 地区
 *
 * Umami 用 ua-parser 与 GeoIP 库，这里不引第三方依赖，只覆盖常见值；
 * 识别不出来就留空，报表里显示「未知」，不影响计数。
 * ------------------------------------------------------------------------- */

function zan_ua_parse($ua)
{
    $ua = (string) $ua;
    if ($ua === '') {
        return array('', '', '');
    }

    $device = 'Desktop';
    if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua)
        || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua))) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|IEMobile/i', $ua)) {
        $device = 'Mobile';
    }

    $os = '';
    if (preg_match('/Windows NT 10\.0/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/CrOS/i', $ua)) {
        $os = 'Chrome OS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    $browser = '';
    if (preg_match('/Edg(e|A|iOS)?\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\/|Opera|OPT\//i', $ua)) {
        $browser = 'Opera';
    } elseif (preg_match('/SamsungBrowser/i', $ua)) {
        $browser = 'Samsung Internet';
    } elseif (preg_match('/Firefox\/|FxiOS/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/CriOS|Chrome\//i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari\//i', $ua)) {
        $browser = 'Safari';
    } elseif (preg_match('/MSIE|Trident/i', $ua)) {
        $browser = 'Internet Explorer';
    }

    return array($browser, $os, $device);
}

/**
 * 地区：只有套了 Cloudflare 才有国家（CF-IPCountry）。
 * 没装 GeoIP 库，所以默认只知道国家，省/市留空 —— 这点不如 Umami，
 * 但比为了一个字段塞几十 MB 的库进虚拟主机要现实。
 */
function zan_client_geo()
{
    $country = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY'])) : '';
    if (!preg_match('/^[A-Z]{2}$/', $country) || $country === 'XX' || $country === 'T1') {
        $country = '';
    }
    $region = isset($_SERVER['HTTP_CF_REGION']) ? substr(preg_replace('/[^\w\s\-]/u', '', (string) $_SERVER['HTTP_CF_REGION']), 0, 32) : '';
    $city   = isset($_SERVER['HTTP_CF_IPCITY']) ? substr(preg_replace('/[^\w\s\-]/u', '', (string) $_SERVER['HTTP_CF_IPCITY']), 0, 64) : '';
    return array($country, trim($region), trim($city));
}

/**
 * 把 document.referrer 拆成 域名 / 路径 / 参数；站内跳转不记来源，
 * 与 Umami 的「self-referral 不记 referrerDomain」一致。
 */
function zan_stats_referrer($ref, $hostname)
{
    $ref = trim((string) $ref);
    if ($ref === '') {
        return array('', '', '');
    }
    if (strlen($ref) > 1000) {
        $ref = substr($ref, 0, 1000);
    }

    $host = strtolower(preg_replace('/:\d+$/', '', trim((string) $hostname)));
    $host = preg_replace('/^www\./', '', $host);

    /* 只有路径时按本站补全 */
    if ($ref[0] === '/') {
        $ref = 'https://' . ($host !== '' ? $host : 'localhost') . $ref;
    }

    $p = parse_url($ref);
    if (!is_array($p) || empty($p['host'])) {
        return array('', '', '');
    }
    $domain = strtolower(preg_replace('/^www\./', '', (string) $p['host']));
    if ($domain === $host) {
        $domain = '';
    }
    $path  = isset($p['path']) ? substr((string) $p['path'], 0, 191) : '';
    $query = isset($p['query']) ? substr((string) $p['query'], 0, 255) : '';
    if ($domain === '' && $path === '') {
        return array('', '', '');
    }
    return array($path, $query, $domain);
}

/* ---------------------------------------------------------------------------
 * 旧表迁移
 * ------------------------------------------------------------------------- */

/** 表是否就绪；$refresh=true 时重新探测 */
function zan_stats_state(PDO $pdo, $refresh = false)
{
    static $st = null;
    if ($st !== null && !$refresh) {
        return $st;
    }
    $st = array('event' => false, 'session' => false, 'legacy' => false);
    foreach (array('event', 'session', 'view') as $t) {
        try {
            $pdo->query('SELECT 1 FROM ' . zan_table($t) . ' LIMIT 1');
            $st[$t === 'view' ? 'legacy' : $t] = true;
        } catch (PDOException $e) {
        }
    }
    return $st;
}

/** 把 1.4 的 view 表搬进 event / session（event_id 由旧 id 推导，幂等） */
function zan_stats_migrate_legacy(PDO $pdo)
{
    $v = zan_table('view');
    $e = zan_table('event');
    $s = zan_table('session');
    $p = zan_table('page');

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM ' . $v)->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $ex) {
        return 0;
    }
    /* 旧表可能没有 visit_id，也可能有列但整列都是空串（1.4 早期写过一批），
       两种都退回「访客 + 小时」推导，否则所有浏览会被算成同一次访问 */
    $hourExpr   = "SHA2(CONCAT('v1|', v.`visitor_hash`, '|hour|', DATE_FORMAT(v.`created_at`, '%Y-%m-%d %H:00:00')), 256)";
    $visitExpr  = in_array('visit_id', $cols, true)
        ? "IF(v.`visit_id` <> '', v.`visit_id`, " . $hourExpr . ')'
        : $hourExpr;
    $site = $pdo->quote(zan_website_id());

    $pdo->exec('INSERT IGNORE INTO ' . $s . ' (`session_id`, `website_id`, `created_at`)'
        . ' SELECT v.`visitor_hash`, ' . $site . ', MIN(v.`created_at`) FROM ' . $v
        . ' v GROUP BY v.`visitor_hash`');

    $pdo->exec('INSERT IGNORE INTO ' . $e
        . ' (`event_id`, `website_id`, `session_id`, `visit_id`, `url_path`, `page_title`, `event_type`, `created_at`)'
        . " SELECT SHA2(CONCAT('mig|', v.`id`), 256), " . $site . ', v.`visitor_hash`, ' . $visitExpr
        . ', v.`page_key`, COALESCE(p.`title`, \'\'), 1, v.`created_at`'
        . ' FROM ' . $v . ' v LEFT JOIN ' . $p . ' p ON p.`page_key` = v.`page_key`');

    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $e)->fetchColumn();
}

/**
 * 首次运行：补建表 + 迁移旧阅读记录。每个请求最多探测一次，
 * 迁移完成后在 config 里留标记，之后不再碰。
 */
function zan_stats_bootstrap(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $st = zan_stats_state($pdo);
    if (!$st['event'] || !$st['session']) {
        try {
            zan_create_tables($pdo, (string) zan_cfg('table_prefix', 'zan_'));
        } catch (PDOException $e) {
            return;
        }
        $st = zan_stats_state($pdo, true);
        if (!$st['event']) {
            return;
        }
    }

    if ((string) zan_cfg('stats_migrated', '') === '1') {
        return;
    }

    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('event'))->fetchColumn();
        if ($count === 0 && $st['legacy']) {
            zan_stats_migrate_legacy($pdo);
        }
    } catch (PDOException $e) {
    }

    zan_config_save(array('stats_migrated' => '1'));
}

function zan_stats_ready()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = zan_stats_state(zan_db());
        $ready = $st['event'];
    } catch (PDOException $e) {
        $ready = false;
    }
    return $ready;
}

/* ---------------------------------------------------------------------------
 * 写入
 * ------------------------------------------------------------------------- */

/**
 * 记一条浏览事件。返回记录用的 session / visit，出错抛 PDOException。
 * 只在页面真正被看见时调用（预取、爬虫、后台标签在 SDK 与这里各挡一次）。
 */
function zan_stats_record(PDO $pdo, $path, $title, $query, $referrer, $hostname, $screen, $language)
{
    $session = zan_session_id();
    $visit   = zan_visit_id($session);
    $ua      = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

    list($browser, $os, $device) = zan_ua_parse($ua);
    list($country, $region, $city) = zan_client_geo();

    $screen   = substr(preg_replace('/[^0-9x]/i', '', (string) $screen), 0, 11);
    $language = substr(preg_replace('/[^A-Za-z\-]/', '', (string) $language), 0, 35);

    $ins = $pdo->prepare('INSERT IGNORE INTO ' . zan_table('session')
        . ' (`session_id`, `website_id`, `browser`, `os`, `device`, `screen`, `language`,'
        . ' `country`, `region`, `city`, `distinct_id`, `created_at`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', NOW())');
    $ins->execute(array($session, zan_website_id(), $browser, $os, $device, $screen, $language, $country, $region, $city));

    list($refPath, $refQuery, $refDomain) = zan_stats_referrer($referrer, $hostname);

    $eid = bin2hex(random_bytes(16));
    $ev  = $pdo->prepare('INSERT INTO ' . zan_table('event')
        . ' (`event_id`, `website_id`, `session_id`, `visit_id`, `url_path`, `url_query`,'
        . ' `referrer_path`, `referrer_query`, `referrer_domain`, `page_title`, `hostname`,'
        . ' `event_type`, `event_name`, `created_at`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'\', NOW())');
    $ev->execute(array(
        $eid, zan_website_id(), $session, $visit, $path, substr((string) $query, 0, 255),
        $refPath, $refQuery, $refDomain, substr((string) $title, 0, 255), substr((string) $hostname, 0, 100),
    ));

    return array('session' => $session, 'visit' => $visit, 'event_id' => $eid);
}

/* ---------------------------------------------------------------------------
 * 查询
 * ------------------------------------------------------------------------- */

/** uv = 界面显示访客数；pv = 界面显示浏览次数 */
function zan_view_mode()
{
    $m = strtolower((string) zan_cfg('view_mode', 'uv'));
    return $m === 'pv' ? 'pv' : 'uv';
}

function zan_views_on()
{
    return (bool) zan_cfg('view_on', true);
}

/**
 * 哪些请求不算阅读：预取/预渲染，以及爬虫、抓取器、命令行。
 * 返回空串表示可以计数。
 */
function zan_view_skip_reason()
{
    $purpose = '';
    foreach (array('HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ') as $k) {
        if (!empty($_SERVER[$k])) {
            $purpose .= ' ' . $_SERVER[$k];
        }
    }
    if ($purpose !== '' && preg_match('/prefetch|prerender|preview/i', $purpose)) {
        return 'prefetch';
    }

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
    if ($ua === '') {
        return 'bot';
    }
    if (zan_is_bot($ua)) {
        return 'bot';
    }
    return '';
}

/**
 * 爬虫 / 抓取器识别（参照 isbot 的覆盖面，宁松勿漏：
 * 万一误判，代价只是这条记录不计入，页面照常显示数字）。
 */
function zan_is_bot($ua)
{
    static $re = null;
    if ($re === null) {
        $re = '/bot[\s\/;\)]|crawl|spider|slurp|archiver|scrap(?:er|ing)|fetcher|feedreader|rss|'
            . 'headless|phantom|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|'
            . 'monitor|checker|validator|'
            . 'curl\/|wget|libwww|httpclient|python-requests|python-urllib|aiohttp|httpx|'
            . 'go-http-client|okhttp|java\/|node-fetch|axios|postmanruntime|insomnia|'
            . 'bytespider|petalbot|gptbot|chatgpt|claudebot|anthropic|ccbot|perplexity|youbot|cohere|'
            . 'googlebot|google-inspectiontool|mediapartners|adsbot|bingbot|bingpreview|yandex|baiduspider|'
            . 'sogou|so-?360|exabot|ahrefs|semrush|mj12|dotbot|dataforseo|screaming|deepcrawl|sitebulb|'
            . 'facebookexternalhit|twitterbot|linkedinbot|telegrambot|whatsapp|discordbot|slackbot|applebot|'
            . 'pinterest|vkshare|embedly|quora|outbrain|redditbot|tumblr|bitlybot|'
            . 'coccoc|seznam|qwantify|duckduckbot|ia_archiver|archive\.org|commoncrawl/i';
    }
    return (bool) preg_match($re, $ua);
}

/** 一张页面的三个数，与 Umami 报表同名 */
function zan_page_stat_cols()
{
    return 'COUNT(*) AS pv, COUNT(DISTINCT `session_id`) AS uv, COUNT(DISTINCT `visit_id`) AS visits';
}

function zan_view_display($pv, $uv)
{
    return zan_view_mode() === 'pv' ? (int) $pv : (int) $uv;
}

/** 单页在某个时间窗内的 pv / 访客 / 访问次数 */
function zan_page_stats(PDO $pdo, $key, $from = null, $to = null)
{
    $out = array('pv' => 0, 'uv' => 0, 'visits' => 0);
    if (!zan_stats_ready()) {
        return $out;
    }
    try {
        $sql = 'SELECT ' . zan_page_stat_cols() . ' FROM ' . zan_table('event')
            . ' WHERE `url_path` = ? AND `event_type` = 1';
        $args = array($key);
        if ($from !== null && $to !== null) {
            $sql .= ' AND `created_at` BETWEEN ? AND ?';
            $args[] = $from;
            $args[] = $to;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch();
        if ($row) {
            $out = array('pv' => (int) $row['pv'], 'uv' => (int) $row['uv'], 'visits' => (int) $row['visits']);
        }
    } catch (PDOException $e) {
    }
    return $out;
}

/**
 * 全站指标：先按「会话 + 访问」分组，再求和 —— 与 Umami getWebsiteStats 的
 * 子查询完全一致，跳出与停留都基于同一份分组，不会互相放大。
 */
function zan_site_stats(PDO $pdo, $from, $to)
{
    $out = array('pv' => 0, 'uv' => 0, 'visits' => 0, 'bounces' => 0, 'totaltime' => 0);
    if (!zan_stats_ready()) {
        return $out;
    }
    try {
        $sql = 'SELECT COALESCE(SUM(t.c), 0) AS pv,'
            . ' COUNT(DISTINCT t.session_id) AS uv,'
            . ' COUNT(DISTINCT t.visit_id) AS visits,'
            . ' COALESCE(SUM(CASE WHEN t.c = 1 THEN 1 ELSE 0 END), 0) AS bounces,'
            . ' COALESCE(SUM(TIMESTAMPDIFF(SECOND, t.min_time, t.max_time)), 0) AS totaltime'
            . ' FROM (SELECT `session_id`, `visit_id`, COUNT(*) AS c,'
            . ' MIN(`created_at`) AS min_time, MAX(`created_at`) AS max_time'
            . ' FROM ' . zan_table('event') . ' WHERE `created_at` BETWEEN ? AND ? AND `event_type` = 1'
            . ' GROUP BY `session_id`, `visit_id`) t';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($from, $to));
        $row = $stmt->fetch();
        if ($row) {
            foreach ($out as $k => $v) {
                $out[$k] = (int) $row[$k];
            }
        }
    } catch (PDOException $e) {
    }
    return $out;
}

/** 按天出趋势，缺的日子由调用方补 0 */
function zan_stats_series(PDO $pdo, $from, $to)
{
    $out = array();
    if (!zan_stats_ready()) {
        return $out;
    }
    try {
        $stmt = $pdo->prepare('SELECT DATE(`created_at`) AS d, COUNT(*) AS pv,'
            . ' COUNT(DISTINCT `session_id`) AS uv, COUNT(DISTINCT `visit_id`) AS visits'
            . ' FROM ' . zan_table('event')
            . ' WHERE `created_at` BETWEEN ? AND ? AND `event_type` = 1'
            . ' GROUP BY DATE(`created_at`)');
        $stmt->execute(array($from, $to));
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['d']] = array('pv' => (int) $row['pv'], 'uv' => (int) $row['uv'], 'visits' => (int) $row['visits']);
        }
    } catch (PDOException $e) {
    }
    return $out;
}

/**
 * 排行：页面 / 来源直接读事件表，浏览器等属性要 join session。
 * $field 是白名单，避免把列名拼进 SQL。
 */
function zan_stats_breakdown(PDO $pdo, $field, $from, $to, $limit = 8)
{
    $allowed = array('url_path', 'referrer_domain', 'browser', 'os', 'device', 'language', 'country');
    if (!in_array($field, $allowed, true) || !zan_stats_ready()) {
        return array();
    }

    $limit = max(1, min(50, (int) $limit));
    $join  = in_array($field, array('browser', 'os', 'device', 'language', 'country'), true);
    $col   = $join
        ? "COALESCE(NULLIF(s.`" . $field . "`, ''), '未知')"
        : 'e.`' . $field . '`';

    $sql = 'SELECT ' . $col . ' AS label, COUNT(*) AS pv, COUNT(DISTINCT e.`session_id`) AS uv'
        . ' FROM ' . zan_table('event') . ' e';
    if ($join) {
        $sql .= ' LEFT JOIN ' . zan_table('session') . ' s ON s.`session_id` = e.`session_id`';
    }
    $sql .= ' WHERE e.`created_at` BETWEEN ? AND ? AND e.`event_type` = 1';
    if ($field === 'referrer_domain') {
        $sql .= " AND e.`referrer_domain` <> ''";
    }
    $sql .= ' GROUP BY ' . $col
        . ' ORDER BY uv DESC, pv DESC LIMIT ' . $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($from, $to));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return array();
    }
}

/** 最近 N 分钟还在看的人（Umami 的实时访客） */
function zan_stats_active(PDO $pdo, $minutes = 5)
{
    if (!zan_stats_ready()) {
        return 0;
    }
    $minutes = max(1, min(60, (int) $minutes));
    try {
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT `session_id`) FROM ' . zan_table('event')
            . ' WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** 老站升级后可能还没有 view 表，读数为 0 而不是报错 */
function zan_has_view_table(PDO $pdo)
{
    $schema = zan_stats_state($pdo);
    return $schema['legacy'];
}

/* ---------------------------------------------------------------------------
 * 接口输出
 * ------------------------------------------------------------------------- */

/**
 * 跨域头。
 *
 * $public = true 用于只读接口（query / site）：返回的只有公开计数，不带 Cookie、
 * 不含访客信息，任何站点读都一样，所以直接给 *。这样博客换域名、本地预览、
 * 或者后台白名单漏配，页面上那几个数字都不会因为 CORS 读不回来而消失。
 *
 * 写接口（like / pageview）仍走白名单：被拦下时浏览器只是读不到响应，
 * 请求本身照样送达，计数不受影响；但能挡住别的站点在你的访客浏览器里悄悄伪造点赞
 * 并读到结果。
 */
function zan_cors($public = false)
{
    $origin  = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
    $allowed = (array) zan_cfg('allowed_origins', array());
    $allowed = array_values(array_filter(array_map('trim', $allowed)));

    if ($public || $origin === '') {
        header('Access-Control-Allow-Origin: *');
    } elseif (!$allowed || in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

/**
 * 业务错误一律 HTTP 200 + code：nginx 可能把 4xx/5xx 换成 HTML 错误页，
 * 前端 JSON.parse 会直接失败。只有脚本自身异常才用 500。
 */
function zan_json($code, $message, $data = null, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    $out = array('code' => (int) $code, 'message' => (string) $message, 'ver' => ZAN_VERSION);
    if ($data !== null) {
        $out['data'] = $data;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------------------------------------------------------------------
 * 后台会话与鉴权
 * ------------------------------------------------------------------------- */

function zan_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    @ini_set('session.use_strict_mode', '1');
    $path = zan_base_path();
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => ($path === '' ? '/' : $path . '/'),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ));
    session_name('zansid');
    session_start();
}

function zan_admin()
{
    zan_session_start();
    if (empty($_SESSION['zan_admin_id'])) {
        return null;
    }
    return array(
        'id'    => (int) $_SESSION['zan_admin_id'],
        'email' => isset($_SESSION['zan_admin_email']) ? (string) $_SESSION['zan_admin_email'] : '',
    );
}

function zan_require_admin()
{
    $admin = zan_admin();
    if ($admin === null) {
        header('Location: ' . zan_url('admin/login.php'));
        exit;
    }
    return $admin;
}

function zan_csrf()
{
    zan_session_start();
    if (empty($_SESSION['zan_nonce']) || !is_string($_SESSION['zan_nonce'])) {
        $_SESSION['zan_nonce'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['zan_nonce'];
}

function zan_csrf_field()
{
    return '<input type="hidden" name="nonce" value="' . zan_h(zan_csrf()) . '" />';
}

function zan_csrf_ok($sent)
{
    zan_session_start();
    $cur = isset($_SESSION['zan_nonce']) ? (string) $_SESSION['zan_nonce'] : '';
    return $cur !== '' && is_string($sent) && hash_equals($cur, $sent);
}

/** 登录成功后换一把钥匙，避免会话固定 */
function zan_csrf_rotate()
{
    zan_session_start();
    $_SESSION['zan_nonce'] = bin2hex(random_bytes(16));
}

/* ---------------------------------------------------------------------------
 * 后台页面框架
 * ------------------------------------------------------------------------- */

/**
 * 有新版本时返回版本号（没有则空串）。
 *
 * 只读 data/update.json，不加载更新器 —— 侧栏每个页面都要问一次，别为此多解析几千行 PHP。
 */
function zan_update_hint()
{
    $f = ZAN_ROOT . 'data' . DIRECTORY_SEPARATOR . 'update.json';
    if (!is_file($f)) {
        return '';
    }
    $s = json_decode((string) @file_get_contents($f), true);
    if (!is_array($s) || empty($s['remote']['sha'])) {
        return '';
    }
    $sha     = (string) $s['remote']['sha'];
    $applied = isset($s['applied_sha']) ? (string) $s['applied_sha'] : '';
    if ($applied === $sha) {
        return '';
    }
    $ver = isset($s['remote']['version']) ? (string) $s['remote']['version'] : '';
    if ($ver !== '' && version_compare($ver, ZAN_VERSION, '>')) {
        return $ver;
    }
    /* 版本号没变但确实推了新代码：只有更新过（有 applied_sha）才提示，
       否则本地开发中的版本永远挂着红点 */
    if ($applied !== '') {
        return $ver !== '' ? $ver : 'new';
    }
    return '';
}

/** 自动检查是否到点（纯本地判断，给页面里的静默检查用） */
function zan_update_auto_due()
{
    if ((string) zan_cfg('update_auto_check', '1') !== '1') {
        return false;
    }
    /* 还没填仓库地址，查了也是白查 */
    if (trim((string) zan_cfg('update_repo', '')) === '') {
        return false;
    }
    $f    = ZAN_ROOT . 'data' . DIRECTORY_SEPARATOR . 'update.json';
    $last = 0;
    if (is_file($f)) {
        $s = json_decode((string) @file_get_contents($f), true);
        if (is_array($s) && !empty($s['checked_at'])) {
            $last = (int) $s['checked_at'];
        }
    }
    $iv = max(1, min(168, (int) zan_cfg('update_interval', '6'))) * 3600;
    return $last === 0 || (time() - $last) >= $iv;
}

function zan_admin_header($title, $active)
{
    /* 侧栏菜单：文件名 => array(名称, 一句话说明, 内联图标)
     * 图标统一 24 视图框、线性描边，尺寸与颜色交给 CSS（.za-nav svg）。
     * 顺序即侧栏顺序：天天看的在上，配置类沉到底部。 */
    $items = array(
        'index.php'       => array(
            '仪表盘', '今天的点赞与浏览',
            '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
        ),
        'stats.php'       => array(
            '网站统计', '访问趋势、来源与访客',
            '<path d="M4.5 19.5V12M9.8 19.5V6M15.1 19.5v-5M20.4 19.5V9"/><path d="M3 19.5h18"/>',
        ),
        'list.php'        => array(
            '点赞统计', '每篇文章收到多少赞',
            '<path d="M12 20.2S4.6 15.7 4.6 10.6A4.2 4.2 0 0 1 12 8a4.2 4.2 0 0 1 7.4 2.6c0 5.1-7.4 9.6-7.4 9.6z"/>',
        ),
        'maintenance.php' => array(
            '数据维护', '备份、恢复与清理',
            '<ellipse cx="12" cy="6.2" rx="7.4" ry="2.9"/><path d="M4.6 6.2v5.6c0 1.6 3.3 2.9 7.4 2.9s7.4-1.3 7.4-2.9V6.2"/><path d="M4.6 11.8v5.6c0 1.6 3.3 2.9 7.4 2.9s7.4-1.3 7.4-2.9v-5.6"/>',
        ),
        'update.php'      => array(
            '系统更新', '从 GitHub 装新版本',
            '<path d="M7.3 17.5a4 4 0 0 1-.4-7.98 5.5 5.5 0 0 1 10.66-.7A3.7 3.7 0 0 1 17.8 17.5"/><path d="M12 11.2v6.2"/><path d="M9.7 15 12 17.3 14.3 15"/>',
        ),
        'settings.php'    => array(
            '系统设置', '站点信息、统计口径与访问控制',
            '<path d="M4 7.5h7M15.5 7.5h4.5M4 16.5h4M12.5 16.5h7.5"/><circle cx="13" cy="7.5" r="2.2"/><circle cx="10" cy="16.5" r="2.2"/>',
        ),
    );
    $ver   = ZAN_VERSION;
    $hint  = zan_update_hint();
    $admin = zan_admin();
    $who   = ($admin !== null && $admin['email'] !== '') ? $admin['email'] : '管理员';
    $sub   = isset($items[$active][1]) ? $items[$active][1] : '';
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title><?php echo zan_h($title); ?> - <?php echo zan_h(zan_site_name()); ?></title>
<link rel="stylesheet" href="<?php echo zan_h(zan_url('assets/admin.css')); ?>?v=<?php echo zan_h($ver); ?>" />
</head>
<body class="za">
<aside class="za-side" id="zaSide">
    <div class="za-brand">
        <span class="za-logo" aria-hidden="true">S</span>
        <div class="za-brand-text">
            <b><?php echo zan_h(zan_site_name()); ?></b>
            <em><?php echo zan_h(ZAN_BRAND); ?> v<?php echo zan_h($ver); ?></em>
        </div>
    </div>
    <nav class="za-nav">
    <?php foreach ($items as $file => $meta) : ?>
        <a href="<?php echo zan_h(zan_url('admin/' . $file)); ?>" class="<?php echo $active === $file ? 'is-active' : ''; ?>"<?php echo $file === 'update.php' ? ' data-nav="update"' : ''; ?> title="<?php echo zan_h($meta[1]); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $meta[2]; ?></svg>
            <span><?php echo zan_h($meta[0]); ?></span>
            <?php if ($file === 'update.php' && $hint !== '') : ?><i class="za-dot" title="有新版本"></i><?php endif; ?>
        </a>
    <?php endforeach; ?>
    </nav>
    <div class="za-side-foot">
        <div class="za-side-tip">
            <b><?php echo zan_h(ZAN_TAGLINE); ?></b>
            <span>点赞只在内容页，统计覆盖全站</span>
        </div>
        <a class="za-logout" href="<?php echo zan_h(zan_url('admin/logout.php')); ?>">退出登录</a>
    </div>
</aside>
<main class="za-main">
    <header class="za-top">
        <button class="za-menu-btn" type="button" aria-label="展开菜单"
                onclick="document.getElementById('zaSide').classList.toggle('is-open')">☰</button>
        <div class="za-heading">
            <h1><?php echo zan_h($title); ?></h1>
            <?php if ($sub !== '') : ?><div class="za-page-sub"><?php echo zan_h($sub); ?></div><?php endif; ?>
        </div>
        <div class="za-top-actions">
            <?php if ($hint !== '') : ?>
            <a class="za-top-ver" href="<?php echo zan_h(zan_url('admin/update.php')); ?>" title="有新版本，去系统更新查看">
                发现新版本<?php echo $hint !== 'new' ? ' ' . zan_h($hint) : ''; ?>
            </a>
            <?php endif; ?>
            <a class="za-top-link" href="<?php echo zan_h(zan_url('index.php')); ?>" target="_blank" rel="noopener">访问站点 ↗</a>
            <span class="za-user" title="当前登录账号"><?php echo zan_h($who); ?></span>
        </div>
    </header>
    <div class="za-body">
    <?php
}

function zan_admin_footer()
{
    /* 静默检查更新：只在到点时、且不在更新页上跑，不阻塞页面渲染 */
    $self = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';
    if ($self !== 'update.php' && zan_update_auto_due()) {
        ?>
<script>
(function () {
    var body = new URLSearchParams();
    body.append('action', 'auto');
    body.append('nonce', <?php echo json_encode(zan_csrf(), JSON_UNESCAPED_SLASHES); ?>);
    fetch(<?php echo json_encode(zan_url('admin/update.php'), JSON_UNESCAPED_SLASHES); ?>, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.has_update) { return; }
        var nav = document.querySelector('.za-nav a[data-nav="update"]');
        if (nav && !nav.querySelector('.za-dot')) {
            var dot = document.createElement('i');
            dot.className = 'za-dot';
            dot.title = '有新版本';
            nav.appendChild(dot);
        }
    }).catch(function () {});
})();
</script>
        <?php
    }
    ?>
    </div>
</main>
</body>
</html>
    <?php
}

/** 后台提示条（PRG 之后显示一次） */
function zan_notice($text, $type = 'ok')
{
    if ($text === '') {
        return;
    }
    echo '<div class="za-notice is-' . zan_h($type) . '">' . $text . '</div>';
}
