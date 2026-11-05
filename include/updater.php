<?php
/**
 * 自动更新：把 GitHub 仓库当分发服务器
 *
 * 流程：比对最新提交与版本号 → 下载该提交的 zip → 只覆盖程序区 → 失败整批回滚。
 * 内容区（config.php、data/、install/、reset-password.php）一根指头都不碰。
 *
 * 几条硬规则，改代码时别破坏：
 *   · 只走 https，只认 github.com / api.github.com / codeload.github.com，地址全部由本文件拼，不接受外部传入
 *   · 更新前先备份程序区，任何一步失败整批回滚，不留半新半旧
 *   · 解压前校验路径，拒绝 ../、绝对路径、以及白名单之外的任何文件
 *   · 远程版本低于本地一律拒绝，避免手滑把站点降级
 *
 * 状态存在 data/update.json，开关存在 config.php 的 update_* 项。
 */
if (!defined('ZAN_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/* 解压依赖自带的 ZIP 读取器。放在这里 require，任何人引入更新器都不会缺类
   （以前靠 update.php / cron.php 各自引入，换入口就报 Zan_Zip_Reader not found）。 */
require_once __DIR__ . '/zipreader.php';

/* ---------------------------------------------------------------------------
 * 目录与状态
 * ------------------------------------------------------------------------- */

/** data/ 下的路径（PHP 在 Windows 上也认 /，统一用斜杠，省得界面上出现混合分隔符） */
function zan_upd_data($sub = '')
{
    return rtrim(ZAN_ROOT, '/\\') . '/data/' . $sub;
}

function zan_upd_state_file()
{
    return zan_upd_data('update.json');
}

/** 建目录（递归），并顺手把 data/ 的访问守卫写上 */
function zan_upd_mkdir($dir)
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    zan_upd_guard_data();
    return true;
}

/**
 * data/ 里会躺源码备份，别让浏览器直接下到
 *
 * Apache 靠 .htaccess；nginx 不认，README 里给了 deny 片段。目录本身不存在时什么都不做。
 */
function zan_upd_guard_data()
{
    $root = rtrim(ZAN_ROOT, '/\\') . '/data';
    if (!is_dir($root)) {
        return;
    }
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $idx = $root . '/index.php';
    if (!is_file($idx)) {
        @file_put_contents($idx, "<?php\nhttp_response_code(403);\nexit('Forbidden');\n");
    }
}

/** 原子写：先写临时文件再 rename */
function zan_upd_write_atomic($file, $content)
{
    if (!zan_upd_mkdir(dirname($file))) {
        return false;
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $content) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0644);
    return true;
}

/** 读状态，永不出错：文件坏了就当空状态 */
function zan_upd_state_read()
{
    $out = array();
    $f   = zan_upd_state_file();
    if (is_file($f)) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j)) {
            $out = $j;
        }
    }
    return $out;
}

