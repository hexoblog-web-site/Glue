<?php
/**
 * 退出登录。
 *
 * 用 GET 是为了侧栏里一个链接就能点；但链接必须带一次性 nonce，
 * 否则别的站点放一张 <img src=".../logout.php"> 就能把人踢下线。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';

zan_session_start();

$nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
if (!zan_csrf_ok($nonce)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('链接已失效，请回到后台重新点「退出登录」。');
}

$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: ' . zan_url('admin/login.php'));
exit;
