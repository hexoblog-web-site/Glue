<?php
/**
 * 发信：PHP mail() 与 SMTP 两种方式（只给「发现新版本」通知用）
 *
 * 用 socket 手写 SMTP，不引第三方库：虚拟主机常见「禁用了 mail()，但给了企业邮箱账号」，
 * 填上账号就能发信。配置存在 config.php（mail_* 开头的项）。
 */
if (!defined('ZAN_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** 站点主机名，用于邮件标题与 EHLO */
function zan_mail_host()
{
    $host = (string) parse_url(zan_abs_url('/'), PHP_URL_HOST);
    if ($host === '') {
        $host = 'localhost';
    }
    return preg_replace('#^www\.#i', '', $host);
}

/** 一份完整的发信配置 */
function zan_mail_config()
{
    $driver = (string) zan_cfg('mail_driver', 'mail');
    if ($driver !== 'smtp') {
        $driver = 'mail';
    }
    $secure = (string) zan_cfg('mail_secure', 'ssl');
    if (!in_array($secure, array('ssl', 'tls', 'none'), true)) {
        $secure = 'ssl';
    }
    $port = (int) zan_cfg('mail_port', 0);
    if ($port <= 0) {
        $port = $secure === 'ssl' ? 465 : ($secure === 'tls' ? 587 : 25);
    }
    return array(
        'driver' => $driver,
        'host'   => trim((string) zan_cfg('mail_host', '')),
        'port'   => $port,
        'secure' => $secure,
        'user'   => trim((string) zan_cfg('mail_user', '')),
        'pass'   => (string) zan_cfg('mail_pass', ''),
        'from'   => trim((string) zan_cfg('mail_from', '')),
        'name'   => trim((string) zan_cfg('mail_from_name', '')),
        'verify' => (string) zan_cfg('mail_verify', '1') === '1',
    );
}

/** 把「名字 <地址>」拼成合法头部；名字有中文时按 RFC 2047 编码 */
function zan_mail_addr($name, $mail)
{
    $mail = trim((string) $mail);
    $name = trim((string) $name);
    if ($name === '') {
        return '<' . $mail . '>';
    }
    if (preg_match('#[^\x20-\x7E]#', $name)) {
        $name = '=?UTF-8?B?' . base64_encode($name) . '?=';
    } else {
        $name = '"' . str_replace('"', '', $name) . '"';
    }
    return $name . ' <' . $mail . '>';
}

/** 按配置发一封纯文本邮件 */
function zan_mail_send($to, $subject, $body, &$err = '')
{
    $err = '';
    $cfg = zan_mail_config();

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = '收件地址不合法';
        return false;
    }

    if ($cfg['driver'] === 'smtp') {
        return zan_smtp_send($cfg, $to, $subject, $body, $err);
    }

    if (!function_exists('mail')) {
        $err = '服务器禁用了 mail() 函数，请改用 SMTP 发信';
        return false;
    }

    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'From: ' . zan_mail_addr($cfg['name'] !== '' ? $cfg['name'] : ZAN_BRAND, 'no-reply@' . zan_mail_host()) . "\r\n";

    $ok = @mail(
        $to,
        '=?UTF-8?B?' . base64_encode((string) $subject) . '?=',
        chunk_split(base64_encode((string) $body), 76, "\r\n"),
        $headers
    );
    if (!$ok) {
        $err = 'mail() 返回失败，改用 SMTP 发信更稳';
    }
    return (bool) $ok;
}

/* ---------------------------------------------------------------------------
 * SMTP
 * ------------------------------------------------------------------------- */

/** 读一条（可能是多行的）SMTP 响应 */
function zan_smtp_read($fp, &$err = '')
{
    $out = '';
    while (!feof($fp)) {
        $line = fgets($fp, 1024);
        if ($line === false) {
            break;
        }
        $out .= $line;
        /* "250-xxx" 是续行，"250 xxx" 才是最后一行 */
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    if ($out === '') {
        $err = 'SMTP 服务器没有响应，可能端口或加密方式填错了';
        return false;
    }
    return $out;
}

function zan_smtp_code($resp)
{
    return (int) substr(trim((string) $resp), 0, 3);
}

/** 发一条命令并校验响应码 */
function zan_smtp_cmd($fp, $cmd, $expect, &$err, $label = '')
{
    if ($cmd !== null) {
        if (@fwrite($fp, $cmd . "\r\n") === false) {
            $err = 'SMTP 连接已断开' . ($label !== '' ? '（' . $label . '）' : '');
            return false;
        }
    }
    $resp = zan_smtp_read($fp, $err);
    if ($resp === false) {
        return false;
    }
    $code  = zan_smtp_code($resp);
    $allow = is_array($expect) ? $expect : array($expect);
    if (!in_array($code, $allow, true)) {
        $err = 'SMTP 在' . ($label !== '' ? $label : '执行 ' . trim((string) $cmd))
            . '时返回 ' . $code . '：' . trim(preg_replace('/\s+/', ' ', $resp));
        return false;
    }
    return $resp;
}

/**
 * 用 SMTP 发信
 *
 * ssl 直连 465，tls 用 STARTTLS（通常 587），none 明文 25。
 * 认证优先 AUTH LOGIN，服务器只报 PLAIN 时用 AUTH PLAIN。
 */
function zan_smtp_send($cfg, $to, $subject, $body, &$err = '')
{
    $err  = '';
    $host = $cfg['host'];
    if ($host === '') {
        $err = '还没填 SMTP 服务器地址';
        return false;
    }
    if ($cfg['user'] === '' || $cfg['pass'] === '') {
        $err = '还没填 SMTP 账号或授权码';
        return false;
    }

    $from = $cfg['from'] !== '' ? $cfg['from'] : $cfg['user'];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $err = '发件邮箱不合法，请检查「发件邮箱」这一项';
        return false;
    }

    $remote = ($cfg['secure'] === 'ssl' ? 'ssl://' : '') . $host . ':' . $cfg['port'];
    $ctx    = stream_context_create(array(
        'ssl' => array(
            'verify_peer'       => $cfg['verify'],
            'verify_peer_name'  => $cfg['verify'],
            'allow_self_signed' => !$cfg['verify'],
            'peer_name'         => $host,
            'SNI_enabled'       => true,
        ),
    ));

    $eno  = 0;
    $estr = '';
    $fp   = @stream_socket_client($remote, $eno, $estr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $err = '连不上 ' . $host . ':' . $cfg['port'] . '（' . ($estr !== '' ? $estr : '错误码 ' . $eno) . '）'
            . '，检查地址、端口，以及主机商是否放行对外 25/465/587';
        return false;
    }
    stream_set_timeout($fp, 15);

    $ok = false;
    do {
        if (zan_smtp_cmd($fp, null, 220, $err, '建立连接') === false) {
            break;
        }

        $ehlo = zan_smtp_cmd($fp, 'EHLO ' . zan_mail_host(), 250, $err, 'EHLO');
        if ($ehlo === false) {
            $ehlo = zan_smtp_cmd($fp, 'HELO ' . zan_mail_host(), 250, $err, 'HELO');
            if ($ehlo === false) {
                break;
            }
        }

        if ($cfg['secure'] === 'tls') {
            if (zan_smtp_cmd($fp, 'STARTTLS', 220, $err, 'STARTTLS') === false) {
                break;
            }
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                $err = 'STARTTLS 握手失败，可能是服务器证书不受信任；可以先关掉「校验证书」再试';
                break;
            }
            $ehlo = zan_smtp_cmd($fp, 'EHLO ' . zan_mail_host(), 250, $err, 'EHLO（加密后）');
            if ($ehlo === false) {
                break;
            }
        }

        $authLine = '';
        foreach (preg_split('/\r?\n/', (string) $ehlo) as $line) {
            if (stripos($line, 'AUTH') !== false) {
                $authLine = strtoupper($line);
                break;
            }
        }

        $authed = false;
        if ($authLine !== '' && strpos($authLine, 'PLAIN') !== false && strpos($authLine, 'LOGIN') === false) {
            $token  = base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']);
            $authed = zan_smtp_cmd($fp, 'AUTH PLAIN ' . $token, 235, $err, 'AUTH PLAIN') !== false;
        } else {
            $r = zan_smtp_cmd($fp, 'AUTH LOGIN', 334, $err, 'AUTH LOGIN');
            if ($r !== false) {
                $r = zan_smtp_cmd($fp, base64_encode($cfg['user']), 334, $err, '发送账号');
            }
            if ($r !== false) {
                $r = zan_smtp_cmd($fp, base64_encode($cfg['pass']), 235, $err, '发送授权码');
            }
            $authed = $r !== false;
            if (!$authed && strpos((string) $err, '返回 ') !== false) {
                $err = '登录被拒绝：' . $err . '。多数邮箱这里要填「授权码」而不是登录密码';
            }
        }
        if (!$authed) {
            break;
        }

        if (zan_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', 250, $err, 'MAIL FROM') === false) {
            break;
        }
        if (zan_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', array(250, 251), $err, 'RCPT TO') === false) {
            break;
        }
        if (zan_smtp_cmd($fp, 'DATA', 354, $err, 'DATA') === false) {
            break;
        }

        $tag     = zan_mail_host();
        $headers = array(
            'Date: ' . date('r'),
            'From: ' . zan_mail_addr($cfg['name'] !== '' ? $cfg['name'] : ZAN_BRAND, $from),
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode((string) $subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $tag . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        );
        $data = implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode((string) $body), 76, "\r\n");

        if (@fwrite($fp, $data . ".\r\n") === false) {
            $err = '发送正文时连接断开';
            break;
        }
        if (zan_smtp_cmd($fp, null, 250, $err, '投递正文') === false) {
            break;
        }

        $ok = true;
        @fwrite($fp, "QUIT\r\n");
    } while (false);

    @fclose($fp);
    return $ok;
}