function zan_upd_state_write($state)
{
    return zan_upd_write_atomic(
        zan_upd_state_file(),
        json_encode((array) $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

/* ---------------------------------------------------------------------------
 * 配置
 * ------------------------------------------------------------------------- */

function zan_upd_repo_valid($r)
{
    return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', (string) $r);
}

/** 仓库 owner/repo；不合法返回空串 */
function zan_upd_repo()
{
    $r = trim((string) zan_cfg('update_repo', ''));
    return zan_upd_repo_valid($r) ? $r : '';
}

function zan_upd_branch()
{
    $b = trim((string) zan_cfg('update_branch', 'main'));
    if ($b === '' || strlen($b) > 100 || strpos($b, '..') !== false || !preg_match('#^[A-Za-z0-9._/-]+$#', $b)) {
        return 'main';
    }
    return $b;
}

function zan_upd_auto_check()
{
    return (string) zan_cfg('update_auto_check', '1') === '1';
}

function zan_upd_auto_apply()
{
    return (string) zan_cfg('update_auto_apply', '0') === '1';
}

/** 检查间隔（秒），1～168 小时 */
function zan_upd_interval()
{
    return max(1, min(168, (int) zan_cfg('update_interval', '6'))) * 3600;
}

/** 自动检查是否到点（只读本地状态，不联网） */
function zan_upd_auto_due()
{
    if (!zan_upd_auto_check()) {
        return false;
    }
    $s = zan_upd_state_read();
    return empty($s['checked_at']) || (time() - (int) $s['checked_at']) >= zan_upd_interval();
}

/** 下次自动检查的时间戳；从没查过或已关闭返回 0 */
function zan_upd_next_check_at()
{
    if (!zan_upd_auto_check()) {
        return 0;
    }
    $s = zan_upd_state_read();
    return empty($s['checked_at']) ? 0 : (int) $s['checked_at'] + zan_upd_interval();
}

/* ---------------------------------------------------------------------------
 * 通知
 * ------------------------------------------------------------------------- */

function zan_upd_notify_email()
{
    $m = trim((string) zan_cfg('update_notify_email', ''));
    return ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)) ? $m : '';
}

/** 计划任务入口令牌，没有就生成一个 */
function zan_upd_cron_token()
{
    $t = (string) zan_cfg('update_cron_token', '');
    if (!preg_match('#^[a-f0-9]{32}$#', $t)) {
        try {
            $t = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $t = md5(uniqid('zan', true));
        }
        zan_config_save(array('update_cron_token' => $t));
    }
    return $t;
}

function zan_upd_cron_url()
{
    return zan_abs_url('admin/cron.php') . '?token=' . zan_upd_cron_token();
}

/**
 * 发现新版本时通知一次
 *
 * 同一个提交只发一封：状态里记 notified_sha；发信失败不记，下次到点会再试。
 */
function zan_upd_notify($state, &$err = '')
{
    $err = '';
    $to  = zan_upd_notify_email();
    $sha = isset($state['remote']['sha']) ? (string) $state['remote']['sha'] : '';
    if ($to === '' || $sha === '') {
        return false;
    }
    if (!empty($state['notified_sha']) && (string) $state['notified_sha'] === $sha) {
        return true;
    }

    $ver = isset($state['remote']['version']) ? (string) $state['remote']['version'] : '';
    $msg = isset($state['remote']['msg']) ? (string) $state['remote']['msg'] : '';
    $body = "自动检查发现新版本。\n\n"
          . "站点：     " . zan_abs_url('/') . "\n"
          . "当前版本： " . ZAN_VERSION . "\n"
          . "最新版本： " . ($ver !== '' ? $ver : '未读到版本号') . "\n"
          . "最新提交： " . substr($sha, 0, 7) . ($msg !== '' ? '  ' . $msg : '') . "\n"
          . "发现时间： " . date('Y-m-d H:i') . "\n\n"
          . "去后台处理： " . zan_abs_url('admin/update.php') . "\n\n"
          . "--\n这封邮件由 " . ZAN_BRAND . " 自动发出，同一版本只发一次。\n";

    $ok = zan_mail_send($to, '[' . zan_mail_host() . '] 有新版本 ' . ($ver !== '' ? $ver : substr($sha, 0, 7)), $body, $merr);

    if ($ok) {
        $state['notified_sha'] = $sha;
        $state['notified_at']  = time();
        $state['notified_to']  = $to;
        $state['notify_error'] = '';
    } else {
        $state['notify_error']    = $merr;
        $state['notify_error_at'] = time();
        $err = $merr;
    }
    zan_upd_state_write($state);
    return $ok;
}

/** 自动更新完成后回一封 */
function zan_upd_notify_applied($version, $files, &$err = '')
{
    $err = '';
    $to  = zan_upd_notify_email();
    if ($to === '') {
        return false;
    }
    $body = "站点已完成自动更新。\n\n"
          . "站点：     " . zan_abs_url('/') . "\n"
          . "更新到：   " . $version . "\n"
          . "覆盖文件： " . (int) $files . " 个\n"
          . "完成时间： " . date('Y-m-d H:i') . "\n\n"
          . "如果页面有异常，可在「系统更新」页回滚到更新前的备份。\n";
    return zan_mail_send($to, '[' . zan_mail_host() . '] 已自动更新到 ' . $version, $body, $err);
}

/**
 * 自动检查/自动更新的公共入口：后台静默请求、计划任务都走这里
 *
 * @return array|false false = 这次没跑（开关关着 / 没到点 / 别的进程正在跑）
 */
function zan_upd_auto_run($timeout = 20, &$err = '')
{
    $err = '';
    if (!zan_upd_auto_check() || zan_upd_repo() === '' || !zan_upd_auto_due()) {
        return false;
    }

    $lock = zan_upd_lock();
    if ($lock === false) {
        return false;
    }
    /* 拿到锁后再确认一次：另一个进程可能刚检查完 */
    if (!zan_upd_auto_due()) {
        zan_upd_unlock($lock);
        return false;
    }

    $r   = zan_upd_check(false, $cerr, $timeout);
    $err = $cerr;
    $out = array(
        'ok'         => !empty($r['ok']),
        'has_update' => !empty($r['has_update']),
        'applied'    => false,
        'notified'   => false,
        'version'    => '',
        'files'      => 0,
    );
    if (!empty($r['state']['remote']['version'])) {
        $out['version'] = (string) $r['state']['remote']['version'];
    }

    if ($out['ok'] && $out['has_update']) {
        if (zan_upd_auto_apply()) {
            $a = zan_upd_apply_locked($aerr);
            if ($a === false) {
                $err = $aerr;
            } else {
                $out['applied']  = true;
                $out['version']  = $a['version'];
                $out['files']    = (int) $a['files'];
                $out['notified'] = zan_upd_notify_applied($a['version'], $a['files'], $nerr);
                if ($nerr !== '') {
                    $err = trim($err . ' ' . $nerr);
                }
            }
        } else {
            $out['notified'] = zan_upd_notify($r['state'], $nerr);
            if ($nerr !== '') {
                $err = trim($err . ' ' . $nerr);
            }
        }
    }

    zan_upd_unlock($lock);
    return $out;
}

/* ---------------------------------------------------------------------------
 * HTTP
 * ------------------------------------------------------------------------- */

/** 人类可读的体积 */
function zan_upd_bytes($n)
{
    $n = (int) $n;
    if ($n >= 1048576) {
        return round($n / 1048576, 1) . ' MB';
    }
    if ($n >= 1024) {
        return round($n / 1024) . ' KB';
    }
    return $n . ' B';
}

/**
 * 取一个地址
 *
 * @param string|null $dest 给了就写文件，否则返回字符串
 * @return string|bool
 */
function zan_upd_http($url, $dest, $maxBytes, &$err, $timeout = 90)
{
    $err    = '';
    $body   = '';
    $fp     = null;
    $got    = 0;
    $tooBig = false;

    if ($dest !== null) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            $err = '无法写入临时文件';
            return false;
        }
    }

    $write = function ($ch, $chunk) use (&$body, &$fp, &$got, $maxBytes, &$tooBig) {
        $got += strlen($chunk);
        if ($got > $maxBytes) {
            $tooBig = true;
            return 0; // 中断传输
        }
        if ($fp) {
            fwrite($fp, $chunk);
        } else {
            $body .= $chunk;
        }
        return strlen($chunk);
    };

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(10, (int) $timeout));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Zan-Updater/' . ZAN_VERSION);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/vnd.github+json'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $write);

        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($fp) {
            fclose($fp);
            $fp = null;
        }

        if ($tooBig) {
            $err = '响应内容超过 ' . zan_upd_bytes($maxBytes);
            return false;
        }
        if ($ok === false) {
            $err = $cerr !== '' ? '连接失败：' . $cerr : '连接失败';
            if (stripos($cerr, 'certificate') !== false || stripos($cerr, 'SSL') !== false) {
                $err .= '。服务器缺少 CA 证书：Windows 在 php.ini 设 curl.cainfo 指向 cacert.pem，'
                      . 'Linux 装 ca-certificates 即可；证书问题不解决，更新无法安全进行。';
            }
            return false;
        }
        if ($code === 404) {
            $err = '仓库或分支不存在（HTTP 404），请检查仓库地址与分支名';
            return false;
        }
        if ($code === 403) {
            $err = 'GitHub 拒绝访问（HTTP 403），可能是接口调用过于频繁，请稍后再试';
            return false;
        }
        if ($code < 200 || $code >= 300) {
            $err = '请求失败（HTTP ' . $code . '）';
            return false;
        }
        return $dest !== null ? true : $body;
    }

    /* 没有 curl 的主机：流式读取兜底 */
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout'         => max(10, (int) $timeout),
            'user_agent'      => 'Zan-Updater/' . ZAN_VERSION,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'header'          => "Accept: application/vnd.github+json\r\n",
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) {
        $err = '无法打开地址（可能是网络不通或证书问题）';
        return false;
    }
    while (!feof($src)) {
        $chunk = fread($src, 8192);
        if ($chunk === false) {
            break;
        }
        if ($write(null, $chunk) === 0) {
            break;
        }
    }
    fclose($src);
    if ($fp) {
        fclose($fp);
    }
    if ($tooBig) {
        $err = '响应内容超过 ' . zan_upd_bytes($maxBytes);
        return false;
    }
    if ($dest !== null) {
        return is_file($dest) && filesize($dest) > 0 ? true : false;
    }
    if ($body === '') {
        $err = '返回内容为空';
        return false;
    }
    return $body;
}

