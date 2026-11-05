<?php
/**
 * 点赞明细：按页面汇总，点进某个页面看记录；可删除单个页面的数据。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require_once dirname(__DIR__) . '/include/schema.php';
zan_require_admin();
$pdo = zan_db_or_die();
zan_stats_bootstrap($pdo);

$notice = '';

/* 删除某个页面的全部记录 */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_page') {
    $key = zan_norm_key(isset($_POST['key']) ? $_POST['key'] : '');
    if (!zan_csrf_ok(isset($_POST['nonce']) ? $_POST['nonce'] : '')) {
        $notice = array('页面已过期，请刷新后重试。', 'error');
    } elseif ($key === '') {
        $notice = array('文章标识无效。', 'error');
    } else {
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM ' . zan_table('log') . ' WHERE `page_key` = ?');
        $del->execute(array($key));
        $removed = $del->rowCount();
        $removedViews = 0;
        if (zan_stats_ready()) {
            $dv = $pdo->prepare('DELETE FROM ' . zan_table('event') . ' WHERE `url_path` = ?');
            $dv->execute(array($key));
            $removedViews = $dv->rowCount();
        }
        $pdo->prepare('DELETE FROM ' . zan_table('page') . ' WHERE `page_key` = ?')->execute(array($key));
        $pdo->commit();
        header('Location: ' . zan_url('admin/list.php?done=' . $removed . '&views=' . $removedViews));
        exit;
    }
}

if (isset($_GET['done'])) {
    $msg = '已删除该页面的 ' . (int) $_GET['done'] . ' 条点赞记录';
    if (isset($_GET['views']) && (int) $_GET['views'] > 0) {
        $msg .= '、' . (int) $_GET['views'] . ' 条浏览记录';
    }
    $notice = array($msg . '。', 'ok');
}

$q       = trim(zan_get_str('q'));
$detail  = zan_norm_key(zan_get_str('key'));
$pageNo  = max(1, (int) zan_get_str('p', '1'));
$perPage = 20;

zan_admin_header($detail !== '' ? '页面记录' : '点赞统计', 'list.php');

/* ---------------------------------------------------------------- 单页详情 */
if ($detail !== '') {
    $stmt = $pdo->prepare('SELECT `page_key`, `title`, `created_at`, `updated_at` FROM ' . zan_table('page')
        . ' WHERE `page_key` = ? LIMIT 1');
    $stmt->execute(array($detail));
    $page = $stmt->fetch();

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM ' . zan_table('log') . ' WHERE `page_key` = ?');
    $cnt->execute(array($detail));
    $likes = (int) $cnt->fetchColumn();

    $stat = zan_page_stats($pdo, $detail);
    $views    = $stat['pv'];
    $visitors = $stat['uv'];
    $visits   = $stat['visits'];

    $logs = $pdo->prepare('SELECT `visitor_hash`, `created_at` FROM ' . zan_table('log')
        . ' WHERE `page_key` = ? ORDER BY `id` DESC LIMIT 200');
    $logs->execute(array($detail));

    if ($notice) {
        zan_notice(zan_h($notice[0]), $notice[1]);
    }
    ?>
    <p class="za-back"><a href="<?php echo zan_h(zan_url('admin/list.php')); ?>">← 返回列表</a></p>

    <div class="za-card">
        <div class="za-card-head">
            <h2><?php echo zan_h($page && $page['title'] !== '' ? $page['title'] : $detail); ?></h2>
            <span class="za-pill"><?php echo $views; ?> 浏览 · <?php echo $visitors; ?> 访客 · <?php echo $likes; ?> 赞</span>
        </div>
        <table class="za-kv">
            <tr><th>浏览 / 访客 / 赞</th><td><?php echo $views; ?> / <?php echo $visitors; ?> / <?php echo $likes; ?></td></tr>
            <tr><th>访问次数</th><td><?php echo $visits; ?></td></tr>
            <tr><th>页面标识</th><td><code><?php echo zan_h($detail); ?></code></td></tr>
            <tr><th>首次记录</th><td><?php echo $page ? zan_h($page['created_at']) : '—'; ?></td></tr>
            <tr><th>最近点赞</th><td><?php echo $page ? zan_h($page['updated_at']) : '—'; ?></td></tr>
        </table>
    </div>

    <div class="za-card">
        <div class="za-card-head"><h2>最近 200 条</h2></div>
        <?php if ($likes === 0) : ?>
            <p class="za-empty">这个页面还没有记录。</p>
        <?php else : ?>
        <table class="za-table">
            <thead><tr><th>时间</th><th>访客指纹（前 12 位）</th></tr></thead>
            <tbody>
            <?php foreach ($logs->fetchAll() as $row) : ?>
                <tr>
                    <td><?php echo zan_h($row['created_at']); ?></td>
                    <td><code><?php echo zan_h(substr($row['visitor_hash'], 0, 12)); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="za-card is-danger">
        <div class="za-card-head"><h2>删除这个页面的数据</h2></div>
        <p class="za-desc">会删掉 <?php echo $likes; ?> 条点赞记录、<?php echo $views; ?> 条浏览记录和这个页面的标题，不可恢复。建议先备份。</p>
        <form method="post" action="<?php echo zan_h(zan_url('admin/list.php')); ?>"
              onsubmit="return confirm('确定删除这个页面的全部点赞记录？');">
            <?php echo zan_csrf_field(); ?>
            <input type="hidden" name="action" value="delete_page" />
            <input type="hidden" name="key" value="<?php echo zan_h($detail); ?>" />
            <button type="submit" class="za-btn is-danger">删除该页面数据</button>
        </form>
    </div>
    <?php
    zan_admin_footer();
    exit;
}

