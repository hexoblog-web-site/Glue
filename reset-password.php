<?php
/**
 * 管理员密码重置（一次性工具）
 *
 * 用法：
 *   1. 把本文件放到站点根目录（与 config.php 同级）
 *   2. 在下面 RESET_KEY 的空字符串里填一个只有你知道的口令
 *   3. 浏览器打开 https://你的域名/reset-password.php
 *   4. 输入口令 + 新密码，重置成功后删除本文件
 *
 * 同一份文件放到 like-lite 根目录也能用，它会自动认表前缀。
 */

define('RESET_KEY', '');
define('RESET_MIN_PASS', 8);
define('RESET_MAX_TRY', 5);

session_name('zanreset');
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');

$errors = array();
$notice = '';
$admins = array();
$table  = '';
$pdo    = null;

function rp_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** 在库里找管理员表：先按前缀猜，再按 password_hash 列反查 */
function rp_find_table(PDO $pdo, array $cfg)
{
    $prefix = isset($cfg['table_prefix']) ? (string) $cfg['table_prefix'] : '';
    $tries  = array($prefix . 'admin', 'zan_admin', 'like_admin', 'll_admin', 'admin');
    foreach ($tries as $t) {
        if ($t === '') {
            continue;
        }
        try {
            $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $t) . '` LIMIT 1');
            return $t;
        } catch (PDOException $e) {
            // 继续试下一个
        }
    }
    try {
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'password_hash'");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            if (stripos($row[0], 'admin') !== false) {
                return $row[0];
            }
        }
    } catch (PDOException $e) {
        // 没有 information_schema 权限就放弃
    }
    return '';
}

function rp_locked()
{
    $tries = isset($_SESSION['rp_tries']) ? (int) $_SESSION['rp_tries'] : 0;
    $last  = isset($_SESSION['rp_last']) ? (int) $_SESSION['rp_last'] : 0;
    return $tries >= RESET_MAX_TRY && (time() - $last) < 600;
}

/* ---------- 引导：读配置、连库、定位表 ---------- */