/* ---------------------------------------------------------------------------
 * 查询远程
 * ------------------------------------------------------------------------- */

/** 从 defs.php 内容里取版本号 */
function zan_upd_version_in($src)
{
    if (preg_match("/define\\(\\s*'ZAN_VERSION'\\s*,\\s*'([0-9][0-9A-Za-z._-]*)'\\s*\\)/", (string) $src, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * 远程最新提交与版本号
 *
 * @return array|false array('sha','version','msg','date','branch')
 */
function zan_upd_remote_info(&$err, $timeout = 90)
{
    $err  = '';
    $repo = zan_upd_repo();
    if ($repo === '') {
        $err = '仓库地址未设置或格式不正确，应为 owner/repo';
        return false;
    }
    $branch = zan_upd_branch();

    $api  = 'https://api.github.com/repos/' . $repo;
    $json = zan_upd_http($api . '/commits/' . rawurlencode($branch), null, 1048576, $err, $timeout);
    if ($json === false) {
        return false;
    }
    $j = json_decode($json, true);
    if (!is_array($j) || empty($j['sha'])) {
        $hint = (is_array($j) && !empty($j['message'])) ? (string) $j['message'] : '返回内容不是 JSON';
        $err  = '查询提交失败：' . $hint;
        return false;
    }

    $sha = (string) $j['sha'];
    $msg = '';
    if (!empty($j['commit']['message'])) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $j['commit']['message']);
        $msg   = mb_substr(trim($lines[0]), 0, 100, 'UTF-8');
    }
    $date = !empty($j['commit']['committer']['date']) ? (string) $j['commit']['committer']['date'] : '';

    /* 版本号从 include/defs.php 里取：仓库还没打 tag 时也能判断新旧 */
    $version = '';
    $raw = zan_upd_http($api . '/contents/include/defs.php?ref=' . rawurlencode($sha), null, 2097152, $e2, $timeout);
    if ($raw !== false) {
        $f = json_decode($raw, true);
        if (!empty($f['content'])) {
            $src     = base64_decode(str_replace(array("\n", "\r"), '', (string) $f['content']));
            $version = zan_upd_version_in($src);
        }
    }

    return array(
        'sha'     => $sha,
        'version' => $version,
        'msg'     => $msg,
        'date'    => $date,
        'branch'  => $branch,
    );
}

/** 状态里是否存在「有新版」 */
function zan_upd_has_new($state)
{
    if (empty($state['remote']['sha'])) {
        return false;
    }
    $remote  = (string) $state['remote']['sha'];
    $applied = isset($state['applied_sha']) ? (string) $state['applied_sha'] : '';
    if ($remote === $applied) {
        return false;
    }
    if (!empty($state['remote']['version']) && version_compare((string) $state['remote']['version'], ZAN_VERSION, '>')) {
        return true;
    }
    /* 版本号没改但确实推了新代码：只有记录过已应用 commit 时才提示，
       否则本地开发中的版本永远显示「有更新」 */
    if ($applied !== '' && $remote !== $applied) {
        return true;
    }
    return false;
}

/** 后台菜单红点用：只读本地状态，不联网 */
function zan_upd_available_cached()
{
    return zan_upd_has_new(zan_upd_state_read());
}

/**
 * 检查更新
 *
 * @param bool $force 忽略间隔，强制联网
 * @return array ok / cached / has_update / state / err
 */
function zan_upd_check($force = false, &$err = '', $timeout = 90)
{
    $err   = '';
    $state = zan_upd_state_read();

    if (!$force && !empty($state['checked_at']) && (time() - (int) $state['checked_at']) < zan_upd_interval()) {
        return array(
            'ok'         => true,
            'cached'     => true,
            'has_update' => zan_upd_has_new($state),
            'state'      => $state,
            'err'        => '',
        );
    }

    $info = zan_upd_remote_info($err, $timeout);
    if ($info === false) {
        $state['checked_at'] = time();
        $state['error']      = $err;
        zan_upd_state_write($state);
        return array('ok' => false, 'cached' => false, 'has_update' => false, 'state' => $state, 'err' => $err);
    }

    $state['checked_at'] = time();
    $state['error']      = '';
    $state['remote']     = $info;
    zan_upd_state_write($state);

    return array(
        'ok'         => true,
        'cached'     => false,
        'has_update' => zan_upd_has_new($state),
        'state'      => $state,
        'err'        => '',
    );
}

/* ---------------------------------------------------------------------------
 * 备份与回滚
 * ------------------------------------------------------------------------- */

/** 程序区里参与更新的目录与根文件（config.php、data/、install/ 永远不在里面） */
function zan_upd_system_items()
{
    return array(
        'dirs'  => array('api', 'admin', 'include', 'assets'),
        'files' => array('index.php', 'README.md'),
    );
}

function zan_upd_backup_root()
{
    return zan_upd_data('backup/update');
}

/**
 * 备份当前程序区
 *
 * @return string|false 备份目录
 */
function zan_upd_backup_system(&$err)
{
    $err  = '';
    $dir  = zan_upd_backup_root() . '/' . date('Ymd-His') . '-' . str_replace('.', '_', ZAN_VERSION);

    if (!zan_upd_mkdir($dir)) {
        $err = '创建备份目录失败，请检查 data/ 目录权限';
        return false;
    }

    $items = zan_upd_system_items();
    foreach ($items['dirs'] as $d) {
        $src = ZAN_ROOT . $d;
        if (is_dir($src) && !zan_upd_copy_tree($src, $dir . '/' . $d, $e)) {
            $err = '备份 ' . $d . ' 失败：' . $e;
            return false;
        }
    }
    foreach ($items['files'] as $f) {
        if (is_file(ZAN_ROOT . $f) && !@copy(ZAN_ROOT . $f, $dir . '/' . $f)) {
            $err = '备份 ' . $f . ' 失败';
            return false;
        }
    }

    zan_upd_prune_backups(3);
    return $dir;
}

/** 递归复制目录 */
function zan_upd_copy_tree($src, $dst, &$err)
{
    $err = '';
    if (!is_dir($src)) {
        return true;
    }
    if (!zan_upd_mkdir($dst)) {
        $err = '创建目录失败：' . $dst;
        return false;
    }
    $dh = @opendir($src);
    if (!$dh) {
        $err = '读取目录失败：' . $src;
        return false;
    }
    while (($item = readdir($dh)) !== false) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!zan_upd_copy_tree($s, $d, $err)) {
                closedir($dh);
                return false;
            }
        } elseif (!@copy($s, $d)) {
            $err = '复制失败：' . $item;
            closedir($dh);
            return false;
        }
    }
    closedir($dh);
    return true;
}

