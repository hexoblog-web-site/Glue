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
    /* 容器里配置放在数据卷上，用环境变量指过去；普通部署还是程序根目录 */
    $env = getenv('ZAN_CONFIG_FILE');
    if ($env !== false && $env !== '') {
        return $env;
    }
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

    /* 先写临时文件再 rename：这个文件一坏，整站 500。
       直接覆盖写的时候哪怕断电一次，config.php 就只剩半截 PHP。 */
    $file = zan_config_file();
    $tmp  = $file . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0644);
    /* opcache 会把 config.php 里的数组编译进缓存，不失效的话下一个请求还读旧值 */
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    return true;
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
    $port = trim((string) zan_cfg('db_port', ''));
    $dsn = 'mysql:host=' . zan_cfg('db_host', 'localhost')
        . ($port !== '' ? ';port=' . $port : '')
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

/**
 * 当前请求是不是 HTTPS。
 *
 * 容器部署时 TLS 在宿主机 Nginx 上终结，容器里看到的永远是 http，
 * 所以必须同时认 X-Forwarded-Proto，否则 Cookie 不会带 Secure、生成的接入地址会是 http://。
 */
function zan_is_https()
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        /* 多级代理时形如 "https, http"，只认第一段 */
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
        if (strtolower(trim($parts[0])) === 'https') {
            return true;
        }
    }
    return false;
}

