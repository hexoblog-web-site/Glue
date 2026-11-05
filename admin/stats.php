<?php
/**
 * 统计：全站访问趋势、来源、页面、浏览器 / 系统 / 设备 / 语言 / 地区。
 *
 * 写入模型与 Umami 一致（事件明细 + session），这里只做查询侧聚合：
 *   浏览次数 = 每个访问的事件数之和
 *   访客数   = COUNT(DISTINCT session_id)
 *   访问次数 = COUNT(DISTINCT visit_id)
 *   跳出率   = 只有一条事件的访问 / 访问次数
 *   平均停留 = 每条访问的 (max_time - min_time) 之和 / 访问次数
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require_once dirname(__DIR__) . '/include/schema.php';
zan_require_admin();
$pdo = zan_db_or_die();
zan_stats_bootstrap($pdo);

$range = isset($_GET['range']) ? (string) $_GET['range'] : '7';
if (!in_array($range, array('today', '7', '30', 'all'), true)) {
    $range = '7';
}

$now = time();
if ($range === 'today') {
    $from  = date('Y-m-d 00:00:00', $now);
    $to    = date('Y-m-d 23:59:59', $now);
    $days  = 1;
} elseif ($range === '30') {
    $from  = date('Y-m-d 00:00:00', strtotime('-29 day', $now));
    $to    = date('Y-m-d 23:59:59', $now);
    $days  = 30;
} elseif ($range === 'all') {
    $from  = '1970-01-01 00:00:00';
    $to    = date('Y-m-d 23:59:59', $now);
    $days  = 30;
} else {
    $from  = date('Y-m-d 00:00:00', strtotime('-6 day', $now));
    $to    = date('Y-m-d 23:59:59', $now);
    $days  = 7;
}

$site   = zan_site_stats($pdo, $from, $to);
$active = zan_stats_active($pdo, 5);

/* 趋势：图表固定看最近 N 天，选择「全部」时也只画 30 天，避免柱子挤成一团 */
$chartDays = array();
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime('-' . $i . ' day', $now));
    $chartDays[$d] = array('pv' => 0, 'uv' => 0, 'visits' => 0);
}
$seriesFrom = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' day', $now));
$series = zan_stats_series($pdo, $seriesFrom, $to);
foreach ($series as $d => $row) {
    if (isset($chartDays[$d])) {
        $chartDays[$d] = $row;
    }
}
$peak = 1;
foreach ($chartDays as $row) {
    $peak = max($peak, $row['pv'], $row['uv']);
}

/* 页面排行带标题 */
$topPages = array();
if (zan_stats_ready()) {
    try {
        $stmt = $pdo->prepare('SELECT e.`url_path`, MAX(e.`page_title`) AS title, COUNT(*) AS pv,'
            . ' COUNT(DISTINCT e.`session_id`) AS uv'
            . ' FROM ' . zan_table('event') . ' e'
            . ' WHERE e.`created_at` BETWEEN ? AND ? AND e.`event_type` = 1'
            . ' GROUP BY e.`url_path` ORDER BY pv DESC LIMIT 10');
        $stmt->execute(array($from, $to));
        $topPages = $stmt->fetchAll();
    } catch (PDOException $e) {
    }
}

$referrers = zan_stats_breakdown($pdo, 'referrer_domain', $from, $to, 8);
$browsers  = zan_stats_breakdown($pdo, 'browser', $from, $to, 8);
$systems   = zan_stats_breakdown($pdo, 'os', $from, $to, 8);
$devices   = zan_stats_breakdown($pdo, 'device', $from, $to, 8);
$langs     = zan_stats_breakdown($pdo, 'language', $from, $to, 8);
$countries = zan_stats_breakdown($pdo, 'country', $from, $to, 8);

function zan_duration($sec)
{
    $sec = max(0, (int) $sec);
    if ($sec < 60) {
        return $sec . ' 秒';
    }
    $m = (int) floor($sec / 60);
    $s = $sec % 60;
    if ($m < 60) {
        return $m . ' 分' . ($s > 0 ? ' ' . $s . ' 秒' : '');
    }
    $h = (int) floor($m / 60);
    return $h . ' 小时 ' . ($m % 60) . ' 分';
}

$bounceRate = $site['visits'] > 0 ? round($site['bounces'] / $site['visits'] * 100) : 0;
$avgTime    = $site['visits'] > 0 ? round($site['totaltime'] / $site['visits']) : 0;

/** 三列小表：名称 / 浏览 / 访客 */
function zan_stats_table(array $rows, $empty = '还没有数据')
{
    if (!$rows) {
        echo '<p class="za-empty">' . zan_h($empty) . '</p>';
        return;
    }
    echo '<table class="za-table"><thead><tr><th>名称</th><th class="num">浏览</th><th class="num">访客</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><code>' . zan_h($r['label']) . '</code></td><td class="num">' . (int) $r['pv']
            . '</td><td class="num">' . (int) $r['uv'] . '</td></tr>';
    }
    echo '</tbody></table>';
}