/** 用备份覆盖回程序区（回滚） */
function zan_upd_restore($dir, &$err)
{
    $err  = '';
    $root = realpath(zan_upd_backup_root());
    $real = realpath((string) $dir);
    if ($root === false || $real === false || strpos($real, $root) !== 0 || !is_dir($real)) {
        $err = '备份目录不合法';
        return false;
    }

    $items = zan_upd_system_items();
    foreach ($items['dirs'] as $d) {
        if (!is_dir($real . '/' . $d)) {
            continue;
        }
        if (!zan_upd_copy_tree($real . '/' . $d, ZAN_ROOT . $d, $e)) {
            $err = '恢复 ' . $d . ' 失败：' . $e;
            return false;
        }
        /* 把「更新时新增、备份里没有」的文件清掉，否则回滚回来还留着新版的文件 */
        zan_upd_prune_extra($real . '/' . $d, ZAN_ROOT . $d);
    }
    foreach ($items['files'] as $f) {
        if (is_file($real . '/' . $f) && !@copy($real . '/' . $f, ZAN_ROOT . $f)) {
            $err = '恢复 ' . $f . ' 失败';
            return false;
        }
    }
    return true;
}

/** 删掉目标目录里备份中没有的文件（只用于程序区回滚） */
function zan_upd_prune_extra($backupDir, $targetDir)
{
    if (!is_dir($targetDir) || !is_dir($backupDir)) {
        return;
    }
    foreach ((array) @scandir($targetDir) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $t = $targetDir . '/' . $n;
        $b = $backupDir . '/' . $n;
        if (is_dir($t)) {
            if (is_dir($b)) {
                zan_upd_prune_extra($b, $t);
            } else {
                zan_upd_rmdir($t);
            }
        } elseif (!is_file($b)) {
            @unlink($t);
        }
    }
}

