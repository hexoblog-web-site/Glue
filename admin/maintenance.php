<?php
/**
 * 数据备份：备份下载、上传恢复、清空数据。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require_once dirname(__DIR__) . '/include/schema.php';
require dirname(__DIR__) . '/include/backup-lib.php';
zan_require_admin();
$pdo = zan_db_or_die();

$notice = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = zan_post_str('action');

    /* 所有动作（包括「下载备份」）都先验 CSRF：
       下载会把全部点赞记录打到浏览器，不能靠一个跨站表单就能触发。 */
    if (!zan_csrf_ok(zan_post_str('nonce'))) {
        $notice = array('页面已过期，请刷新后重试。', 'error');
    } elseif ($action === 'backup') {
        /* 备份：直接下载，不在服务器留副本 */
        zan_bk_send($pdo);
        exit;
    } elseif ($action === 'repair') {
        /* 全部是 CREATE TABLE IF NOT EXISTS，已有表不动，只补缺的 */
        try {
            zan_create_tables($pdo, (string) zan_cfg('table_prefix', 'zan_'));
            header('Location: ' . zan_url('admin/maintenance.php?done=repair'));
            exit;
        } catch (PDOException $e) {
            $notice = array('建表失败：' . zan_h($e->getMessage()), 'error');
        }
    } elseif ($action === 'restore') {
        $mode    = isset($_POST['mode']) ? (string) $_POST['mode'] : 'merge';
        $confirm = isset($_POST['confirm']) ? trim((string) $_POST['confirm']) : '';

        if ($confirm !== 'RESTORE') {
            $notice = array('请在确认框里原样输入 RESTORE。', 'error');
        } else {
            try {
                $data = zan_bk_read_upload('file');
                zan_bk_apply($pdo, $data, $mode);
                header('Location: ' . zan_url('admin/maintenance.php?done=restore'));
                exit;
            } catch (RuntimeException $e) {
                $notice = array(zan_h($e->getMessage()), 'error');
            } catch (PDOException $e) {
                $notice = array('导入时数据库出错：' . zan_h($e->getMessage()), 'error');
            }
        }
    } elseif ($action === 'wipe') {
        $scope   = isset($_POST['scope']) ? (string) $_POST['scope'] : 'log';
        $confirm = isset($_POST['confirm']) ? trim((string) $_POST['confirm']) : '';

        if ($confirm !== 'DELETE') {
            $notice = array('请在确认框里原样输入 DELETE。', 'error');
        } else {
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM ' . zan_table('log'));
            if ($scope === 'all') {
                $pdo->exec('DELETE FROM ' . zan_table('page'));
            }
            $pdo->commit();
            header('Location: ' . zan_url('admin/maintenance.php?done=wipe&scope=' . urlencode($scope)));
            exit;
        }
    }
}

if (isset($_GET['done'])) {
    if ($_GET['done'] === 'restore') {
        $notice = array('数据已导入。', 'ok');
    } elseif ($_GET['done'] === 'repair') {
        $notice = array('表结构已补齐（已存在的表没有改动）。', 'ok');
    } elseif ($_GET['done'] === 'wipe') {
        $scope  = isset($_GET['scope']) ? $_GET['scope'] : 'log';
        $notice = array($scope === 'all' ? '点赞记录和页面列表都已清空。' : '点赞记录已清空，页面列表保留。', 'ok');
    }
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('log'))->fetchColumn();
$pages = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('page'))->fetchColumn();

/* 上传上限：取 upload_max_filesize 与 post_max_size 的较小值 */
function zan_ini_bytes($v)
{
    $v   = trim((string) $v);
    $num = (int) $v;
    $unit = strtolower(substr($v, -1));
    if ($unit === 'g') {
        return $num * 1073741824;
    }
    if ($unit === 'm') {
        return $num * 1048576;
    }
    if ($unit === 'k') {
        return $num * 1024;
    }
    return $num;
}

$up1    = zan_ini_bytes(ini_get('upload_max_filesize'));
$up2    = zan_ini_bytes(ini_get('post_max_size'));
$limit  = min($up1 ?: ZAN_BK_MAX, $up2 ?: ZAN_BK_MAX, ZAN_BK_MAX);
$limitH = $limit >= 1048576 ? round($limit / 1048576, 1) . ' MB' : round($limit / 1024) . ' KB';

zan_admin_header('数据备份', 'maintenance.php');
if ($notice) {
    zan_notice($notice[0], $notice[1]);
}
?>
<div class="za-cards">
    <div class="za-card za-stat"><span>点赞记录</span><strong><?php echo $total; ?></strong></div>
    <div class="za-card za-stat"><span>页面</span><strong><?php echo $pages; ?></strong></div>
    <div class="za-card za-stat"><span>上传上限</span><strong class="sm"><?php echo zan_h($limitH); ?></strong></div>
</div>

<div class="za-card">
    <div class="za-card-head"><h2>备份</h2></div>
    <p class="za-desc">导出成 JSON 下载到本地。备份包含页面标题与点赞记录，不含管理员密码。</p>
    <form method="post" action="<?php echo zan_h(zan_url('admin/maintenance.php')); ?>">
        <?php echo zan_csrf_field(); ?>
        <input type="hidden" name="action" value="backup" />
        <button type="submit" class="za-btn is-primary">下载备份</button>
    </form>
</div>

<div class="za-card is-danger">
    <div class="za-card-head"><h2>恢复</h2></div>
    <form method="post" action="<?php echo zan_h(zan_url('admin/maintenance.php')); ?>" enctype="multipart/form-data">
        <?php echo zan_csrf_field(); ?>
        <input type="hidden" name="action" value="restore" />

        <label for="file">备份文件（.json）</label>
        <input id="file" name="file" type="file" accept="application/json,.json" required />

        <label>方式</label>
        <label class="za-radio"><input type="radio" name="mode" value="merge" checked /> 合并（跳过已存在的记录，数字不会翻倍）</label>
        <label class="za-radio"><input type="radio" name="mode" value="replace" /> 覆盖（先清空现有数据再导入）</label>

        <label for="confirm">输入 <code>RESTORE</code> 确认</label>
        <input id="confirm" name="confirm" type="text" autocomplete="off" placeholder="RESTORE" required />

        <p><button type="submit" class="za-btn is-danger">开始导入</button></p>
    </form>
</div>

<div class="za-card is-danger">
    <div class="za-card-head"><h2>清空数据</h2></div>
    <form method="post" action="<?php echo zan_h(zan_url('admin/maintenance.php')); ?>">
        <?php echo zan_csrf_field(); ?>
        <input type="hidden" name="action" value="wipe" />

        <label class="za-radio"><input type="radio" name="scope" value="log" checked /> 只清空点赞记录（页面列表保留）</label>
        <label class="za-radio"><input type="radio" name="scope" value="all" /> 连页面列表一起清空（回到刚装好的状态）</label>

        <label for="del">输入 <code>DELETE</code> 确认</label>
        <input id="del" name="confirm" type="text" autocomplete="off" placeholder="DELETE" required />

        <p class="za-desc">管理员账号、站点设置不会被删。清空前建议先下载一份备份。</p>
        <p><button type="submit" class="za-btn is-danger">清空</button></p>
    </form>
</div>
<?php zan_admin_footer(); ?>
