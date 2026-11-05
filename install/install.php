<?php
/**
 * 安装第二步：建表 + 建管理员。
 *
 *   step 0/1  表单：邮箱、密码、重复密码
 *   step 2    校验 → 建表 → 写入管理员 → 成功页（含接入代码）
 */
define('ZAN_INSTALL_UI', true);
require_once dirname(__DIR__) . '/include/install-ui.php';
require_once dirname(__DIR__) . '/include/schema.php';

if (!zan_config_exists()) {
    header('Location: setup-config.php');
    exit;
}

$db = @include ZAN_ROOT . 'config.php';
if (!is_array($db) || empty($db['db_name'])) {
    header('Location: setup-config.php');
    exit;
}

$prefix = isset($db['table_prefix']) ? $db['table_prefix'] : 'zan_';

function zan_i_table($name, $prefix)
{
    return '`' . $prefix . $name . '`';
}

function zan_i_installed(PDO $pdo, $prefix)
{
    try {
        $sql = "SHOW TABLES LIKE " . $pdo->quote(str_replace('_', '\\_', $prefix . 'admin'));
        if (!$pdo->query($sql)->fetchColumn()) {
            return false;
        }
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_i_table('admin', $prefix))->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function zan_i_password($length = 18)
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*';
    $max   = strlen($chars) - 1;
    $out   = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

$step = isset($_GET['step']) ? (int) $_GET['step'] : 0;

try {
    $pdo = zan_db_connect($db['db_host'], $db['db_user'], $db['db_pass'], $db['db_name']);
} catch (PDOException $e) {
    zan_db_connect_error($db['db_host']);
}

if (zan_i_installed($pdo, $prefix)) {
    zan_already_installed('../admin/login.php');
}

/* 环境要求（PHP 已在第一步拦过，这里补 MySQL 版本） */
$mysqlVersion = '0';
try {
    $mysqlVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
} catch (PDOException $e) {
}
$mysqlOk = version_compare(preg_replace('/[^0-9.].*$/', '', $mysqlVersion), '5.5.5', '>=');
if (!version_compare(PHP_VERSION, ZAN_REQ_PHP, '>=') || !$mysqlOk) {
    zan_install_die(
        '<p>需要 PHP ' . zan_h(ZAN_REQ_PHP) . '+ / MySQL 5.5.5+，当前 PHP ' . zan_h(PHP_VERSION)
        . ' / MySQL ' . zan_h($mysqlVersion) . '。</p>',
        '未满足要求'
    );
}

function zan_i_form($error = null)
{
    $email = isset($_POST['admin_email']) ? trim($_POST['admin_email']) : '';
    zan_install_header(ZAN_BRAND . ' › 安装');
    if ($error !== null) {
        echo '<div class="ip-error">' . $error . '</div>';
    }
    ?>
<h1>创建管理员</h1>
<p>只需一个邮箱和一组密码。站点名称、样式等装完在后台改。</p>
<form method="post" action="install.php?step=2" novalidate>
    <input type="hidden" name="zan_install_nonce" value="<?php echo zan_h(zan_install_nonce()); ?>" />
    <table class="ip-table">
        <tr>
            <th><label for="admin_email">邮箱</label></th>
            <td><input name="admin_email" id="admin_email" type="email" value="<?php echo zan_h($email); ?>" autofocus />
                <p class="ip-desc">登录时用它作为账号。</p></td>
        </tr>
        <tr>
            <th><label for="pass1">密码</label></th>
            <td><input name="admin_password" id="pass1" type="password" autocomplete="new-password" />
                <p class="ip-desc">留空则由系统生成 18 位随机密码，装完在成功页显示一次。</p></td>
        </tr>
        <tr>
            <th><label for="pass2">重复密码</label></th>
            <td><input name="admin_password2" id="pass2" type="password" autocomplete="new-password" /></td>
        </tr>
    </table>
    <p class="ip-step"><button type="submit" class="ip-btn">安装 <?php echo zan_h(ZAN_BRAND); ?></button></p>
</form>
    <?php
    zan_install_footer();
}

switch ($step) {
    case 0:
    case 1:
        zan_i_form();
        break;

    case 2:
        $pass1 = isset($_POST['admin_password']) ? (string) $_POST['admin_password'] : '';
        $pass2 = isset($_POST['admin_password2']) ? (string) $_POST['admin_password2'] : '';
        $email = isset($_POST['admin_email']) ? trim($_POST['admin_email']) : '';
        $nonce = isset($_POST['zan_install_nonce']) ? $_POST['zan_install_nonce'] : '';

        if (!zan_install_nonce_ok($nonce)) {
            zan_i_form('页面已过期，请重新填写。');
            break;
        }

        $error = null;
        if ($pass1 !== $pass2) {
            $error = '两次输入的密码不一致。';
        } elseif ($email === '') {
            $error = '请填写邮箱地址。';
        } elseif (!zan_is_valid_email($email)) {
            $error = '这个邮箱格式看起来不对，例如 <code>name@example.com</code>。';
        }
        if ($error !== null) {
            zan_i_form($error);
            break;
        }

        $generated = false;
        if ($pass1 === '') {
            $pass1     = zan_i_password(18);
            $generated = true;
        }

        try {
            zan_create_tables($pdo, $prefix);
            $stmt = $pdo->prepare('INSERT INTO ' . zan_i_table('admin', $prefix)
                . ' (`email`, `password_hash`, `created_at`) VALUES (?, ?, NOW())');
            $stmt->execute(array($email, password_hash($pass1, PASSWORD_DEFAULT)));
        } catch (PDOException $e) {
            zan_install_die(
                '<p>数据表写入失败：' . zan_h($e->getMessage()) . '</p>'
                . '<p class="ip-step"><a class="ip-btn" href="install.php?step=1">返回重试</a></p>',
                '安装失败'
            );
        }

        /* 接入代码：按当前访问地址推导，装完即可复制 */
        $root  = rtrim(str_replace('\\', '/', dirname(dirname((string) $_SERVER['SCRIPT_NAME']))), '/');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $base  = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $root;
        $snippet = '<div data-zan data-zan-scope="content"></div>' . "\n"
            . '<span data-zan-view></span>' . "\n"
            . '<script src="' . $base . '/api/js/zan.js" data-server="' . $base . '" defer></script>';

        zan_install_header(ZAN_BRAND . ' › 安装完成');
        ?>
<h1>安装完成</h1>
<p>管理员账号已经建好，数据表也齐了。</p>
<table class="ip-table ip-success">
    <tr><th>邮箱</th><td><?php echo zan_h($email); ?></td></tr>
    <tr>
        <th>密码</th>
        <td><code><?php echo zan_h($pass1); ?></code>
            <p class="ip-desc"><?php echo $generated ? '系统随机生成，' : ''; ?>只显示这一次，请现在保存好。</p></td>
    </tr>
</table>

<h2>把下面两行贴到文章页</h2>
<textarea class="ip-code" rows="3" readonly><?php echo zan_h($snippet); ?></textarea>
<p class="ip-desc">第一行是容器，第二行加载脚本。CSS 由脚本自动注入，不用另外引。</p>

<p class="ip-step"><a class="ip-btn" href="../admin/login.php">前往登录</a></p>
<p class="ip-note"><strong>安全提示：</strong>装完请删除服务器上的 <code>install</code> 文件夹，防止别人重装。</p>
        <?php
        zan_install_footer();
        break;
}