/** 只保留最近 N 份备份 */
function zan_upd_prune_backups($keep = 3)
{
    $root = zan_upd_backup_root();
    if (!is_dir($root)) {
        return;
    }
    $list = array();
    foreach ((array) @scandir($root) as $n) {
        if ($n === '.' || $n === '..' || !is_dir($root . '/' . $n)) {
            continue;
        }
        $list[] = $n;
    }
    sort($list);
    $drop = array_slice($list, 0, max(0, count($list) - $keep));
    foreach ($drop as $n) {
        zan_upd_rmdir($root . '/' . $n);
    }
}

/** 递归删目录（只用于自己建的备份/临时目录） */
function zan_upd_rmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) @scandir($dir) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $dir . '/' . $n;
        if (is_dir($p)) {
            zan_upd_rmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/* ---------------------------------------------------------------------------
 * 覆盖写入
 * ------------------------------------------------------------------------- */

/**
 * 允许被更新覆盖的相对路径
 *
 * config.php / data/ / install/ / reset-password.php 永远不在里面：
 * 一个是站点密钥，一个是数据，一个是装完就该删的安装器，一个是站长自己填了口令的救急工具。
 */
function zan_upd_path_allowed($rel)
{
    $rel = str_replace('\\', '/', ltrim((string) $rel, '/'));
    if ($rel === '' || strpos($rel, '..') !== false) {
        return false;
    }
    if (in_array($rel, array('index.php', 'README.md'), true)) {
        return true;
    }
    foreach (array('api/', 'admin/', 'include/', 'assets/') as $p) {
        if (strpos($rel, $p) === 0 && substr($rel, -1) !== '/') {
            return true;
        }
    }
    return false;
}

/** 写一个文件：先写临时名再 rename，单文件原子替换 */
function zan_upd_write_file($rel, $data, &$err)
{
    $err = '';
    $rel = str_replace('\\', '/', $rel);
    if (!zan_upd_path_allowed($rel)) {
        $err = '路径不在允许范围内';
        return false;
    }

    $dst = ZAN_ROOT . $rel;
    if (!zan_upd_mkdir(dirname($dst))) {
        $err = '创建目录失败';
        return false;
    }

    $tmp = $dst . '.zannew';
    if (@file_put_contents($tmp, $data) === false) {
        $err = '写入失败（目录不可写？）';
        return false;
    }
    if (!@rename($tmp, $dst)) {
        /* Windows 下目标文件可能被占用，删掉再试一次 */
        @unlink($dst);
        if (!@rename($tmp, $dst)) {
            @unlink($tmp);
            $err = '替换文件失败';
            return false;
        }
    }
    @chmod($dst, 0644);
    /* opcache 按 mtime 判断，同一个时间戳内替换会继续跑旧代码 */
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($dst, true);
    }
    return true;
}

