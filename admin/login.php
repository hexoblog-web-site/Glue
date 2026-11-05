<?php
/**
 * 后台登录。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';

if (!zan_installed()) {
    header('Location: ' . zan_url('install/'));
    exit;
}

zan_session_start();

/* 已登录直接进后台 */
if (zan_admin() !== null) {
    header('Location: ' . zan_url('admin/index.php'));
    exit;
}

$error = '';
$email = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $pass  = isset($_POST['password']) ? (string) $_POST['password'] : '';

    $tries = isset($_SESSION['zan_login_tries']) ? (int) $_SESSION['zan_login_tries'] : 0;
    $last  = isset($_SESSION['zan_login_last']) ? (int) $_SESSION['zan_login_last'] : 0;

    if ($tries >= 8 && time() - $last < 600) {
        $error = '尝试次数过多，请 10 分钟后再试。';
    } elseif (!zan_csrf_ok(isset($_POST['nonce']) ? $_POST['nonce'] : '')) {
        $error = '页面已过期，请重新提交。';
    } elseif ($email === '' || $pass === '') {
        $error = '请填写邮箱和密码。';
    } else {
        try {
            $pdo  = zan_db();
            $stmt = $pdo->prepare('SELECT `id`, `email`, `password_hash` FROM ' . zan_table('admin')
                . ' WHERE `email` = ? LIMIT 1');
            $stmt->execute(array($email));
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            $row = false;
            $error = '数据库连接失败。';
        }

        if ($row && password_verify($pass, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['zan_admin_id']    = (int) $row['id'];
            $_SESSION['zan_admin_email'] = (string) $row['email'];
            unset($_SESSION['zan_login_tries'], $_SESSION['zan_login_last']);
            zan_csrf_rotate();
            header('Location: ' . zan_url('admin/index.php'));
            exit;
        }
        if ($error === '') {
            $error = '邮箱或密码不正确。';
            $_SESSION['zan_login_tries'] = $tries + 1;
            $_SESSION['zan_login_last']  = time();
            usleep(300000);
        }
    }
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title>登录 - <?php echo zan_h(zan_site_name()); ?></title>
<link rel="stylesheet" href="<?php echo zan_h(zan_url('assets/admin.css')); ?>?v=<?php echo zan_h(ZAN_VERSION); ?>" />
</head>
<body class="za za-login">
<main class="za-login-card">
    <div class="za-brand"><?php echo zan_h(zan_site_name()); ?></div>
    <h1>登录后台</h1>
    <?php if ($error !== '') : ?>
        <div class="za-notice is-error"><?php echo zan_h($error); ?></div>
    <?php endif; ?>
    <form method="post" action="<?php echo zan_h(zan_url('admin/login.php')); ?>">
        <?php echo zan_csrf_field(); ?>
        <label for="email">邮箱</label>
        <input id="email" name="email" type="email" value="<?php echo zan_h($email); ?>" autocomplete="username" autofocus required />
        <label for="password">密码</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />
        <button type="submit" class="za-btn">登录</button>
    </form>
    <p class="za-login-foot"><?php echo zan_h(ZAN_BRAND); ?> v<?php echo zan_h(ZAN_VERSION); ?></p>
</main>
</body>
</html>
