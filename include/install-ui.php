<?php
/**
 * 安装程序公共层：页面框架、环境自检、配置读写、错误页。
 */
if (!defined('ZAN_INSTALL_UI')) {
    http_response_code(403);
    exit('Forbidden');
}
if (!defined('ZAN_APP')) {
    define('ZAN_APP', true);
}

require_once __DIR__ . '/defs.php';

function zan_config_exists()
{
    return is_file(ZAN_ROOT . 'config.php');
}

function zan_asset($path)
{
    return '../assets/' . ltrim($path, '/');
}

function zan_asset_ver($path)
{
    $file = ZAN_ROOT . 'assets/' . ltrim($path, '/');
    return is_file($file) ? (string) filemtime($file) : ZAN_VERSION;
}

function zan_install_session()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    @ini_set('session.use_strict_mode', '1');
    $dir = rtrim(str_replace('\\', '/', dirname((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'))), '/');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => ($dir === '' ? '/' : $dir . '/'),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ));
    session_name('zansid');
    session_start();
}

function zan_install_nonce()
{
    zan_install_session();
    if (empty($_SESSION['zan_install_nonce'])) {
        $_SESSION['zan_install_nonce'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['zan_install_nonce'];
}

function zan_install_nonce_ok($sent)
{
    zan_install_session();
    $cur = isset($_SESSION['zan_install_nonce']) ? (string) $_SESSION['zan_install_nonce'] : '';
    unset($_SESSION['zan_install_nonce']);
    return $cur !== '' && is_string($sent) && hash_equals($cur, $sent);
}

/**
 * 环境自检：不需要数据库就能判断的项。
 * level = required 的任一项不合格就拦下来，optional 只提示。
 */
function zan_env_check()
{
    $writable = is_writable(ZAN_ROOT);
    return array(
        array('label' => 'PHP 版本', 'need' => '≥ ' . ZAN_REQ_PHP, 'actual' => PHP_VERSION,
            'level' => 'required', 'ok' => version_compare(PHP_VERSION, ZAN_REQ_PHP, '>=')),
        array('label' => 'PDO 扩展', 'need' => '已启用', 'actual' => extension_loaded('pdo') ? '已启用' : '未启用',
            'level' => 'required', 'ok' => extension_loaded('pdo')),
        array('label' => 'PDO MySQL 驱动', 'need' => '已启用', 'actual' => extension_loaded('pdo_mysql') ? '已启用' : '未启用',
            'level' => 'required', 'ok' => extension_loaded('pdo_mysql')),
        array('label' => 'mbstring 扩展', 'need' => '已启用', 'actual' => extension_loaded('mbstring') ? '已启用' : '未启用',
            'level' => 'required', 'ok' => extension_loaded('mbstring')),
        array('label' => 'JSON 扩展', 'need' => '已启用', 'actual' => extension_loaded('json') ? '已启用' : '未启用',
            'level' => 'required', 'ok' => extension_loaded('json')),
        array('label' => '程序目录可写', 'need' => '可写（用于生成 config.php）', 'actual' => $writable ? '可写' : '不可写',
            'level' => 'required', 'ok' => $writable),
    );
}

function zan_env_gate()
{
    $failed = array();
    foreach (zan_env_check() as $item) {
        if (!$item['ok'] && $item['level'] === 'required') {
            $failed[] = $item;
        }
    }
    if (!$failed) {
        return;
    }
    zan_install_header(ZAN_BRAND . ' › 环境检查');
    echo '<h1>未满足要求</h1><p>服务器还不能安装 ' . zan_h(ZAN_BRAND) . '，请先处理：</p><ul>';
    foreach ($failed as $item) {
        echo '<li><strong>' . zan_h($item['label']) . '</strong>：需要 ' . zan_h($item['need'])
            . '，当前 ' . zan_h($item['actual']) . '</li>';
    }
    echo '</ul><p>处理完重新打开本页即可继续。</p>';
    zan_install_footer();
    exit;
}

function zan_install_header($title)
{
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title><?php echo $title; ?></title>
<link rel="stylesheet" href="<?php echo zan_asset('style/install.css'); ?>?v=<?php echo zan_asset_ver('style/install.css'); ?>" />
</head>
<body class="ip">
<p class="ip-logo"><?php echo zan_h(ZAN_BRAND); ?></p>
<div class="ip-card">
    <?php
}

function zan_install_footer()
{
    ?>
</div>
<script src="<?php echo zan_asset('js/install.js'); ?>?v=<?php echo zan_asset_ver('js/install.js'); ?>"></script>
</body>
</html>
    <?php
}

function zan_install_die($html, $title = '出错了', $status = 500)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    zan_install_header(zan_h(ZAN_BRAND) . ' › ' . zan_h($title));
    echo '<h1>' . zan_h($title) . '</h1>' . $html;
    zan_install_footer();
    exit;
}

function zan_db_connect($host, $user, $pass, $dbname = null)
{
    $dsn = 'mysql:host=' . $host . ';charset=utf8mb4';
    if ($dbname !== null && $dbname !== '') {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4';
    }
    return new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));
}

function zan_db_select(PDO $pdo, $dbname)
{
    $pdo->exec('USE `' . str_replace('`', '', $dbname) . '`');
}

function zan_db_connect_error($host)
{
    zan_install_die(
        '<p>连不上数据库服务器 <code>' . zan_h($host) . '</code>。请确认主机名、用户名、密码正确，'
        . '并确认数据库服务正在运行。</p>'
        . '<p class="ip-step"><a class="ip-btn" href="setup-config.php?step=1" onclick="history.go(-1);return false;">返回修改</a></p>',
        '建立数据库连接时出错'
    );
}

function zan_db_select_error($dbname, $user)
{
    zan_install_die(
        '<p>能连上数据库服务器（说明账号密码是对的），但无法选择数据库 <code>' . zan_h($dbname) . '</code>。</p>'
        . '<ul><li>它存在吗？</li><li>用户 <code>' . zan_h($user) . '</code> 有权限使用它吗？</li>'
        . '<li>部分主机的库名前缀就是账号名，例如 <code>' . zan_h($user) . '_' . zan_h($dbname) . '</code>。</li></ul>'
        . '<p class="ip-step"><a class="ip-btn" href="setup-config.php?step=1" onclick="history.go(-1);return false;">返回修改</a></p>',
        '不能选择数据库'
    );
}

function zan_already_installed($loginUrl)
{
    zan_install_header(ZAN_BRAND . ' › 安装');
    echo '<h1>已经装过了</h1><p>' . zan_h(ZAN_BRAND) . ' 看起来已经安装妥当。要重装请先删除数据库里的旧数据表。</p>'
        . '<p class="ip-step"><a class="ip-btn" href="' . zan_h($loginUrl) . '">前往登录页</a></p>';
    zan_install_footer();
    exit;
}

/** 邮箱校验：比 filter_var 严一点，拦住 a@b.c、连续点这类地址 */
function zan_is_valid_email($email)
{
    $email = trim((string) $email);
    if ($email === '' || strlen($email) > 191 || strpos($email, '..') !== false) {
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $at = strrpos($email, '@');
    $local  = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if ($local === '' || strlen($local) > 64) {
        return false;
    }
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
        return false;
    }
    $tld = substr($domain, strrpos($domain, '.') + 1);
    return (bool) preg_match('/^[a-z]{2,63}$/i', $tld);
}