/**
 * 写权限预检：真写一个探针文件，比 is_writable 可靠
 */
function zan_upd_writable_preflight(&$err)
{
    $err = '';
    foreach (array(ZAN_ROOT, ZAN_ROOT . 'include', ZAN_ROOT . 'admin', ZAN_ROOT . 'api', ZAN_ROOT . 'assets') as $d) {
        if (!is_dir($d)) {
            $err = str_replace(ZAN_ROOT, '站点根/', $d) . ' 不存在';
            return false;
        }
        $probe = $d . '/.zan-write-test';
        if (@file_put_contents($probe, 'x') === false) {
            $err = str_replace(ZAN_ROOT, '站点根/', $d) . ' 目录不可写，请临时设为 755（属主为 PHP 运行用户）后重试';
            return false;
        }
        @unlink($probe);
    }
    return true;
}

/** 临时放开程序区目录的写权限（部署时设了 555 也能更新） */
function zan_upd_open_dirs()
{
    $saved = array();
    $dirs  = array(ZAN_ROOT . 'api', ZAN_ROOT . 'admin', ZAN_ROOT . 'include', ZAN_ROOT . 'assets');
    foreach ($dirs as $top) {
        if (!is_dir($top)) {
            continue;
        }
        $stack = array($top);
        while ($stack) {
            $cur = array_pop($stack);
            if (!is_writable($cur)) {
                $old = @fileperms($cur);
                if ($old !== false && @chmod($cur, ($old & 0777) | 0755)) {
                    $saved[$cur] = $old & 0777;
                }
            }
            foreach ((array) @scandir($cur) as $n) {
                if ($n === '.' || $n === '..') {
                    continue;
                }
                $p = $cur . '/' . $n;
                if (is_dir($p)) {
                    $stack[] = $p;
                }
            }
        }
    }
    return $saved;
}

