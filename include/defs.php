<?php
/**
 * 全局常量与最小工具。
 * 所有 include/ 下的文件都靠 ZAN_APP 判断是否被直接访问。
 */
if (!defined('ZAN_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!defined('ZAN_VERSION')) {
    define('ZAN_VERSION', '2.0.6');
}
if (!defined('ZAN_BRAND')) {
    define('ZAN_BRAND', 'Sugar-Box');
}
if (!defined('ZAN_TAGLINE')) {
    define('ZAN_TAGLINE', '点赞 + 网站统计');
}
/* 程序根目录，带结尾斜杠 */
if (!defined('ZAN_ROOT')) {
    define('ZAN_ROOT', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
/* 最低 PHP 版本 */
if (!defined('ZAN_REQ_PHP')) {
    define('ZAN_REQ_PHP', '7.4');
}
/* 登录节流：同一 IP 在窗口内允许失败几次、锁多久（秒） */
if (!defined('ZAN_LOGIN_MAX_TRY')) {
    define('ZAN_LOGIN_MAX_TRY', 20);
}
if (!defined('ZAN_LOGIN_WINDOW')) {
    define('ZAN_LOGIN_WINDOW', 600);
}

/** HTML 转义 */
function zan_h($s)
{
    return htmlspecialchars($s === null ? '' : (string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * 从 $_POST / $_GET 取一个字符串。
 *
 * 参数可能是数组（?path[]=x）；直接 (string) 会得到 "Array"，trim() 在 PHP 8 下还会抛
 * TypeError 变成 500。凡是把请求参数往函数里传的地方都走这两个函数。
 */
function zan_post_str($key, $default = '')
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default;
}

function zan_get_str($key, $default = '')
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $default;
}
