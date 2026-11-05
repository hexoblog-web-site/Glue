<?php
/**
 * 安装第一步：数据库连接信息 → 生成 config.php。
 *
 *   step 0  说明需要用到的信息
 *   step 1  表单：库名 / 用户名 / 密码 / 主机 / 表前缀
 *   step 2  连接 → 选库 → 写入 config.php
 */
define('ZAN_INSTALL_UI', true);
require_once dirname(__DIR__) . '/include/install-ui.php';

if (zan_config_exists()) {
    zan_install_die(
        '<p><code>config.php</code> 已经存在。要重置配置请先删除这个文件，然后<a href="install.php">继续安装</a>。</p>',
        '配置文件已存在',
        409
    );
}

zan_env_gate();

$step = isset($_GET['step']) ? (int) $_GET['step'] : 0;

switch ($step) {
    case 0:
        zan_install_header(ZAN_BRAND . ' › 配置文件');
        ?>
<h1>开始之前</h1>
<p>安装 <?php echo zan_h(ZAN_BRAND); ?> 需要下面几项信息，通常由主机商提供：</p>
<ol>
    <li>数据库名</li>
    <li>数据库用户名</li>
    <li>数据库密码</li>
    <li>数据库主机（一般是 <code>localhost</code>）</li>
    <li>表前缀（同一个库里装多套程序时才需要改）</li>
</ol>
<p>这些信息会写进程序目录下的 <code>config.php</code>。</p>
<p class="ip-step"><a class="ip-btn" href="setup-config.php?step=1">现在就开始</a></p>
        <?php
        break;

    case 1:
        zan_install_header(ZAN_BRAND . ' › 配置文件');
        ?>
<h1>数据库连接</h1>
<form method="post" action="setup-config.php?step=2">
    <table class="ip-table">
        <tr>
            <th><label for="dbname">数据库名</label></th>
            <td><input name="dbname" id="dbname" type="text" placeholder="zan" autofocus />
                <p class="ip-desc">要把 <?php echo zan_h(ZAN_BRAND); ?> 装到哪个库。</p></td>
        </tr>
        <tr>
            <th><label for="uname">用户名</label></th>
            <td><input name="uname" id="uname" type="text" placeholder="数据库用户名" /></td>
        </tr>
        <tr>
            <th><label for="pwd">密码</label></th>
            <td>
                <div class="ip-pwd">
                    <input name="pwd" id="pwd" type="password" autocomplete="off" spellcheck="false" placeholder="数据库密码" />
                    <button type="button" class="ip-btn ip-ghost" data-reveal="pwd">显示</button>
                </div>
            </td>
        </tr>
        <tr>
            <th><label for="dbhost">数据库主机</label></th>
            <td><input name="dbhost" id="dbhost" type="text" value="localhost" />
                <p class="ip-desc">如果 <code>localhost</code> 不通，向主机商确认正确地址。</p></td>
        </tr>
        <tr>
            <th><label for="prefix">表前缀</label></th>
            <td><input name="prefix" id="prefix" type="text" value="zan_" />
                <p class="ip-desc">默认 <code>zan_</code>，最终表名是 <code>zan_page</code>、<code>zan_log</code>、<code>zan_admin</code>。</p></td>
        </tr>
    </table>
    <p class="ip-step"><button type="submit" class="ip-btn">提交</button></p>
</form>
        <?php
        break;

    case 2:
        $dbname = isset($_POST['dbname']) ? trim($_POST['dbname']) : '';
        $uname  = isset($_POST['uname']) ? trim($_POST['uname']) : '';
        $pwd    = isset($_POST['pwd']) ? (string) $_POST['pwd'] : '';
        $dbhost = isset($_POST['dbhost']) ? trim($_POST['dbhost']) : '';
        $prefix = isset($_POST['prefix']) ? trim($_POST['prefix']) : '';

        if ($prefix === '') {
            zan_install_die('<p>「表前缀」不能为空。</p>', '配置有误', 400);
        }
        if (preg_match('|[^a-z0-9_]|i', $prefix)) {
            zan_install_die('<p>「表前缀」只能包含数字、字母和下划线。</p>', '配置有误', 400);
        }

        try {
            $pdo = zan_db_connect($dbhost, $uname, $pwd);
        } catch (PDOException $e) {
            zan_db_connect_error($dbhost);
        }

        try {
            zan_db_select($pdo, $dbname);
        } catch (PDOException $e) {
            zan_db_select_error($dbname, $uname);
        }

        /* 前缀被 MySQL 当成值解析（纯数字）时同样非法 */
        try {
            $pdo->query('SELECT ' . $prefix);
            zan_install_die('<p>「表前缀」无效。</p>', '配置有误', 400);
        } catch (PDOException $e) {
            /* 预期的语法错误，说明前缀没问题 */
        }

        $config = array(
            'db_host'         => $dbhost,
            'db_name'         => $dbname,
            'db_user'         => $uname,
            'db_pass'         => $pwd,
            'table_prefix'    => $prefix,
            'site_secret'     => bin2hex(random_bytes(32)),
            'site_id'         => bin2hex(random_bytes(8)),
            'site_name'       => '',
            'view_on'         => true,
            'view_mode'       => 'pv',
            'salt_rotation'   => 'month',
            /* 新装没有旧阅读表，不需要迁移 */
            'stats_migrated'  => '1',
            /* 留空 = 允许任意来源调用接口（本地调试方便）。上线后可填 ['https://www.example.com'] */
            'allowed_origins' => array(),
            /* 套了 Cloudflare / 面板反代时把回源 IP 填进来，否则所有人算同一个访客 */
            'trusted_proxies' => array(),
        );
        $content = "<?php\n/**\n * Sugar-Box 配置（安装向导生成，后台设置页会更新）\n */\nreturn " . var_export($config, true) . ";\n";
        $file    = ZAN_ROOT . 'config.php';

        if (@file_put_contents($file, $content, LOCK_EX) !== false) {
            @chmod($file, 0644);
            zan_install_header(ZAN_BRAND . ' › 配置文件');
            echo '<h1>数据库连接成功</h1><p>已经能连上数据库了。下一步创建数据表和管理员账号。</p>'
                . '<p class="ip-step"><a class="ip-btn" href="install.php">运行安装程序</a></p>';
            zan_install_footer();
            exit;
        }

        zan_install_header(ZAN_BRAND . ' › 配置文件');
        ?>
<h1>无法写入 config.php</h1>
<p>把下面这段内容手工保存成程序根目录下的 <code>config.php</code>，然后点「运行安装程序」。</p>
<textarea class="ip-code" rows="14" readonly><?php echo zan_h($content); ?></textarea>
<p class="ip-step"><a class="ip-btn" href="install.php">运行安装程序</a></p>
        <?php
        break;
}

zan_install_footer();