function zan_upd_close_dirs($saved)
{
    foreach ((array) $saved as $dir => $perm) {
        @chmod($dir, $perm);
    }
}

/* ---------------------------------------------------------------------------
 * 下载与安装
 * ------------------------------------------------------------------------- */

/** 按 commit 下载源码包 */
function zan_upd_download($sha, &$err)
{
    $err  = '';
    $repo = zan_upd_repo();
    if ($repo === '' || !preg_match('#^[0-9a-f]{7,40}$#i', (string) $sha)) {
        $err = '提交号不合法';
        return false;
    }

    $dir = zan_upd_data('tmp');
    if (!zan_upd_mkdir($dir)) {
        $err = '创建临时目录失败';
        return false;
    }
    $zip = $dir . '/update-' . substr($sha, 0, 12) . '.zip';
    @unlink($zip);

    $url = 'https://codeload.github.com/' . $repo . '/zip/' . rawurlencode($sha);
    if (!zan_upd_http($url, $zip, 20 * 1024 * 1024, $err)) {
        @unlink($zip);
        return false;
    }
    if (!is_file($zip) || filesize($zip) < 100) {
        @unlink($zip);
        $err = '下载的压缩包异常（内容太小）';
        return false;
    }
    /* 有的仓库包很小（只有几个文件），不能拿体积判断，认 zip 魔数 */
    if ((string) @file_get_contents($zip, false, null, 0, 4) !== "PK\x03\x04") {
        @unlink($zip);
        $err = '下载的内容不是 zip（可能被 CDN 或拦截页替换）';
        return false;
    }
    return $zip;
}

/** zip 内部的统一前缀（GitHub 打包会套一层 repo-sha/） */
function zan_upd_zip_prefix($names)
{
    $first = null;
    foreach ((array) $names as $n) {
        $pos = strpos($n, '/');
        if ($pos === false) {
            return '';
        }
        $seg = substr($n, 0, $pos + 1);
        if ($first === null) {
            $first = $seg;
        } elseif ($first !== $seg) {
            return false;
        }
    }
    return $first === null ? false : $first;
}

/**
 * 从 zip 安装更新
 *
 * @param string $zipPath
 * @param array  $info zan_upd_remote_info() 的返回
 * @return array|false array('version','files','backup')
 */
function zan_upd_install_zip($zipPath, $info, &$err)
{
    $err = '';

    $zip = new Zan_Zip_Reader($zipPath);
    if ($zip->error() !== '') {
        $err = '压缩包无法读取：' . $zip->error();
        return false;
    }
    $entries = $zip->entries();
    $prefix  = zan_upd_zip_prefix(array_keys($entries));
    if ($prefix === false) {
        $err = '压缩包结构不符合预期（找不到统一的根目录）';
        return false;
    }

    /* 关键文件必须在，否则不是这个程序的包 */
    foreach (array('include/defs.php', 'admin/index.php', 'index.php') as $k) {
        if (!isset($entries[$prefix . $k])) {
            $err = '压缩包缺少关键文件：' . $k;
            return false;
        }
    }

    $defs = $zip->read($prefix . 'include/defs.php');
    if ($defs === false) {
        $err = '读取版本文件失败：' . $zip->error();
        return false;
    }
    $remoteVer = zan_upd_version_in($defs);
    if ($remoteVer === '') {
        $err = '压缩包里读不到版本号，已拒绝更新';
        return false;
    }
    if (version_compare($remoteVer, ZAN_VERSION, '<')) {
        $err = '远程版本 ' . $remoteVer . ' 低于当前 ' . ZAN_VERSION . '，已取消';
        return false;
    }

    /* 先备份，后动手 */
    $backup = zan_upd_backup_system($err);
    if ($backup === false) {
        return false;
    }

    $perms = zan_upd_open_dirs();
    if (!zan_upd_writable_preflight($perr)) {
        zan_upd_close_dirs($perms);
        $err = '系统目录不可写，已取消更新：' . $perr;
        return false;
    }

    $written = 0;
    $failed  = array();

    foreach ($entries as $name => $e) {
        if ($prefix !== '' && strpos($name, $prefix) !== 0) {
            continue;
        }
        $rel = $prefix === '' ? $name : substr($name, strlen($prefix));
        if (!zan_upd_path_allowed($rel)) {
            continue;
        }
        $data = $zip->read($name);
        if ($data === false) {
            $failed[] = $rel . '（' . $zip->error() . '）';
            continue;
        }
        if (!zan_upd_write_file($rel, $data, $werr)) {
            $failed[] = $rel . '（' . $werr . '）';
        } else {
            $written++;
        }
    }

    /* 写完复核一遍关键文件，避免解压中途出问题还当成成功 */
    if (!$failed && zan_upd_version_in((string) @file_get_contents(ZAN_ROOT . 'include/defs.php')) === '') {
        $failed[] = 'include/defs.php 写入后不可读';
    }

    if ($failed) {
        $restoreErr = '';
        zan_upd_restore($backup, $restoreErr);
        zan_upd_close_dirs($perms);
        $err = '更新中断，' . ($restoreErr === '' ? '已回滚到更新前的版本' : '回滚也失败了，请手工从备份恢复：' . $backup)
             . '。原因：' . implode('；', array_slice($failed, 0, 3));
        return false;
    }

    zan_upd_close_dirs($perms);
    zan_upd_prune_backups(3);

    $state = zan_upd_state_read();
    $state['applied_sha']     = (string) $info['sha'];
    $state['applied_version'] = $remoteVer;
    $state['last_update_at']  = time();
    $state['last_backup']     = $backup;
    $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : array();
    array_unshift($history, array(
        'at'      => time(),
        'version' => $remoteVer,
        'sha'     => substr((string) $info['sha'], 0, 12),
        'files'   => $written,
    ));
    $state['history'] = array_slice($history, 0, 10);
    zan_upd_state_write($state);

    return array('version' => $remoteVer, 'files' => $written, 'backup' => $backup);
}