if (!is_file(__DIR__ . '/config.php')) {
    $errors[] = '当前目录下没有 config.php，请把本文件放到程序根目录（与 config.php 同级）。';
} else {
    $cfg = require __DIR__ . '/config.php';
    if (!is_array($cfg)) {
        $errors[] = 'config.php 格式不正确，无法读取数据库配置。';
    } else {
        try {
            $dsn = 'mysql:host=' . (isset($cfg['db_host']) ? $cfg['db_host'] : 'localhost')
                . ';dbname=' . (isset($cfg['db_name']) ? $cfg['db_name'] : '')
                . ';charset=utf8mb4';
            $pdo = new PDO($dsn, isset($cfg['db_user']) ? $cfg['db_user'] : '', isset($cfg['db_pass']) ? $cfg['db_pass'] : '', array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
            $table = rp_find_table($pdo, $cfg);
            if ($table === '') {
                $errors[] = '数据库里找不到管理员表（含 password_hash 列的 admin 表）。';
            }
        } catch (PDOException $e) {
            $errors[] = '数据库连接失败：' . $e->getMessage();
        }
    }
}

/* ---------- 处理提交 ---------- */

$key_ready = (RESET_KEY !== '' && strlen(RESET_KEY) >= 6);

if (!$errors && $table !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key  = isset($_POST['key']) ? (string) $_POST['key'] : '';
    $id   = isset($_POST['admin_id']) ? (int) $_POST['admin_id'] : 0;
    $p1   = isset($_POST['pass1']) ? (string) $_POST['pass1'] : '';
    $p2   = isset($_POST['pass2']) ? (string) $_POST['pass2'] : '';

    if (!$key_ready) {
        $errors[] = '请先打开本文件，把 RESET_KEY 改成你自己的口令。';
    } elseif (rp_locked()) {
        $errors[] = '口令错误次数过多，请 10 分钟后再试。';
    } elseif (!hash_equals(RESET_KEY, $key)) {
        $errors[] = '口令不正确。';
        $_SESSION['rp_tries'] = (isset($_SESSION['rp_tries']) ? (int) $_SESSION['rp_tries'] : 0) + 1;
        $_SESSION['rp_last']  = time();
        usleep(300000);
    } elseif (mb_strlen($p1) < RESET_MIN_PASS) {
        $errors[] = '新密码至少 ' . RESET_MIN_PASS . ' 位。';
    } elseif ($p1 !== $p2) {
        $errors[] = '两次输入的密码不一致。';
    } elseif ($id <= 0) {
        $errors[] = '请选择要重置的账号。';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE `' . str_replace('`', '', $table) . '`
                SET `password_hash` = ? WHERE `id` = ?');
            $stmt->execute(array(password_hash($p1, PASSWORD_DEFAULT), $id));
            unset($_SESSION['rp_tries'], $_SESSION['rp_last']);
            $notice = '密码已重置，现在可以用新密码登录后台了。';
        } catch (PDOException $e) {
            $errors[] = '写入失败：' . $e->getMessage();
        }
    }
}

/* ---------- 账号列表 ---------- */

if (!$errors && $table !== '' && $notice === '') {
    try {
        $admins = $pdo->query('SELECT `id`, `email` FROM `' . str_replace('`', '', $table) . '`
            ORDER BY `id` ASC LIMIT 10')->fetchAll();
        if (!$admins) {
            $errors[] = '管理员表是空的，请重新运行安装向导创建账号。';
        }
    } catch (PDOException $e) {
        $errors[] = '读取管理员列表失败：' . $e->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title>重置管理员密码</title>
<style>
:root { color-scheme: light dark; }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
       padding: 32px 16px; background: #f5f6f8; color: #1f2328;
       font: 15px/1.65 -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; }
.card { width: 100%; max-width: 460px; background: #fff; border: 1px solid #e3e6ea;
        border-radius: 14px; padding: 26px 26px 22px; box-shadow: 0 8px 28px rgba(15, 23, 42, .06); }
h1 { margin: 0 0 4px; font-size: 19px; }
.sub { margin: 0 0 18px; color: #6b7280; font-size: 13px; }
label { display: block; margin: 14px 0 6px; font-size: 13px; color: #374151; }
input, select { width: 100%; padding: 9px 11px; border: 1px solid #d5d9df; border-radius: 8px;
                font: inherit; background: #fff; color: inherit; }
input:focus, select:focus { outline: 2px solid #bfdbfe; border-color: #7aa7e0; }
button { margin-top: 20px; width: 100%; padding: 11px; border: 0; border-radius: 9px;
         background: #2563eb; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
button:hover { background: #1d4ed8; }
.msg { margin: 0 0 14px; padding: 10px 12px; border-radius: 9px; font-size: 13px; }
.msg.err { background: #fef2f2; color: #b42318; border: 1px solid #fecaca; }
.msg.ok { background: #ecfdf3; color: #067647; border: 1px solid #bbf7d0; }
.tip { margin: 16px 0 0; padding-top: 14px; border-top: 1px dashed #e3e6ea;
       color: #6b7280; font-size: 12.5px; }
code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; font-size: 12.5px; }
@media (prefers-color-scheme: dark) {
  body { background: #0f1115; color: #e6e8eb; }
  .card { background: #171a1f; border-color: #2a2f37; box-shadow: none; }
  .sub, .tip { color: #9aa2ad; }
  label { color: #c2c8d0; }
  input, select { background: #12151a; border-color: #333a44; }
  code { background: #232830; }
  .msg.err { background: #2a1618; border-color: #5a2a2e; color: #fca5a5; }
  .msg.ok { background: #12251b; border-color: #235c3c; color: #86efac; }
  .tip { border-top-color: #2a2f37; }
}
</style>
</head>
<body>
<main class="card">
    <h1>重置管理员密码</h1>
    <p class="sub">输入本文件里设置的口令，给管理员账号换一个新密码。</p>

    <?php foreach ($errors as $e) : ?>
        <div class="msg err"><?php echo rp_h($e); ?></div>
    <?php endforeach; ?>

    <?php if ($notice !== '') : ?>
        <div class="msg ok"><?php echo rp_h($notice); ?></div>
        <form method="post" action="">
            <button type="button" onclick="location.href='admin/login.php'">去登录</button>
        </form>
        <p class="tip">为了安全，请立即从服务器删除 <code>reset-password.php</code>。</p>
    <?php elseif (!$key_ready) : ?>
        <p class="tip">还没有设置口令。请用文本编辑器打开本文件，找到顶部这一行：
        <code>define('RESET_KEY', '');</code>
        在两个单引号中间填一个 6 位以上、只有你知道的口令，保存后刷新本页。</p>
    <?php elseif ($admins) : ?>
        <form method="post" action="">
            <?php if (count($admins) > 1) : ?>
                <label for="admin_id">要重置的账号</label>
                <select id="admin_id" name="admin_id">
                    <?php foreach ($admins as $a) : ?>
                        <option value="<?php echo (int) $a['id']; ?>"><?php echo rp_h($a['email']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input type="hidden" name="admin_id" value="<?php echo (int) $admins[0]['id']; ?>" />
                <label>账号</label>
                <input type="text" value="<?php echo rp_h($admins[0]['email']); ?>" readonly />
            <?php endif; ?>

            <label for="key">重置口令</label>
            <input id="key" name="key" type="password" autocomplete="off" required />

            <label for="pass1">新密码</label>
            <input id="pass1" name="pass1" type="password" autocomplete="new-password" required />

            <label for="pass2">重复新密码</label>
            <input id="pass2" name="pass2" type="password" autocomplete="new-password" required />

            <button type="submit">重置密码</button>
        </form>
        <p class="tip">重置成功后立即删除本文件，否则知道口令的人随时能改密码。</p>
    <?php endif; ?>
</main>
</body>
</html>
