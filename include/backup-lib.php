<?php
/**
 * 备份与恢复（JSON）。
 *
 * 不用 mysqldump、不用 exec、不用 ZipArchive —— 只依赖 PHP 自带的 json，
 * 任何虚拟主机都能跑。备份里只有页面标题与点赞记录，不含管理员密码。
 */
if (!defined('ZAN_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

define('ZAN_BK_FORMAT', 'zan-backup');
define('ZAN_BK_VERSION', 3);
define('ZAN_BK_MAX', 24 * 1024 * 1024);

function zan_bk_export(PDO $pdo)
{
    $pages = $pdo->query('SELECT `page_key`, `title`, `created_at`, `updated_at` FROM ' . zan_table('page')
        . ' ORDER BY `page_key`')->fetchAll();
    $logs  = $pdo->query('SELECT `page_key`, `visitor_hash`, `created_at` FROM ' . zan_table('log')
        . ' ORDER BY `id`')->fetchAll();

    return array(
        'format'      => ZAN_BK_FORMAT,
        'version'     => ZAN_BK_VERSION,
        'app_version' => ZAN_VERSION,
        'exported_at' => date('c'),
        'counts'      => array('page' => count($pages), 'log' => count($logs)),
        'tables'      => array('page' => $pages, 'log' => $logs),
    );
}

function zan_bk_json(PDO $pdo)
{
    return json_encode(zan_bk_export($pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function zan_bk_filename()
{
    return 'sugar-box-backup-' . date('Ymd-His') . '.json';
}

/** 直接推给浏览器下载，不落盘 */
function zan_bk_send(PDO $pdo)
{
    $json = zan_bk_json($pdo);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . zan_bk_filename() . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $json;
}

/**
 * 解析上传的备份内容，返回数组或抛 RuntimeException（消息可直接展示）。
 */
function zan_bk_decode($raw)
{
    $raw = (string) $raw;
    if ($raw === '') {
        throw new RuntimeException('文件是空的。');
    }
    if (strlen($raw) > ZAN_BK_MAX) {
        throw new RuntimeException('文件超过 24MB，无法导入。');
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('不是有效的 JSON 文件。');
    }
    if (!isset($data['format']) || $data['format'] !== ZAN_BK_FORMAT) {
        throw new RuntimeException('这不是本程序的备份文件。');
    }
    if (!isset($data['tables']) || !is_array($data['tables'])) {
        throw new RuntimeException('备份内容不完整。');
    }
    /* 逐项确认是数组：只判断 isset 的话，tables.page 是个字符串也能过，
       后面 foreach 会先报一堆 warning 再往库里写脏数据 */
    foreach (array('page', 'log') as $need) {
        if (!isset($data['tables'][$need]) || !is_array($data['tables'][$need])) {
            throw new RuntimeException('备份内容不完整（缺少 ' . $need . ' 表）。');
        }
    }
    return $data;
}

/** 读上传文件，返回备份数组 */
function zan_bk_read_upload($field)
{
    if (empty($_FILES[$field])) {
        throw new RuntimeException('没有收到文件。');
    }
    $f = $_FILES[$field];
    if (!empty($f['error'])) {
        $map = array(
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器 upload_max_filesize 限制。',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制。',
            UPLOAD_ERR_PARTIAL    => '文件只上传了一部分。',
            UPLOAD_ERR_NO_FILE    => '没有选择文件。',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录。',
            UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件。',
            UPLOAD_ERR_EXTENSION  => '上传被扩展阻止。',
        );
        throw new RuntimeException(isset($map[$f['error']]) ? $map[$f['error']] : '上传失败。');
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('上传的文件不可读。');
    }
    return zan_bk_decode(file_get_contents($f['tmp_name']));
}

/**
 * 写回数据库。
 *
 * merge   —— 已有的跳过（数字不会翻倍）
 * replace —— 先清空再导入
 *
 * 旧版本（2.x，带统计）的备份里还有 session / event 表，这里直接忽略：
 * 本程序不存这些数据，导入时只取 page 与 log。
 */
function zan_bk_apply(PDO $pdo, array $data, $mode)
{
    $mode = $mode === 'replace' ? 'replace' : 'merge';

    $pdo->beginTransaction();
    try {
        if ($mode === 'replace') {
            $pdo->exec('DELETE FROM ' . zan_table('log'));
            $pdo->exec('DELETE FROM ' . zan_table('page'));
        }

        $insPage = $pdo->prepare('INSERT INTO ' . zan_table('page')
            . ' (`page_key`, `title`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `title` = IF(VALUES(`title`) = \'\', `title`, VALUES(`title`)), `updated_at` = VALUES(`updated_at`)');
        $insLog = $pdo->prepare('INSERT IGNORE INTO ' . zan_table('log')
            . ' (`page_key`, `visitor_hash`, `uniq`, `created_at`) VALUES (?, ?, ?, ?)');

        $addedLog = 0;
        $now      = date('Y-m-d H:i:s');

        foreach ($data['tables']['page'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (empty($row['page_key'])) {
                continue;
            }
            $insPage->execute(array(
                (string) $row['page_key'],
                isset($row['title']) ? (string) $row['title'] : '',
                isset($row['created_at']) ? (string) $row['created_at'] : $now,
                isset($row['updated_at']) ? (string) $row['updated_at'] : $now,
            ));
        }

        foreach ($data['tables']['log'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (empty($row['page_key']) || empty($row['visitor_hash'])) {
                continue;
            }
            $key  = (string) $row['page_key'];
            $hash = (string) $row['visitor_hash'];
            $insLog->execute(array(
                $key,
                $hash,
                hash('sha256', $key . '|' . $hash),
                isset($row['created_at']) ? (string) $row['created_at'] : $now,
            ));
            $addedLog += $insLog->rowCount();
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('导入失败：' . $e->getMessage());
    }

    $total = (int) $pdo->query('SELECT COUNT(*) FROM ' . zan_table('log'))->fetchColumn();
    return array('mode' => $mode, 'added' => $addedLog, 'total' => $total);
}
