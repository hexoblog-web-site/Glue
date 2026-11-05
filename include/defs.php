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
    define('ZAN_VERSION', '2.0.2');
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

/** HTML 转义 */
function zan_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
