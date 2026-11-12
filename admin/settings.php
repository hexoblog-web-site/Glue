<?php
/**
 * 设置：站点名称、跨域白名单、反向代理。
 * 全部写进 config.php —— 不额外建表，也就不用迁移。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
zan_require_admin();

$notice = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!zan_csrf_ok(isset($_POST['nonce']) ? $_POST['nonce'] : '')) {
        $notice = array('页面已过期，请刷新后重试。', 'error');
    } else {
        $name = trim((string) (isset($_POST['site_name']) ? $_POST['site_name'] : ''));

        $lines = function ($text) {
            $out = array();
            foreach (preg_split('/[\r\n,]+/', (string) $text) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $out[] = $line;
                }
            }
            return array_values(array_unique($out));
        };

        $origins = array();
        foreach ($lines(isset($_POST['allowed_origins']) ? $_POST['allowed_origins'] : '') as $line) {
            /* 存归一化后的值：浏览器发来的 Origin 没有结尾斜杠，
               这里也去掉，免得存进去的写法在运行时永远匹配不上 */
            $origins[] = $line === '*' ? '*' : zan_origin_norm($line);
        }
        $origins = array_values(array_unique(array_filter($origins)));
        $proxies = $lines(isset($_POST['trusted_proxies']) ? $_POST['trusted_proxies'] : '');

        $ok = zan_config_save(array(
            'site_name'       => mb_substr($name, 0, 60, 'UTF-8'),
            'allowed_origins' => $origins,
            'trusted_proxies' => $proxies,
        ));

        if ($ok) {
            header('Location: ' . zan_url('admin/settings.php?done=1'));
            exit;
        }
        $notice = array('写入 config.php 失败，请检查程序目录是否可写（可手动改文件）。', 'error');
    }
}

$origins = implode("\n", (array) zan_cfg('allowed_origins', array()));
$proxies = implode("\n", (array) zan_cfg('trusted_proxies', array()));

if (isset($_GET['done'])) {
    $notice = array('已保存。', 'ok');
}

zan_admin_header('系统设置', 'settings.php');
if ($notice) {
    zan_notice(zan_h($notice[0]), $notice[1]);
}
?>
<form method="post" action="<?php echo zan_h(zan_url('admin/settings.php')); ?>" class="za-form">
    <?php echo zan_csrf_field(); ?>

    <div class="za-card">
        <div class="za-card-head"><h2>基本</h2></div>
        <label for="site_name">站点名称</label>
        <input id="site_name" name="site_name" type="text" maxlength="60"
               value="<?php echo zan_h(zan_cfg('site_name', '')); ?>" placeholder="<?php echo zan_h(ZAN_BRAND); ?>" />
        <p class="za-desc">显示在后台标题和标签页上，不影响前台按钮。</p>
        <p class="za-desc">前台外观由容器属性决定，例如
            <code>&lt;div data-zan data-zan-scope="content" data-zan-variant="pill" data-zan-color="#0b7285"&gt;&lt;/div&gt;</code>，
            可选 <code>heart</code> / <code>pill</code> / <code>mini</code> 三种形态。</p>
    </div>

    <div class="za-card">
        <div class="za-card-head"><h2>访问控制</h2></div>
        <label for="allowed_origins">允许调用接口的站点（跨域白名单）</label>
        <textarea id="allowed_origins" name="allowed_origins" rows="3"
                  placeholder="https://www.example.com"><?php echo zan_h($origins); ?></textarea>
        <p class="za-desc">一行一个，留空表示不限制。博客和接口不同域时才需要填。
            要填的是浏览器地址栏里的 <code>协议://域名</code>：<b>不要带结尾斜杠</b>，也不要带路径。
            <code>https://www.example.com</code> 与 <code>https://example.com</code>
            是两个不同的来源，两种写法都要能访问就都写上。<br>
            填错不会报错，但点赞会提示「网络错误」而计数其实已经 +1：
            服务端照常写入，只是浏览器读不到响应。</p>

        <label for="trusted_proxies">反向代理 IP</label>
        <textarea id="trusted_proxies" name="trusted_proxies" rows="2"
                  placeholder="127.0.0.1"><?php echo zan_h($proxies); ?></textarea>
        <p class="za-desc">套了 Cloudflare、Nginx 反代时填回源 IP。<b>没有浏览器本地标识的访客，点赞去重是按 IP + UA 算的</b>：
            反代没配好时，所有访客会被当成同一个人。</p>
    </div>

    <p><button type="submit" class="za-btn is-primary">保存设置</button></p>
</form>

<div class="za-card">
    <div class="za-card-head"><h2>接口地址</h2></div>
    <table class="za-kv">
        <tr><th>SDK</th><td><code><?php echo zan_h(zan_abs_url('api/js/zan.js')); ?></code></td></tr>
        <tr><th>接口</th><td><code><?php echo zan_h(zan_abs_url('api/zan.php')); ?></code></td></tr>
        <tr><th>样式</th><td><code><?php echo zan_h(zan_abs_url('api/style/zan.css')); ?></code></td></tr>
    </table>
    <p class="za-desc">本服务只做点赞：不采集浏览、访客、来源、地区等信息，数据库里只有文章路径、标题和加盐哈希后的匿名指纹。</p>
</div>
<?php zan_admin_footer(); ?>
