<?php
/**
 * 概览：点赞总数、今日赞、最近 7 天曲线、页面排行、接入代码。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require_once dirname(__DIR__) . '/include/schema.php';
zan_require_admin();
$pdo = zan_db_or_die();

$total = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('log'))->fetchColumn();
$pages = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('page'))->fetchColumn();
$today = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('log') . ' WHERE `created_at` >= CURDATE()')->fetchColumn();

/* 最近 7 天：只统计赞 */
$days = array();
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime('-' . $i . ' day'));
    $days[$d] = 0;
}
$stmt = $pdo->prepare('SELECT DATE(`created_at`) AS d, COUNT(*) AS c FROM ' . zan_table('log')
    . ' WHERE `created_at` >= ? GROUP BY DATE(`created_at`)');
$stmt->execute(array(date('Y-m-d', strtotime('-6 day')) . ' 00:00:00'));
foreach ($stmt->fetchAll() as $row) {
    if (isset($days[$row['d']])) {
        $days[$row['d']] = (int) $row['c'];
    }
}
$peak = max(1, max($days));

$rank = zan_page_rank_cols('p');
$top  = $pdo->query('SELECT p.`page_key`, p.`title`,'
    . ' ' . $rank['likes'] . ' AS likes,'
    . ' ' . $rank['last_at'] . ' AS last_at'
    . ' FROM ' . zan_table('page') . ' p'
    . ' ORDER BY likes DESC, p.`updated_at` DESC LIMIT 10')->fetchAll();

$server  = zan_abs_url('');
$snippet = '<div data-zan data-zan-scope="content"></div>' . "\n"
    . '<script src="' . $server . '/api/js/zan.js" data-server="' . $server . '" defer></script>';

zan_admin_header('仪表盘', 'index.php');
?>

<div class="za-cards">
    <div class="za-card za-stat"><span>总赞数</span><strong><?php echo $total; ?></strong></div>
    <div class="za-card za-stat"><span>今日赞</span><strong><?php echo $today; ?></strong></div>
    <div class="za-card za-stat"><span>有点赞的页面</span><strong><?php echo $pages; ?></strong></div>
</div>

<p class="za-desc" style="margin:-4px 0 16px">
    本服务只记录点赞，不采集浏览、访客或来源信息。
    · <a href="<?php echo zan_h(zan_url('admin/list.php')); ?>">看每篇文章的赞数 →</a>
</p>

<div class="za-card">
    <div class="za-card-head">
        <h2>最近 7 天</h2>
        <span class="za-legend"><i></i>赞</span>
    </div>
    <div class="za-chart">
        <?php foreach ($days as $day => $count) : ?>
            <?php $lh = max(2, (int) round($count / $peak * 78)); ?>
            <div class="za-bar" title="<?php echo zan_h($day . '：赞 ' . $count); ?>">
                <div class="za-pair">
                    <i style="height:<?php echo $lh; ?>px"></i>
                </div>
                <span><?php echo zan_h(substr($day, 5)); ?></span>
                <em><?php echo $count; ?></em>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="za-card">
    <div class="za-card-head"><h2>页面排行</h2></div>
    <?php if (!$top) : ?>
        <p class="za-empty">还没有数据。把下面的代码贴到文章页，有人点一次就会出现。</p>
    <?php else : ?>
    <table class="za-table">
        <thead><tr><th>页面</th><th class="num">赞</th><th class="num">最近</th></tr></thead>
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
        只有带 <code>data-zan-scope="content"</code> 的容器才能点赞；列表页、侧栏的容器去掉这个属性即为只读。</p>
    <textarea class="za-code" rows="3" readonly onclick="this.select()"><?php echo zan_h($snippet); ?></textarea>
</div>

<?php zan_admin_footer(); ?>
