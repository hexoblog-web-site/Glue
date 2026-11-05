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

        $origins = $lines(isset($_POST['allowed_origins']) ? $_POST['allowed_origins'] : '');
        $proxies = $lines(isset($_POST['trusted_proxies']) ? $_POST['trusted_proxies'] : '');

        $ok = zan_config_save(array(
            'site_name'       => mb_substr($name, 0, 60, 'UTF-8'),
            'allowed_origins' => $origins,
            'trusted_proxies' => $proxies,
            'view_on'         => isset($_POST['view_on']),
            'view_mode'       => (isset($_POST['view_mode']) && $_POST['view_mode'] === 'pv') ? 'pv' : 'uv',
            'salt_rotation'   => (isset($_POST['salt_rotation'])
                && in_array($_POST['salt_rotation'], array('day', 'week', 'month'), true))
                ? $_POST['salt_rotation'] : 'month',
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

zan_admin_header('设置', 'settings.php');
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
            <code>&lt;div data-zan data-zan-variant="pill" data-zan-color="#0b7285"&gt;&lt;/div&gt;</code>，
            可选 <code>heart</code> / <code>pill</code> / <code>mini</code> 三种形态。
            显示全站累计浏览用 <code>&lt;span data-zan-view data-zan-scope="site"&gt;&lt;/span&gt;</code>。</p>
    </div>

    <div class="za-card">
        <div class="za-card-head"><h2>网站统计</h2></div>
        <label class="za-check">
            <input type="checkbox" name="view_on" value="1" <?php echo zan_cfg('view_on', true) ? 'checked' : ''; ?> />
            <span>记录全站浏览</span>
        </label>
        <p class="za-desc">统计覆盖所有页面（首页、归档、标签页都算），关掉后接口只返回已有数字、不再写入。
            点赞不受这个开关影响，且仍然只在内容页有效。</p>

        <label for="view_mode">界面显示哪个数</label>
        <select id="view_mode" name="view_mode">
            <option value="uv" <?php echo zan_view_mode() === 'uv' ? 'selected' : ''; ?>>访客数（同一人多天只算一次）</option>
            <option value="pv" <?php echo zan_view_mode() === 'pv' ? 'selected' : ''; ?>>浏览次数（每打开一次算一次）</option>
        </select>
        <p class="za-desc">两个数都在记，这里只决定 <code>data-zan-view</code> 显示哪个。后台统计页两个都列。</p>

        <label for="salt_rotation">访客去重周期</label>
        <select id="salt_rotation" name="salt_rotation">
            <option value="month" <?php echo zan_salt_rotation() === 'month' ? 'selected' : ''; ?>>一个月（推荐，同 Umami 默认）</option>
            <option value="week" <?php echo zan_salt_rotation() === 'week' ? 'selected' : ''; ?>>一周</option>
            <option value="day" <?php echo zan_salt_rotation() === 'day' ? 'selected' : ''; ?>>一天</option>
        </select>
        <p class="za-desc">与 Umami 同一套写入模型：访客身份 = 站点 + IP + 浏览器 UA + 当期盐 + 密钥的哈希，浏览器不存任何标识。
            盐按这个周期轮换，跨周期算不出同一个人；<b>改周期等于重置访客数</b>，
            历史记录不会变，但同一个人会被当成新访客重新计一次。
            爬虫、预取、后台标签页一律不计。</p>
    </div>

    <div class="za-card">
        <div class="za-card-head"><h2>访问控制</h2></div>
        <label for="allowed_origins">允许调用接口的站点（跨域白名单）</label>
        <textarea id="allowed_origins" name="allowed_origins" rows="3"
                  placeholder="https://www.example.com"><?php echo zan_h($origins); ?></textarea>
        <p class="za-desc">一行一个，留空表示不限制。博客和接口不同域时才需要填，必须带 <code>https://</code>。</p>

        <label for="trusted_proxies">反向代理 IP</label>
        <textarea id="trusted_proxies" name="trusted_proxies" rows="2"
                  placeholder="127.0.0.1"><?php echo zan_h($proxies); ?></textarea>
        <p class="za-desc">套了 Cloudflare、宝塔反代时填回源 IP。<b>访客数就是按这个 IP + UA 算的</b>：
            反代没配好时，所有访客会被当成同一个人（浏览次数照常涨，访客数一直是 1）。</p>
    </div>

    <p><button type="submit" class="za-btn">保存设置</button></p>
</form>

<div class="za-card">
    <div class="za-card-head"><h2>接口地址</h2></div>
    <table class="za-kv">
        <tr><th>SDK</th><td><code><?php echo zan_h(zan_abs_url('api/js/zan.js')); ?></code></td></tr>
        <tr><th>接口</th><td><code><?php echo zan_h(zan_abs_url('api/zan.php')); ?></code></td></tr>
        <tr><th>样式</th><td><code><?php echo zan_h(zan_abs_url('api/style/zan.css')); ?></code></td></tr>
    </table>
</div>
<?php zan_admin_footer(); ?>