/* ---------------------------------------------------------------- 列表 */
$where  = '';
$params = array();
if ($q !== '') {
    /* 用户输入的 % 和 _ 是字面量，不是通配符；不转义的话搜 "%" 会一次列出全表 */
    $like   = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
    $where  = ' WHERE (`page_key` LIKE ? OR `title` LIKE ?)';
    $params = array($like, $like);
}

$totalRows = $pdo->prepare('SELECT COUNT(*) FROM ' . zan_table('page') . $where);
$totalRows->execute($params);
$totalPages = max(1, (int) ceil((int) $totalRows->fetchColumn() / $perPage));
$pageNo     = min($pageNo, $totalPages);
$offset     = ($pageNo - 1) * $perPage;

$rank = zan_page_rank_cols('p');

$sql = 'SELECT p.`page_key`, p.`title`,'
    . ' ' . $rank['likes'] . ' AS likes,'
    . ' ' . $rank['views'] . ' AS views,'
    . ' ' . $rank['visitors'] . ' AS visitors,'
    . ' ' . $rank['last_at'] . ' AS last_at'
    . ' FROM ' . zan_table('page') . ' p'
    . ($where !== '' ? str_replace(array('`page_key`', '`title`'), array('p.`page_key`', 'p.`title`'), $where) : '')
    . ' ORDER BY views DESC, likes DESC, p.`updated_at` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($notice) {
    zan_notice(zan_h($notice[0]), $notice[1]);
}
?>
<form class="za-filter" method="get" action="<?php echo zan_h(zan_url('admin/list.php')); ?>">
    <input type="search" name="q" value="<?php echo zan_h($q); ?>" placeholder="按地址或标题搜索" />
    <button type="submit" class="za-btn is-primary">搜索</button>
    <?php if ($q !== '') : ?>
        <a class="za-link" href="<?php echo zan_h(zan_url('admin/list.php')); ?>">清除</a>
    <?php endif; ?>
</form>

<div class="za-card">
    <?php if (!$rows) : ?>
        <p class="za-empty">没有匹配的页面。有人打开文章后这里才会有数据。</p>
    <?php else : ?>
    <table class="za-table">
        <thead><tr><th>页面</th><th class="num">浏览</th><th class="num">访客</th><th class="num">赞</th><th class="num">最近</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
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

    <?php if ($totalPages > 1) : ?>
    <div class="za-pager">
        <?php
        $base = zan_url('admin/list.php') . '?q=' . urlencode($q) . '&p=';
        ?>
        <?php if ($pageNo > 1) : ?>
            <a href="<?php echo zan_h($base . ($pageNo - 1)); ?>">上一页</a>
        <?php endif; ?>
        <span>第 <?php echo $pageNo; ?> / <?php echo $totalPages; ?> 页</span>
        <?php if ($pageNo < $totalPages) : ?>
            <a href="<?php echo zan_h($base . ($pageNo + 1)); ?>">下一页</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php zan_admin_footer(); ?>