/** 站点根地址（含协议与主机），用于生成接入代码 */
function zan_abs_url($path = '')
{
    $https  = zan_is_https();
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
 * 这一层挡的是「页面用错容器」和「随手拼请求」，不构成安全边界；
 * 真正的防刷仍靠访客去重与限流。
 */
function zan_content_guard($key)
{
    /* 参数可能是数组（?path[]=x）：PHP 会把数组转成 "Array"，直接 trim 还会在 8.x 抛 TypeError */
    $path  = zan_norm_key(zan_post_str('path'));
    $scope = zan_post_str('scope');

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
 */
function zan_visitor_hash($vid)
{
    $secret = zan_like_secret();
    if (zan_vid_ok($vid)) {
        return hash('sha256', 'v1|' . $vid . '|' . $secret);
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300) : '';
    return hash('sha256', 'v1|' . zan_client_ip() . '|' . $ua . '|' . $secret);
}

/** 点赞去重用的密钥：安装时生成的 site_secret */
function zan_like_secret()
{
    $s = (string) zan_cfg('site_secret', '');
    if ($s !== '') {
        return $s;
    }
    /* 手工改过 config.php 把 site_secret 弄丢了：用配置路径兜底，
       同一个站点永远算出同一个值，总比空串让 IP+UA 可被反推要好 */
    return hash('sha256', 'zan-fallback|' . zan_config_file());
}

/* ---------------------------------------------------------------------------
 * 后台排行
 * ------------------------------------------------------------------------- */

/**
 * 页面排行用的两个列表达式：赞数 / 最近一次点赞。
 *
 * admin/index.php 和 admin/list.php 都要这份排行，SQL 各写一遍迟早跑偏。
 * 一律用子查询而不是 JOIN：log 与 page 是一对多，JOIN 到一起会放大计数。
 *
 * @return array<string,string> 可直接拼进 SELECT 的表达式
 */
function zan_page_rank_cols($alias = 'p')
{
    $alias = preg_replace('/[^a-z]/i', '', (string) $alias);
    if ($alias === '') {
        $alias = 'p';
    }
    $a   = $alias . '.';
    $log = zan_table('log');

    return array(
        'likes'   => '(SELECT COUNT(*) FROM ' . $log . ' l WHERE l.`page_key` = ' . $a . '`page_key`)',
        'last_at' => '(SELECT MAX(l.`created_at`) FROM ' . $log . ' l WHERE l.`page_key` = ' . $a . '`page_key`)',
    );
}

/* ---------------------------------------------------------------------------
 * 接口输出
 * ------------------------------------------------------------------------- */

/**
 * 归一化一个 Origin 或白名单项。
 *
 * 浏览器发来的 Origin 永远是 `scheme://host[:port]`，不带路径、不带结尾斜杠；
 * 而白名单是手填的，从地址栏复制就会多一个 `/`，只写裸域名也很常见。
 * 直接用 in_array 精确比对，这几种写法一个都匹配不上，而且失败是静默的：
 * 请求照样送达服务端、数据照样写入，只是浏览器读不到响应 ——
 * 前端于是报「网络错误」，用户刷新却发现计数已经 +1。
 */
function zan_origin_norm($origin)
{
    $o = strtolower(trim((string) $origin));
    $o = rtrim($o, '/');
    /* 只写了域名：按 https 补全 */
    if ($o !== '' && strpos($o, '://') === false) {
        $o = 'https://' . $o;
    }
    /* 默认端口不参与比较 */
    $o = preg_replace('#:(80|443)$#', '', $o);
    return $o;
}

/** 取 scheme 之后的部分，用来忽略 http / https 的差异 */
function zan_origin_host($origin)
{
    return preg_replace('#^[a-z][a-z0-9+.-]*://#', '', zan_origin_norm($origin));
}

/** Origin 是否命中白名单（容忍尾斜杠、大小写、裸域名、默认端口） */
function zan_cors_match($origin, array $allowed)
{
    $o = zan_origin_norm($origin);
    if ($o === '') {
        return false;
    }
    $host = zan_origin_host($origin);
    foreach ($allowed as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        if ($item === '*') {
            return true;
        }
        if (zan_origin_norm($item) === $o) {
            return true;
        }
        /* 白名单写的是裸域名时，http 与 https 都放行 */
        if (strpos($item, '://') === false && zan_origin_host($item) === $host) {
            return true;
        }
    }
    return false;
}

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
    } elseif (!$allowed || zan_cors_match($origin, $allowed)) {
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
        'secure'   => zan_is_https(),
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
 * 登录节流
 *
 * 只把失败次数记在 session 里是没用的：攻击者每次丢掉 Cookie 就从 0 开始，
 * 8 次限制形同虚设。这里按 IP 再记一份，落在 data/login-throttle.json。
 * 文件不可写时静默降级（退回 session 计数），不让登录功能因为磁盘权限挂掉。
 * ------------------------------------------------------------------------- */

function zan_login_throttle_file()
{
    return rtrim(ZAN_DATA_DIR, '/\\') . '/login-throttle.json';
}

function zan_login_throttle_read()
{
    $f = zan_login_throttle_file();
    if (!is_file($f)) {
        return array();
    }
    $j = json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : array();
}

function zan_login_throttle_write(array $all)
{
    $f = zan_login_throttle_file();
    if (!is_dir(dirname($f))) {
        return false;
    }
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($all, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** 还要等多少秒才能再试；0 表示没锁 */
function zan_login_throttle_left()
{
    $all = zan_login_throttle_read();
    $ip  = zan_client_ip();
    if (empty($all[$ip]) || !is_array($all[$ip])) {
        return 0;
    }
    $count = isset($all[$ip]['n']) ? (int) $all[$ip]['n'] : 0;
    $last  = isset($all[$ip]['t']) ? (int) $all[$ip]['t'] : 0;
    if ($count < ZAN_LOGIN_MAX_TRY) {
        return 0;
    }
    $left = ZAN_LOGIN_WINDOW - (time() - $last);
    return $left > 0 ? $left : 0;
}

function zan_login_throttle_hit()
{
    $ip  = zan_client_ip();
    $now = time();
    $all = zan_login_throttle_read();

    /* 顺手清掉一天前的记录，文件不会越滚越大 */
    foreach ($all as $k => $v) {
        if (!is_array($v) || ($now - (int) (isset($v['t']) ? $v['t'] : 0)) > 86400) {
            unset($all[$k]);
        }
    }

    $n = isset($all[$ip]['n']) ? (int) $all[$ip]['n'] : 0;
    $t = isset($all[$ip]['t']) ? (int) $all[$ip]['t'] : 0;
    if ($now - $t > ZAN_LOGIN_WINDOW) {
        $n = 0;   // 窗口过了，重新计数
    }
    $all[$ip] = array('n' => $n + 1, 't' => $now);
    zan_login_throttle_write($all);
}

function zan_login_throttle_clear()
{
    $ip  = zan_client_ip();
    $all = zan_login_throttle_read();
    if (isset($all[$ip])) {
        unset($all[$ip]);
        zan_login_throttle_write($all);
    }
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
    $f = rtrim(ZAN_DATA_DIR, '/\\') . DIRECTORY_SEPARATOR . 'update.json';
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
    $f    = rtrim(ZAN_DATA_DIR, '/\\') . DIRECTORY_SEPARATOR . 'update.json';
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
    /* 页面清单：文件名 => array(名称, 一句话说明)
     * 名称用于侧栏二级项与页面标题，说明用于顶栏副标题；
     * 一级项和分组图标不在这里，见下面的 $groups。 */
    $items = array(
        'index.php'       => array('仪表盘', '今天的点赞概况'),
        'list.php'        => array('点赞统计', '每篇文章收到多少赞'),
        'maintenance.php' => array('数据备份', '备份、恢复与清理'),
        'settings.php'    => array('系统设置', '站点信息与访问控制'),
        'update.php'      => array('系统更新', '从 GitHub 装新版本'),
    );

    /* 导航：仪表盘单列，其余按「数据 / 系统」两组折叠。
     * 组定义是 键 => array(组名, 组图标, 组内文件)，顺序即渲染顺序。
     * 图标用实心填充（fill 由 CSS 控制），比细线条在小尺寸下更压得住。 */
    $groups = array(
        'data' => array(
            '数据',
            '<path d="M12 20.6c-.3 0-.6-.1-.8-.3C8.6 18.1 2.8 13.6 2.8 9.3 2.8 6.4 5 4 7.9 4c1.7 0 3.2.9 4.1 2.3C12.9 4.9 14.4 4 16.1 4c2.9 0 5.1 2.4 5.1 5.3 0 4.3-5.8 8.8-8.4 11-.2.2-.5.3-.8.3z"/>',
            array('list.php', 'maintenance.php'),
        ),
        'sys'  => array(
            '系统',
            '<path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>',
            array('settings.php', 'update.php'),
        ),
    );
    /* 仪表盘图标（首页）与折叠箭头，两个都只有一处用，放局部变量即可 */
    $icoHome  = '<path d="M11.4 3.2a1 1 0 0 1 1.2 0l8 6a1 1 0 0 1 .4.8V20a1 1 0 0 1-1 1h-5.2v-5.6H9.2V21H4a1 1 0 0 1-1-1V10a1 1 0 0 1 .4-.8z"/>';
    $icoArrow = '<path d="M7.4 9.4a1.1 1.1 0 0 1 1.6 0L12 12.5l3-3.1a1.1 1.1 0 1 1 1.6 1.5l-3.8 3.9a1.1 1.1 0 0 1-1.6 0L7.4 10.9a1.1 1.1 0 0 1 0-1.5z"/>';

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
            <em>v<?php echo zan_h($ver); ?></em>
        </div>
    </div>
    <nav class="za-nav">
        <a class="za-item<?php echo $active === 'index.php' ? ' is-active' : ''; ?>"
           href="<?php echo zan_h(zan_url('admin/index.php')); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $icoHome; ?></svg>
            <span>仪表盘</span>
        </a>
    <?php foreach ($groups as $gkey => $g) :
        $isOpen  = in_array($active, $g[2], true);
        $hasDot  = ($gkey === 'sys' && $hint !== '');
        ?>
        <div class="za-group<?php echo $isOpen ? ' is-open' : ''; ?>" data-group="<?php echo zan_h($gkey); ?>">
            <button class="za-item za-item-toggle" type="button"
                    aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $g[1]; ?></svg>
                <span><?php echo zan_h($g[0]); ?></span>
                <?php if ($hasDot) : ?><i class="za-dot" title="有新版本"></i><?php endif; ?>
                <svg class="za-arrow" viewBox="0 0 24 24" aria-hidden="true"><?php echo $icoArrow; ?></svg>
            </button>
            <div class="za-sub"><div>
            <?php foreach ($g[2] as $file) : ?>
                <?php if (!isset($items[$file])) { continue; } ?>
                <a href="<?php echo zan_h(zan_url('admin/' . $file)); ?>"
                   class="<?php echo $active === $file ? 'is-active' : ''; ?>"><?php echo zan_h($items[$file][0]); ?></a>
            <?php endforeach; ?>
            </div></div>
        </div>
    <?php endforeach; ?>
    </nav>
    <div class="za-side-foot">
        <a class="za-logout" href="<?php echo zan_h(zan_url('admin/logout.php?nonce=' . zan_csrf())); ?>">退出登录</a>
    </div>
</aside>
<script>
(function () {
    var side = document.getElementById('zaSide');
    if (!side) { return; }
    var groups = [].slice.call(side.querySelectorAll('.za-group'));
    var KEY = 'zan_nav_open', saved = null;
    try { saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { saved = null; }
    groups.forEach(function (g) {
        var k = g.getAttribute('data-group');
        if (saved && typeof saved[k] === 'boolean' && !g.querySelector('.za-sub a.is-active')) {
            g.classList.toggle('is-open', saved[k]);
        }
        var btn = g.querySelector('.za-item-toggle');
        if (!btn) { return; }
        btn.setAttribute('aria-expanded', g.classList.contains('is-open') ? 'true' : 'false');
        btn.addEventListener('click', function () {
            g.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', g.classList.contains('is-open') ? 'true' : 'false');
            var st = {};
            groups.forEach(function (x) { st[x.getAttribute('data-group')] = x.classList.contains('is-open'); });
            try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {}
        });
    });
})();
</script>
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
