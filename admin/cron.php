<?php
/**
 * 定时检查入口（给服务器计划任务用，不走登录）
 *
 * 用法：计划任务里填
 *   curl -s "https://你的域名/admin/cron.php?token=XXXXXXXX" >/dev/null
 * 宝塔 / 1Panel 的「访问 URL」任务可以直接粘贴，返回 JSON 方便看结果。
 *
 * 安全：token 存在 config.php（update_cron_token），由「系统更新」页生成、可一键重置。
 * token 不对一律 404，不暴露任何信息。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require dirname(__DIR__) . '/include/mailer.php';
require dirname(__DIR__) . '/include/updater.php';   /* 更新器自己会带上 zipreader */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$real  = (string) zan_cfg('update_cron_token', '');

/* 令牌不对就当这个地址不存在 */
if ($real === '' || $token === '' || !hash_equals($real, $token)) {
    http_response_code(404);
    echo json_encode(array('ok' => false), JSON_UNESCAPED_UNICODE);
    exit;
}

$r = zan_upd_auto_run(25, $err);

if ($r === false) {
    if (!zan_upd_auto_check()) {
        $why = '自动检查已关闭（后台「系统更新」里可以打开）';
    } elseif (zan_upd_repo() === '') {
        $why = '还没有填 GitHub 仓库地址';
    } else {
        $why = '还没到检查时间，或另一个进程正在检查';
    }
    echo json_encode(array(
        'ok'         => true,
        'ran'        => false,
        'has_update' => zan_upd_available_cached(),
        'msg'        => $why,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(array(
    'ok'         => !empty($r['ok']),
    'ran'        => true,
    'has_update' => !empty($r['has_update']),
    'applied'    => !empty($r['applied']),
    'notified'   => !empty($r['notified']),
    'current'    => ZAN_VERSION,
    'version'    => (string) $r['version'],
    'files'      => (int) $r['files'],
    'checked_at' => date('c'),
    'msg'        => (string) $err,
), JSON_UNESCAPED_UNICODE);