zan_admin_header('网站统计', 'stats.php');
?>

<div class="za-range">
    <?php foreach (array('today' => '今天', '7' => '最近 7 天', '30' => '最近 30 天', 'all' => '全部') as $k => $label) : ?>
        <a href="<?php echo zan_h(zan_url('admin/stats.php?range=' . $k)); ?>" class="<?php echo $range === $k ? 'is-active' : ''; ?>"><?php echo zan_h($label); ?></a>
    <?php endforeach; ?>
</div>

<div class="za-cards">
    <div class="za-card za-stat"><span>浏览次数</span><strong><?php echo $site['pv']; ?></strong></div>
    <div class="za-card za-stat"><span>访客数</span><strong><?php echo $site['uv']; ?></strong></div>
    <div class="za-card za-stat"><span>访问次数</span><strong><?php echo $site['visits']; ?></strong></div>
    <div class="za-card za-stat"><span>跳出率</span><strong><?php echo $bounceRate; ?><small style="font-size:15px">%</small></strong></div>
    <div class="za-card za-stat"><span>平均停留</span><strong class="sm"><?php echo zan_h(zan_duration($avgTime)); ?></strong></div>
    <div class="za-card za-stat"><span>当前在线 · 5 分钟</span><strong><?php echo $active; ?></strong></div>
</div>

<p class="za-desc" style="margin:-4px 0 16px">
    统计覆盖全站所有页面（首页、归档、标签页都算），爬虫、预取、后台标签页不计。
    访客 = IP + 浏览器 UA + 当期盐，浏览器不存标识。
</p>

<div class="za-card">
    <div class="za-card-head">
        <h2><?php echo $days; ?> 天趋势</h2>
        <span class="za-legend"><i></i>浏览次数<b></b>访客数</span>
    </div>
    <div class="za-chart">
        <?php $bi = 0; foreach ($chartDays as $day => $row) : ?>
            <?php
            $ph = max(2, (int) round($row['pv'] / $peak * 78));
            $uh = max(2, (int) round($row['uv'] / $peak * 78));
            $showLabel = $days <= 10 || $bi % 5 === 0 || $bi === $days - 1;
            $bi++;
            ?>
            <div class="za-bar" title="<?php echo zan_h($day . '：浏览 ' . $row['pv'] . '，访客 ' . $row['uv'] . '，访问 ' . $row['visits']); ?>">
                <div class="za-pair">
                    <i style="height:<?php echo $ph; ?>px"></i>
                    <b style="height:<?php echo $uh; ?>px"></b>
                </div>
                <span><?php echo $showLabel ? zan_h(substr($day, 5)) : ''; ?></span>
                <?php if ($days <= 10) : ?><em><?php echo (int) $row['pv']; ?></em><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="za-card">
    <div class="za-card-head"><h2>页面</h2><span class="za-pill">浏览 / 访客</span></div>
    <?php if (!$topPages) : ?>
        <p class="za-empty">这段时间还没有记录。打开一次博客页面就会出现。</p>
    <?php else : ?>
    <table class="za-table">
        <thead><tr><th>页面</th><th class="num">浏览</th><th class="num">访客</th></tr></thead>
        <tbody>
        <?php foreach ($topPages as $row) : ?>
            <tr>
                <td>
                    <a href="<?php echo zan_h(zan_url('admin/list.php?key=' . urlencode($row['url_path']))); ?>"><?php echo zan_h($row['title'] !== '' ? $row['title'] : $row['url_path']); ?></a>
                    <?php if ($row['title'] !== '') : ?><em class="za-sub"><?php echo zan_h($row['url_path']); ?></em><?php endif; ?>
                </td>
                <td class="num"><?php echo (int) $row['pv']; ?></td>
                <td class="num"><?php echo (int) $row['uv']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="za-grid2">
    <div class="za-card">
        <div class="za-card-head"><h2>来源</h2></div>
        <?php zan_stats_table($referrers, '没有外部来源（直接访问）'); ?>
    </div>
    <div class="za-card">
        <div class="za-card-head"><h2>浏览器</h2></div>
        <?php zan_stats_table($browsers); ?>
    </div>
    <div class="za-card">
        <div class="za-card-head"><h2>操作系统</h2></div>
        <?php zan_stats_table($systems); ?>
    </div>
    <div class="za-card">
        <div class="za-card-head"><h2>设备</h2></div>
        <?php zan_stats_table($devices); ?>
    </div>
    <div class="za-card">
        <div class="za-card-head"><h2>语言</h2></div>
        <?php zan_stats_table($langs); ?>
    </div>
    <div class="za-card">
        <div class="za-card-head"><h2>地区</h2></div>
        <?php if (!$countries) : ?>
            <p class="za-empty">套 Cloudflare 后自动有国家；没套时不知道地区。</p>
        <?php else : ?>
            <?php zan_stats_table($countries); ?>
        <?php endif; ?>
    </div>
</div>

<?php zan_admin_footer(); ?>