/**
 * 更新锁：同一时间只允许一个更新在跑
 *
 * @return resource|false
 */
function zan_upd_lock()
{
    if (!zan_upd_mkdir(zan_upd_data())) {
        return false;
    }
    $fp = @fopen(zan_upd_data('update.lock'), 'c');
    if (!$fp) {
        return false;
    }
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function zan_upd_unlock($fp)
{
    if ($fp) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/** 一键更新：查 → 下载 → 安装 */
function zan_upd_apply(&$err)
{
    $err  = '';
    $lock = zan_upd_lock();
    if ($lock === false) {
        $err = '另一个更新正在进行，请稍后刷新再试';
        return false;
    }
    $r = zan_upd_apply_locked($err);
    zan_upd_unlock($lock);
    return $r;
}

/** 真正干活的那个，由 zan_upd_apply 持锁调用 */
function zan_upd_apply_locked(&$err)
{
    $err = '';
    @set_time_limit(180);

    $info = zan_upd_remote_info($err);
    if ($info === false) {
        return false;
    }
    if ($info['version'] !== '' && version_compare($info['version'], ZAN_VERSION, '<')) {
        $err = '远程版本 ' . $info['version'] . ' 低于当前 ' . ZAN_VERSION . '，已取消';
        return false;
    }

    $zip = zan_upd_download($info['sha'], $err);
    if ($zip === false) {
        return false;
    }

    $r = zan_upd_install_zip($zip, $info, $err);
    @unlink($zip);

    if ($r === false) {
        return false;
    }
    return $r;
}

/** 回滚到指定的更新备份 */
function zan_upd_rollback($dir, &$err)
{
    $err = '';

    $perms = zan_upd_open_dirs();
    $ok    = zan_upd_restore($dir, $err);
    zan_upd_close_dirs($perms);

    if (!$ok) {
        return false;
    }

    $state = zan_upd_state_read();
    unset($state['applied_sha'], $state['applied_version']);
    $state['last_rollback_at'] = time();
    zan_upd_state_write($state);
    return true;
}

/** 可用备份列表（新 → 旧） */
function zan_upd_backups()
{
    $root = zan_upd_backup_root();
    $out  = array();
    if (!is_dir($root)) {
        return $out;
    }
    foreach ((array) @scandir($root) as $n) {
        if ($n === '.' || $n === '..' || !is_dir($root . '/' . $n)) {
            continue;
        }
        $out[] = array(
            'name' => $n,
            'path' => $root . '/' . $n,
            'time' => (int) @filemtime($root . '/' . $n),
        );
    }
    /* 目录名是 Ymd-His-版本，字典序就是时间序，比 filemtime 稳 */
    usort($out, function ($a, $b) {
        return strcmp($b['name'], $a['name']);
    });
    return $out;
}
