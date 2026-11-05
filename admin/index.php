<?php
/**
 * 概览：点赞总数、全站浏览概况、最近 7 天、页面排行、接入代码。
 * 访问趋势、来源、浏览器等明细在 stats.php。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require_once dirname(__DIR__) . '/include/schema.php';
zan_require_admin();
$pdo = zan_db_or_die();
zan_stats_bootstrap($pdo);

$total = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('log'))->fetchColumn();
$pages = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('page'))->fetchColumn();
$today = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('log') . ' WHERE `created_at` >= CURDATE()')->fetchColumn();

$site  = zan_site_stats($pdo, '1970-01-01 00:00:00', '2099-12-31 23:59:59');
$todayStats = zan_site_stats($pdo, date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
$active = zan_stats_active($pdo, 5);

/* 最近 7 天：赞 + 浏览 */
$days  = array();
$vdays = array();
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime('-' . $i . ' day'));
    $days[$d]  = 0;
    $vdays[$d] = 0;
}
$stmt = $pdo->prepare('SELECT DATE(`created_at`) AS d, COUNT(*) AS c FROM ' . zan_table('log')
    . ' WHERE `created_at` >= ? GROUP BY DATE(`created_at`)');
$stmt->execute(array(date('Y-m-d', strtotime('-6 day')) . ' 00:00:00'));
foreach ($stmt->fetchAll() as $row) {
    if (isset($days[$row['d']])) {
        $days[$row['d']] = (int) $row['c'];
    }
}

$series = zan_stats_series($pdo, date('Y-m-d 00:00:00', strtotime('-6 day')), date('Y-m-d 23:59:59'));
foreach ($series as $d => $row) {
    if (isset($vdays[$d])) {
        $vdays[$d] = $row['pv'];
    }
}
$peak = max(1, max($days), max($vdays));

/* 两个子查询各自计数，避免 JOIN 多张表时相互放大 */
$rank = zan_page_rank_cols('p');
$top  = $pdo->query('SELECT p.`page_key`, p.`title`,'
    . ' ' . $rank['likes'] . ' AS likes,'
    . ' ' . $rank['views'] . ' AS views,'
    . ' ' . $rank['visitors'] . ' AS visitors,'
    . ' ' . $rank['last_at'] . ' AS last_at'
    . ' FROM ' . zan_table('page') . ' p'
    . ' ORDER BY views DESC, likes DESC, p.`updated_at` DESC LIMIT 10')->fetchAll();

$server  = zan_abs_url('');
$snippet = '<div data-zan data-zan-scope="content"></div>' . "\n"
    . '<span data-zan-view></span>' . "\n"
    . '<script src="' . $server . '/api/js/zan.js" data-server="' . $server . '" defer></script>';

zan_admin_header('仪表盘', 'index.php');
?>

<div class="za-cards">
    <div class="za-card za-stat"><span>总赞数</span><strong><?php echo $total; ?></strong></div>
    <div class="za-card za-stat"><span>今日赞</span><strong><?php echo $today; ?></strong></div>
    <div class="za-card za-stat"><span>今日浏览 · 访客 <?php echo (int) $todayStats['uv']; ?></span><strong><?php echo (int) $todayStats['pv']; ?></strong></div>
    <div class="za-card za-stat"><span>当前在线 · 5 分钟</span><strong><?php echo $active; ?></strong></div>
</div>

<p class="za-desc" style="margin:-4px 0 16px">
    累计浏览 <?php echo (int) $site['pv']; ?> · 访客 <?php echo (int) $site['uv']; ?> · 访问 <?php echo (int) $site['visits']; ?> 次 ·
    共 <?php echo $pages; ?> 个页面有点赞记录 ·
    界面显示<?php echo zan_view_mode() === 'pv' ? '浏览次数' : '访客数'; ?>
    · <a href="<?php echo zan_h(zan_url('admin/stats.php')); ?>">看统计明细 →</a>
</p>

<div class="za-card">
    <div class="za-card-head">
        <h2>最近 7 天</h2>
        <span class="za-legend"><i></i>赞<b></b>浏览</span>
    </div>
    <div class="za-chart">
        <?php foreach ($days as $day => $count) : ?>
            <?php
            $lh = max(2, (int) round($count / $peak * 78));
            $vh = max(2, (int) round($vdays[$day] / $peak * 78));
            ?>
            <div class="za-bar" title="<?php echo zan_h($day . '：赞 ' . $count . '，浏览 ' . $vdays[$day]); ?>">
                <div class="za-pair">
                    <i style="height:<?php echo $lh; ?>px"></i>
                    <b style="height:<?php echo $vh; ?>px"></b>
                </div>
                <span><?php echo zan_h(substr($day, 5)); ?></span>
                <em><?php echo $count; ?> / <?php echo $vdays[$day]; ?></em>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="za-card">
    <div class="za-card-head"><h2>页面排行</h2></div>
    <?php if (!$top) : ?>
        <p class="za-empty">还没有数据。把下面的代码贴到文章页，打开一次就会出现浏览数。</p>
    <?php else : ?>
    <table class="za-table">
        <thead><tr><th>页面</th><th class="num">浏览</th><th class="num">访客</th><th class="num">赞</th><th class="num">最近</th></tr></thead>
        <tbody>
        <?php foreach ($top as $row) : ?>
            <tr>
                <td>
                    <a href="<?php echo zan_h(zan_url('admin/list.php?key=' . urlencode($row['page_key']))); ?>">
                        <?php echo zan_h($row['title'] !== '' ? $row['title'] : $row['page_key']); ?>
                    </a>
                    <?php if ($row['title'] !== '') : ?>
                        <em class="za-sub"><?php echo zan_h($row['page_key']); ?></em>
                    <?php endif; ?>
                </td>
                <td class="num"><?php echo (int) $row['views']; ?></td>
                <td class="num"><?php echo (int) $row['visitors']; ?></td>
                <td class="num"><?php echo (int) $row['likes']; ?></td>
                <td class="num za-sub"><?php echo $row['last_at'] ? zan_h(substr($row['last_at'], 0, 16)) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="za-card">
    <div class="za-card-head"><h2>接入代码</h2></div>
    <p class="za-desc">贴到文章页模板里（容器放关注位置，脚本放页面底部或 <code>&lt;/body&gt;</code> 前）。
        只有带 <code>data-zan-scope="content"</code> 的容器才能点赞，列表页、侧栏的容器一律只读。
        统计是全站自动上报的，不需要额外容器；<code>data-zan-view</code> 只是把这一页的数字显示出来。</p>
    <textarea class="za-code" rows="4" readonly onclick="this.select()"><?php echo zan_h($snippet); ?></textarea>
</div>

<?php zan_admin_footer(); ?>
