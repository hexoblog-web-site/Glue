<?php
/**
 * 后端根地址：健康检查。
 *
 * 未安装 → 进安装向导
 * 已安装 → 返回一行 JSON（?format=html 是可视化状态页）
 * 后台入口在 admin/login.php，不在这里。
 */
define('ZAN_APP', true);
require __DIR__ . '/include/common.php';

if (!zan_installed()) {
    header('Location: ' . zan_url('install/'));
    exit;
}

$ok     = true;
$detail = '';
try {
    $pdo = zan_db();
    $pdo->query('SELECT 1');
} catch (PDOException $e) {
    $ok     = false;
    $detail = '数据库连接失败';
}

$server = zan_abs_url('');

if (isset($_GET['format']) && $_GET['format'] === 'html') {
    $snippet = '<div data-zan data-zan-scope="content"></div>' . "\n"
        . '<span data-zan-view></span>' . "\n"
        . '<script src="' . $server . '/api/js/zan.js" data-server="' . $server . '" defer></script>';
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo zan_h(zan_site_name()); ?> · 服务状态</title>
<style>
    :root { color-scheme: light dark; }
    body { margin: 0; background: #f6f7f9; color: #1d2327; font: 15px/1.7 -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif; }
    .wrap { max-width: 640px; margin: 64px auto; padding: 0 20px; }
    .card { background: #fff; border: 1px solid #e3e6ea; border-radius: 12px; padding: 24px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .badge { display: inline-flex; align-items: center; gap: 8px; margin: 12px 0 20px; font-weight: 600; }
    .dot { width: 9px; height: 9px; border-radius: 50%; background: <?php echo $ok ? '#2f9e44' : '#e03131'; ?>; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { text-align: left; padding: 8px 0; border-top: 1px solid #eef0f2; vertical-align: top; }
    th { width: 90px; color: #6b7280; font-weight: 500; }
    code, textarea { font: 13px/1.6 ui-monospace, Consolas, monospace; }
    textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #e3e6ea; border-radius: 8px; background: #fafbfc; resize: vertical; }
    .btn { display: inline-block; margin-top: 18px; padding: 9px 16px; border-radius: 8px; background: #3858e9; color: #fff; text-decoration: none; font-size: 14px; }
    @media (prefers-color-scheme: dark) {
        body { background: #14161a; color: #e6e8eb; }
        .card { background: #1b1e24; border-color: #2a2f38; }
        th, td { border-color: #2a2f38; }
        th { color: #9aa3af; }
        textarea { background: #14161a; border-color: #2a2f38; color: #e6e8eb; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1><?php echo zan_h(zan_site_name()); ?></h1>
        <div class="badge"><i class="dot"></i><?php echo $ok ? '运行正常' : zan_h($detail); ?></div>
        <table>
            <tr><th>服务地址</th><td><code><?php echo zan_h($server); ?></code></td></tr>
            <tr><th>版本</th><td><?php echo zan_h(ZAN_VERSION); ?></td></tr>
            <tr><th>接入代码</th><td><textarea rows="3" readonly onclick="this.select()"><?php echo zan_h($snippet); ?></textarea></td></tr>
        </table>
        <a class="btn" href="<?php echo zan_h(zan_url('admin/login.php')); ?>">进入后台</a>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}
echo json_encode(array(
    'code'    => $ok ? 0 : 500,
    'message' => $ok ? 'Sugar-Box 运行正常：点赞只在内容页生效，统计覆盖全站' : ('Sugar-Box 无法正常工作：' . $detail),
    'version' => ZAN_VERSION,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
