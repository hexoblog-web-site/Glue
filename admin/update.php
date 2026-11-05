<?php
/**
 * 系统更新：以 GitHub 仓库为分发源
 *
 * 只更新程序区（api、admin、include、assets、index.php、README.md），
 * 不动 config.php、data/、install/、reset-password.php。
 */
define('ZAN_APP', true);
require dirname(__DIR__) . '/include/common.php';
require dirname(__DIR__) . '/include/mailer.php';
require dirname(__DIR__) . '/include/zipreader.php';
require dirname(__DIR__) . '/include/updater.php';
zan_require_admin();

$notice = '';

/* -------------------------------------------------------------------------
 * 后台静默检查（浏览器在页面加载后异步打一发，不阻塞页面）
 * ---------------------------------------------------------------------- */
if (isset($_POST['action']) && $_POST['action'] === 'auto') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!zan_csrf_ok(isset($_POST['nonce']) ? $_POST['nonce'] : '')) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'msg' => '校验失败'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!zan_upd_auto_check()) {
        echo json_encode(array('ok' => true, 'has_update' => false, 'applied' => false), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r   = zan_upd_auto_run(20, $cerr);
    $out = array(
        'ok'         => true,
        'has_update' => zan_upd_available_cached(),
        'applied'    => false,
        'version'    => '',
        'files'      => 0,
        'msg'        => (string) $cerr,
    );
    if ($r !== false) {
        $out['ok']         = !empty($r['ok']);
        $out['has_update'] = !empty($r['has_update']);
        $out['applied']    = !empty($r['applied']);
        $out['version']    = (string) $r['version'];
        $out['files']      = (int) $r['files'];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/* -------------------------------------------------------------------------
 * 普通表单动作
 * ---------------------------------------------------------------------- */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if (!zan_csrf_ok(isset($_POST['nonce']) ? $_POST['nonce'] : '')) {
        $notice = array('页面已过期，请刷新后重试。', 'error');
    } elseif ($do === 'save') {
        $repo = trim((string) (isset($_POST['update_repo']) ? $_POST['update_repo'] : ''));
        if ($repo !== '' && !zan_upd_repo_valid($repo)) {
            $notice = array('仓库地址格式不对，应形如 owner/repo（不要带 https:// 或 .git）。', 'error');
        } else {
            $branch = trim((string) (isset($_POST['update_branch']) ? $_POST['update_branch'] : 'main'));
            if ($branch === '' || strpos($branch, '..') !== false || !preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
                $notice = array('分支名不合法。', 'error');
            } else {
                $email = trim((string) (isset($_POST['update_notify_email']) ? $_POST['update_notify_email'] : ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $notice = array('通知邮箱格式不对。', 'error');
                } else {
                    $interval = (int) (isset($_POST['update_interval']) ? $_POST['update_interval'] : 6);
                    $ok = zan_config_save(array(
                        'update_repo'         => $repo,
                        'update_branch'       => $branch,
                        'update_interval'     => max(1, min(168, $interval)),
                        'update_auto_check'   => isset($_POST['update_auto_check']) ? '1' : '0',
                        'update_auto_apply'   => isset($_POST['update_auto_apply']) ? '1' : '0',
                        'update_notify_email' => $email,
                    ));
                    if ($ok) {
                        header('Location: ' . zan_url('admin/update.php?done=save'));
                        exit;
                    }
                    $notice = array('写入 config.php 失败，请检查程序目录是否可写。', 'error');
                }
            }
        }
    } elseif ($do === 'save_mail') {
        $driver = (isset($_POST['mail_driver']) && $_POST['mail_driver'] === 'smtp') ? 'smtp' : 'mail';
        $host   = trim((string) (isset($_POST['mail_host']) ? $_POST['mail_host'] : ''));
        $port   = (int) (isset($_POST['mail_port']) ? $_POST['mail_port'] : 0);
        $secure = isset($_POST['mail_secure']) ? (string) $_POST['mail_secure'] : 'ssl';
        $user   = trim((string) (isset($_POST['mail_user']) ? $_POST['mail_user'] : ''));
        $from   = trim((string) (isset($_POST['mail_from']) ? $_POST['mail_from'] : ''));
        $name   = trim((string) (isset($_POST['mail_from_name']) ? $_POST['mail_from_name'] : ''));
        $pass   = (string) (isset($_POST['mail_pass']) ? $_POST['mail_pass'] : '');

        if (!in_array($secure, array('ssl', 'tls', 'none'), true)) {
            $secure = 'ssl';
        }
        if ($port !== 0 && ($port < 1 || $port > 65535)) {
            $notice = array('SMTP 端口应在 1–65535 之间。', 'error');
        } elseif ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $notice = array('发件邮箱格式不对。', 'error');
        } elseif ($driver === 'smtp' && ($host === '' || $user === '')) {
            $notice = array('选了 SMTP 发信，服务器地址和账号都要填。', 'error');
        } elseif ($driver === 'smtp' && $pass === '' && (string) zan_cfg('mail_pass', '') === '') {
            $notice = array('选了 SMTP 发信，授权码（或密码）不能为空。', 'error');
        } else {
            $changes = array(
                'mail_driver'    => $driver,
                'mail_host'      => $host,
                'mail_port'      => max(0, $port),
                'mail_secure'    => $secure,
                'mail_user'      => $user,
                'mail_from'      => $from,
                'mail_from_name' => mb_substr($name, 0, 60, 'UTF-8'),
                'mail_verify'    => isset($_POST['mail_verify']) ? '1' : '0',
            );
            /* 授权码留空 = 不改：避免每次保存都重填，也避免明文回显在页面源码里 */
            if ($pass !== '') {
                $changes['mail_pass'] = $pass;
            }
            if (zan_config_save($changes)) {
                header('Location: ' . zan_url('admin/update.php?done=mail'));
                exit;
            }
            $notice = array('写入 config.php 失败，请检查程序目录是否可写。', 'error');
        }
    } elseif ($do === 'testmail') {
        $to = zan_upd_notify_email();
        if ($to === '') {
            $notice = array('先填写通知邮箱并保存，再发测试邮件。', 'error');
        } else {
            $ok = zan_mail_send(
                $to,
                '[' . zan_mail_host() . '] 测试邮件',
                "这是一封来自 " . ZAN_BRAND . " 的测试邮件。\n\n"
                . "站点：" . zan_abs_url('/') . "\n"
                . "时间：" . date('Y-m-d H:i') . "\n\n"
                . "收到这封邮件，说明「发现新版本自动通知」可以正常发出。\n",
                $merr
            );
            if ($ok) {
                $cfg = zan_mail_config();
                $notice = array('测试邮件已发送到 ' . $to . '（发信方式：'
                    . ($cfg['driver'] === 'smtp' ? 'SMTP' : 'PHP mail()') . '），去收件箱看一下，也可能在垃圾箱。', 'ok');
            } else {
                $notice = array('发送失败：' . $merr, 'error');
            }
        }
    } elseif ($do === 'rotate') {
        zan_config_save(array('update_cron_token' => ''));
        zan_upd_cron_token();
        $notice = array('定时检查地址已重置，旧地址立即失效，记得去服务器改计划任务。', 'ok');
    } elseif ($do === 'check') {
        $r = zan_upd_check(true, $err);
        if (!$r['ok']) {
            $notice = array('检查失败：' . $err, 'error');
        } elseif (!empty($r['has_update'])) {
            $v = !empty($r['state']['remote']['version']) ? $r['state']['remote']['version'] : '新提交';
            $notice = array('发现新版本：' . $v . '。', 'ok');
        } else {
            $notice = array('已是最新版本（当前 ' . ZAN_VERSION . '）。', 'ok');
        }
    } elseif ($do === 'apply') {
        $r = zan_upd_apply($err);
        if ($r === false) {
            $notice = array('更新失败：' . $err, 'error');
        } else {
            $notice = array('已更新到 ' . $r['version'] . '（覆盖 ' . (int) $r['files'] . ' 个文件）。刷新页面后生效。', 'ok');
        }
    } elseif ($do === 'skip') {
        $state = zan_upd_state_read();
        if (empty($state['remote']['sha'])) {
            $notice = array('还没有检查结果，先点「立即检查」。', 'error');
        } else {
            $state['applied_sha']     = (string) $state['remote']['sha'];
            $state['applied_version'] = isset($state['remote']['version']) ? (string) $state['remote']['version'] : '';
            $state['skipped_at']      = time();
            zan_upd_state_write($state);
            $notice = array('已忽略这次更新，仓库有新提交时会再提示。', 'ok');
        }
    } elseif ($do === 'rollback') {
        $name = (string) (isset($_POST['name']) ? $_POST['name'] : '');
        $hit  = null;
        foreach (zan_upd_backups() as $b) {
            if ($b['name'] === $name) {
                $hit = $b;
                break;
            }
        }
        if ($hit === null) {
            $notice = array('备份不存在。', 'error');
        } elseif (zan_upd_rollback($hit['path'], $err)) {
            $notice = array('已回滚到 ' . $name . ' 的版本。刷新页面后生效。', 'ok');
        } else {
            $notice = array('回滚失败：' . $err, 'error');
        }
    }
}

if (isset($_GET['done'])) {
    if ($_GET['done'] === 'save') {
        $notice = array('更新设置已保存。', 'ok');
    } elseif ($_GET['done'] === 'mail') {
        $notice = array('发信设置已保存。', 'ok');
    }
}

$state   = zan_upd_state_read();
$remote  = isset($state['remote']) && is_array($state['remote']) ? $state['remote'] : array();
$hasNew  = zan_upd_has_new($state);
$backups = zan_upd_backups();
$history = isset($state['history']) && is_array($state['history']) ? $state['history'] : array();
$repo    = (string) zan_cfg('update_repo', '');
$branch  = zan_upd_branch();
$lastErr = (string) (isset($state['error']) ? $state['error'] : '');

$remoteVer = isset($remote['version']) ? (string) $remote['version'] : '';
$remoteSha = isset($remote['sha']) ? (string) $remote['sha'] : '';
$remoteMsg = isset($remote['msg']) ? (string) $remote['msg'] : '';
$remoteAt  = !empty($remote['date']) ? strtotime((string) $remote['date']) : 0;

$notifyTo   = zan_upd_notify_email();
$nextCheck  = zan_upd_next_check_at();
$notifiedAt = isset($state['notified_at']) ? (int) $state['notified_at'] : 0;
$notifiedTo = isset($state['notified_to']) ? (string) $state['notified_to'] : '';
$notifyErr  = isset($state['notify_error']) ? (string) $state['notify_error'] : '';
$cronUrl    = zan_upd_cron_url();

$mailCfg  = zan_mail_config();
$hasPass  = (string) zan_cfg('mail_pass', '') !== '';

zan_admin_header('系统更新', 'update.php');
if ($notice) {
    zan_notice(zan_h($notice[0]), $notice[1]);
}
?>

<?php if ($repo === '') : ?>
<div class="za-notice is-ok">
    还没有填仓库地址。在下面的「更新设置」里填上 <code>owner/repo</code> 并保存，就能检查更新了。
</div>
<?php elseif ($hasNew) : ?>
<div class="za-notice is-ok">
    发现新版本<?php echo $remoteVer !== '' ? '（' . zan_h($remoteVer) . '）' : ''; ?>，当前是 <?php echo zan_h(ZAN_VERSION); ?>。更新前会自动备份，失败会自动回滚。
</div>
<?php elseif ($lastErr !== '') : ?>
<div class="za-notice is-error">上次检查失败：<?php echo zan_h($lastErr); ?></div>
<?php endif; ?>

<div class="za-card">
    <div class="za-card-head">
        <h2>版本状态</h2>
        <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>">
            <?php echo zan_csrf_field(); ?>
            <input type="hidden" name="action" value="check" />
            <button class="za-btn" type="submit">立即检查</button>
        </form>
    </div>

    <table class="za-kv">
        <tr><th>当前版本</th><td><b><?php echo zan_h(ZAN_VERSION); ?></b></td></tr>
        <tr><th>仓库</th><td><?php echo $repo !== '' ? zan_h($repo) : '<span class="za-desc">未设置</span>'; ?>
            <span class="za-pill"><?php echo zan_h($branch); ?></span></td></tr>
        <tr><th>最新版本</th><td>
            <?php if ($remoteVer === '') : ?>
                <?php echo $remoteSha !== '' ? '未知（未读到版本号）' : '尚未检查'; ?>
            <?php else : ?>
                <?php echo zan_h($remoteVer); ?>
                <?php if ($hasNew) : ?><span class="za-pill is-new">可更新</span>
                <?php else : ?><span class="za-pill is-ok">已是最新</span><?php endif; ?>
            <?php endif; ?>
        </td></tr>
        <tr><th>最新提交</th><td><?php echo $remoteSha !== '' ? '<code>' . zan_h(substr($remoteSha, 0, 7)) . '</code> ' . zan_h($remoteMsg) : '—'; ?></td></tr>
        <tr><th>提交时间</th><td><?php echo $remoteAt ? zan_h(date('Y-m-d H:i', $remoteAt)) : '—'; ?></td></tr>
        <tr><th>上次检查</th><td><?php echo !empty($state['checked_at']) ? zan_h(date('Y-m-d H:i', (int) $state['checked_at'])) : '从未'; ?></td></tr>
        <tr><th>下次检查</th><td>
            <?php
            if (!zan_upd_auto_check()) {
                echo '已关闭自动检查';
            } elseif ($nextCheck) {
                echo zan_h(date('Y-m-d H:i', $nextCheck));
            } else {
                echo '下次打开后台时';
            }
            ?>
        </td></tr>
        <tr><th>自动检查</th><td><?php echo zan_upd_auto_check()
            ? '开启 · 每 ' . (int) zan_cfg('update_interval', '6') . ' 小时'
            : '<span class="za-pill">已关闭</span>'; ?></td></tr>
        <tr><th>通知邮箱</th><td><?php echo $notifyTo !== '' ? zan_h($notifyTo) : '<span class="za-desc">未设置</span>'; ?></td></tr>
        <tr><th>上次通知</th><td><?php echo $notifiedAt
            ? zan_h(date('Y-m-d H:i', $notifiedAt)) . ($notifiedTo !== '' ? ' → ' . zan_h($notifiedTo) : '')
            : '还没有发过'; ?></td></tr>
        <?php if ($notifyErr !== '') : ?>
        <tr><th>发信失败</th><td class="za-desc"><?php echo zan_h($notifyErr); ?></td></tr>
        <?php endif; ?>
        <tr><th>上次更新</th><td><?php echo !empty($state['last_update_at']) ? zan_h(date('Y-m-d H:i', (int) $state['last_update_at'])) : '从未'; ?></td></tr>
    </table>

    <?php if ($hasNew) : ?>
    <div class="za-actions">
        <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>">
            <?php echo zan_csrf_field(); ?>
            <input type="hidden" name="action" value="apply" />
            <button class="za-btn" type="submit">立即更新到 <?php echo $remoteVer !== '' ? zan_h($remoteVer) : '最新提交'; ?></button>
        </form>
        <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>">
            <?php echo zan_csrf_field(); ?>
            <input type="hidden" name="action" value="skip" />
            <button class="za-btn is-quiet" type="submit">忽略这次</button>
        </form>
    </div>
    <p class="za-desc" style="margin-top:10px">下载并覆盖需要几秒到几十秒，取决于服务器访问 GitHub 的速度。中途别关页面。</p>
    <?php endif; ?>
</div>

<div class="za-card">
    <div class="za-card-head"><h2>更新了哪些东西</h2></div>
    <table class="za-kv">
        <tr><th>会覆盖</th><td class="za-tags"><code>api/</code><code>admin/</code><code>include/</code><code>assets/</code><code>index.php</code><code>README.md</code></td></tr>
        <tr><th>绝不动</th><td class="za-tags"><code>config.php</code><code>data/</code><code>install/</code><code>reset-password.php</code></td></tr>
        <tr><th>失败处理</th><td>整批回滚到更新前</td></tr>
    </table>
    <p class="za-desc" style="margin-top:10px">更新只换程序文件，数据库表结构不变（新版如需新表，会由后台提示补建）。</p>
</div>

<?php if ($history) : ?>
<div class="za-card">
    <div class="za-card-head"><h2>更新记录</h2></div>
    <table class="za-table">
        <thead><tr><th>时间</th><th>版本</th><th>提交</th><th class="num">文件数</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h) : ?>
            <tr>
                <td class="za-desc"><?php echo zan_h(date('Y-m-d H:i', (int) $h['at'])); ?></td>
                <td><?php echo zan_h($h['version']); ?></td>
                <td><code><?php echo zan_h($h['sha']); ?></code></td>
                <td class="num"><?php echo (int) $h['files']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="za-card">
    <div class="za-card-head"><h2>更新设置</h2></div>
    <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>">
        <?php echo zan_csrf_field(); ?>
        <input type="hidden" name="action" value="save" />

        <label for="update_repo">GitHub 仓库</label>
        <input type="text" id="update_repo" name="update_repo" value="<?php echo zan_h($repo); ?>" placeholder="owner/repo" />
        <p class="za-desc">只写 <code>owner/repo</code>，不要带 https:// 或 .git。仓库需公开，私有库要另外配 token（本版本不支持）。</p>

        <label for="update_branch">跟踪分支</label>
        <input type="text" id="update_branch" name="update_branch" value="<?php echo zan_h($branch); ?>" placeholder="main" />

        <label for="update_interval">检查间隔（小时）</label>
        <input type="text" id="update_interval" name="update_interval" value="<?php echo (int) zan_cfg('update_interval', '6'); ?>" />

        <label for="update_notify_email">通知邮箱</label>
        <input type="text" id="update_notify_email" name="update_notify_email" value="<?php echo zan_h((string) zan_cfg('update_notify_email', '')); ?>" placeholder="you@example.com" />
        <p class="za-desc">发现新版本时发一封邮件，同一版本只发一次。留空就只在后台提示。</p>

        <label class="za-check"><input type="checkbox" name="update_auto_check" value="1" <?php echo zan_upd_auto_check() ? 'checked' : ''; ?> /> 自动检查（打开后台时静默查一次，有新版本在菜单上标点）</label>
        <label class="za-check"><input type="checkbox" name="update_auto_apply" value="1" <?php echo zan_upd_auto_apply() ? 'checked' : ''; ?> /> 自动更新（发现就装，不再问；仓库只有你能推代码时才建议开）</label>

        <p style="margin-top:16px"><button class="za-btn" type="submit">保存设置</button></p>
    </form>
</div>

<details class="za-card">
    <summary class="za-card-head" style="cursor:pointer;margin:0">
        <h2>发信设置（邮件通知用）</h2>
        <span class="za-desc" style="margin:0">当前：<?php echo $mailCfg['driver'] === 'smtp' ? 'SMTP' : 'PHP mail()'; ?></span>
    </summary>
    <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>" style="margin-top:12px">
        <?php echo zan_csrf_field(); ?>
        <input type="hidden" name="action" value="save_mail" />

        <label for="mail_driver">发信方式</label>
        <select id="mail_driver" name="mail_driver">
            <option value="mail" <?php echo $mailCfg['driver'] === 'mail' ? 'selected' : ''; ?>>PHP mail()（服务器自带）</option>
            <option value="smtp" <?php echo $mailCfg['driver'] === 'smtp' ? 'selected' : ''; ?>>SMTP（推荐）</option>
        </select>
        <p class="za-desc">主机禁用了 <code>mail()</code> 或收不到信，就换 SMTP。下面几项只在 SMTP 时用得上。</p>

        <label for="mail_host">SMTP 服务器</label>
        <input type="text" id="mail_host" name="mail_host" value="<?php echo zan_h($mailCfg['host']); ?>" placeholder="smtp.qq.com" />

        <label for="mail_port">端口</label>
        <input type="text" id="mail_port" name="mail_port" value="<?php echo (int) $mailCfg['port']; ?>" />

        <label for="mail_secure">加密</label>
        <select id="mail_secure" name="mail_secure">
            <option value="ssl" <?php echo $mailCfg['secure'] === 'ssl' ? 'selected' : ''; ?>>SSL（465）</option>
            <option value="tls" <?php echo $mailCfg['secure'] === 'tls' ? 'selected' : ''; ?>>STARTTLS（587）</option>
            <option value="none" <?php echo $mailCfg['secure'] === 'none' ? 'selected' : ''; ?>>不加密（25）</option>
        </select>

        <label for="mail_user">SMTP 账号</label>
        <input type="text" id="mail_user" name="mail_user" value="<?php echo zan_h($mailCfg['user']); ?>" placeholder="you@qq.com" autocomplete="off" />

        <label for="mail_pass">授权码 / 密码</label>
        <input type="password" id="mail_pass" name="mail_pass" value="" autocomplete="new-password"
               placeholder="<?php echo $hasPass ? '已设置，留空表示不修改' : '邮箱的 SMTP 授权码'; ?>" />
        <p class="za-desc">QQ / 163 要的是「授权码」，不是登录密码。留空表示沿用已保存的值。</p>

        <label for="mail_from">发件邮箱</label>
        <input type="text" id="mail_from" name="mail_from" value="<?php echo zan_h($mailCfg['from']); ?>" placeholder="留空就用 SMTP 账号" />

        <label for="mail_from_name">发件人名称</label>
        <input type="text" id="mail_from_name" name="mail_from_name" value="<?php echo zan_h((string) zan_cfg('mail_from_name', ZAN_BRAND)); ?>" placeholder="<?php echo zan_h(ZAN_BRAND); ?>" />

        <label class="za-check"><input type="checkbox" name="mail_verify" value="1" <?php echo $mailCfg['verify'] ? 'checked' : ''; ?> /> 校验证书（服务器证书过期或自签时才取消）</label>

        <p style="margin-top:16px"><button class="za-btn" type="submit">保存发信设置</button></p>
    </form>

    <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>" style="margin-top:10px">
        <?php echo zan_csrf_field(); ?>
        <input type="hidden" name="action" value="testmail" />
        <button class="za-btn is-quiet" type="submit" <?php echo $notifyTo === '' ? 'disabled' : ''; ?>>发一封测试邮件</button>
        <?php if ($notifyTo === '') : ?><span class="za-desc" style="margin-left:8px">先填通知邮箱并保存</span><?php endif; ?>
    </form>

    <details style="margin-top:14px">
        <summary class="za-desc" style="cursor:pointer">常用邮箱参数</summary>
        <table class="za-table" style="font-size:13px">
            <thead><tr><th>邮箱</th><th>服务器</th><th>端口</th><th>加密</th></tr></thead>
            <tbody>
                <tr><td>QQ / 腾讯企业邮</td><td><code>smtp.qq.com</code></td><td>465</td><td>SSL</td></tr>
                <tr><td>163 / 126</td><td><code>smtp.163.com</code></td><td>465</td><td>SSL</td></tr>
                <tr><td>阿里企业邮</td><td><code>smtp.qiye.aliyun.com</code></td><td>465</td><td>SSL</td></tr>
                <tr><td>Gmail</td><td><code>smtp.gmail.com</code></td><td>587</td><td>STARTTLS</td></tr>
                <tr><td>Outlook</td><td><code>smtp.office365.com</code></td><td>587</td><td>STARTTLS</td></tr>
            </tbody>
        </table>
    </details>
</details>

<div class="za-card">
    <div class="za-card-head"><h2>无人值守</h2></div>
    <p class="za-desc">后台不开也能查：把这个地址加到服务器的计划任务（宝塔 / 1Panel / crontab），到点由服务器自己来访问，结果写进状态并发出邮件。</p>
    <input type="text" id="cron-url" value="<?php echo zan_h($cronUrl); ?>" readonly onclick="this.select()" />
    <div class="za-actions">
        <button class="za-btn is-quiet" type="button" onclick="var i=document.getElementById('cron-url');i.select();document.execCommand('copy');this.textContent='已复制';">复制地址</button>
        <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>">
            <?php echo zan_csrf_field(); ?>
            <input type="hidden" name="action" value="rotate" />
            <button class="za-btn is-quiet" type="submit" onclick="return confirm('重置后旧地址立即失效，服务器上的计划任务要同步修改。继续？');">重置令牌</button>
        </form>
    </div>
    <p class="za-desc" style="margin-top:12px">命令示例：<code>curl -s "上面这个地址" &gt;/dev/null</code>，建议每 6 小时跑一次，和上面的检查间隔一致。</p>
</div>

<div class="za-card">
    <div class="za-card-head"><h2>备份与回滚</h2></div>
    <?php if (!$backups) : ?>
        <p class="za-desc">更新前会自动把程序区备份到 <code>data/backup/update/</code>，这里就会出现。</p>
    <?php else : ?>
        <table class="za-table">
            <thead><tr><th>备份</th><th>时间</th><th class="num">操作</th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b) : ?>
                <tr>
                    <td><code><?php echo zan_h($b['name']); ?></code></td>
                    <td class="za-desc"><?php echo zan_h(date('Y-m-d H:i', $b['time'])); ?></td>
                    <td class="num">
                        <form method="post" action="<?php echo zan_h(zan_url('admin/update.php')); ?>">
                            <?php echo zan_csrf_field(); ?>
                            <input type="hidden" name="action" value="rollback" />
                            <input type="hidden" name="name" value="<?php echo zan_h($b['name']); ?>" />
                            <button class="za-btn is-quiet" type="submit" onclick="return confirm('用这份备份覆盖当前程序文件？');">回滚</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="za-desc" style="margin-top:10px">只保留最近 3 份。回滚只还原程序文件，不动数据库和 config.php。</p>
    <?php endif; ?>
</div>

<?php zan_admin_footer(); ?>
