<?php
/**
 * 容器启动自检（由 docker/entrypoint.sh 调用）。
 *
 * 做的事只有四件，全部幂等：
 *   1. 等数据库就绪（MySQL 容器通常比 Web 慢十几秒）
 *   2. 没有 config.php 就按环境变量生成一份，存到 /data
 *   3. 建表（CREATE TABLE IF NOT EXISTS）
 *   4. 没有任何管理员时，创建一个
 *
 * 任何一步失败都会打印原因并以非 0 退出，容器仍然启动 ——
 * 这样打开页面能看到「数据库连接失败」，而不是一个起不来的容器。
 */

define('ZAN_APP', true);
define('ZAN_BOOT', true);

const BOOT_CONFIG = '/data/config.php';
const BOOT_ROOT   = '/var/www/html';

function boot_env($key, $default = '')
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : trim((string) $v);
}

function boot_log($msg)
{
    fwrite(STDOUT, '[zan] ' . $msg . PHP_EOL);
}

function boot_warn($msg)
{
    fwrite(STDERR, '[zan] ' . $msg . PHP_EOL);
}

function boot_lines($text)
{
    $out = array();
    foreach (preg_split('/[\r\n,]+/', (string) $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return array_values(array_unique($out));
}

/** 生成/更新配置文件；已有配置默认不动，除非 FORCE_CONFIG=1 */
function boot_write_config()
{
    $force = boot_env('FORCE_CONFIG') === '1';
    if (is_file(BOOT_CONFIG) && !$force) {
        return false;
    }

    $old = array();
    if (is_file(BOOT_CONFIG)) {
        $prev = @include BOOT_CONFIG;
        if (is_array($prev)) {
            $old = $prev;
        }
    }

    $cfg = array(
        'db_host'      => boot_env('DB_HOST', 'db'),
        'db_port'      => boot_env('DB_PORT', '3306'),
        'db_name'      => boot_env('DB_NAME', 'zan'),
        'db_user'      => boot_env('DB_USER', 'zan'),
        'db_pass'      => (string) getenv('DB_PASS'),
        'table_prefix' => boot_env('DB_PREFIX', 'zan_'),
        /* 换过密钥等于把所有点赞去重指纹作废（点赞数不变，但同一个人可以再点一次） */
        'site_secret'  => isset($old['site_secret']) && strlen((string) $old['site_secret']) >= 32
            ? (string) $old['site_secret']
            : bin2hex(random_bytes(32)),
        'site_name'       => isset($old['site_name']) ? (string) $old['site_name'] : boot_env('SITE_NAME', ''),
        'allowed_origins' => !empty($old['allowed_origins'])
            ? $old['allowed_origins']
            : boot_lines(boot_env('ALLOWED_ORIGINS', '')),
        'trusted_proxies' => !empty($old['trusted_proxies'])
            ? $old['trusted_proxies']
            : boot_lines(boot_env('TRUSTED_PROXIES', '')),
    );

    $body = "<?php\n/**\n * Sugar-Box 配置（容器首次启动生成，后台设置页会更新）\n */\n"
        . 'return ' . var_export($cfg, true) . ";\n";

    $tmp = BOOT_CONFIG . '.tmp';
    if (@file_put_contents($tmp, $body) === false || !@rename($tmp, BOOT_CONFIG)) {
        throw new RuntimeException('无法写入 ' . BOOT_CONFIG . '（检查数据卷权限）');
    }
    @chmod(BOOT_CONFIG, 0640);
    boot_log('已生成 ' . BOOT_CONFIG . ($force ? '（FORCE_CONFIG=1 覆盖）' : ''));
    return true;
}

function boot_connect(array $cfg, $tries = 45)
{
    $dsn = 'mysql:host=' . $cfg['db_host']
        . (empty($cfg['db_port']) ? '' : ';port=' . $cfg['db_port'])
        . ';dbname=' . $cfg['db_name']
        . ';charset=utf8mb4';

    $last = null;
    for ($i = 1; $i <= $tries; $i++) {
        try {
            $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 3,
            ));
            if ($i > 1) {
                boot_log('数据库已就绪（第 ' . $i . ' 次尝试）');
            }
            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
            if ($i < $tries) {
                usleep(1000000);
            }
        }
    }
    throw new RuntimeException('连接数据库失败：' . ($last ? $last->getMessage() : '未知原因'));
}

function boot_random_password($length = 16)
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max   = strlen($chars) - 1;
    $out   = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

try {
    boot_write_config();
    if (!is_file(BOOT_CONFIG)) {
        throw new RuntimeException('配置文件不存在：' . BOOT_CONFIG);
    }
    $cfg = @include BOOT_CONFIG;
    if (!is_array($cfg)) {
        throw new RuntimeException('配置文件格式不对：' . BOOT_CONFIG);
    }

    $pdo = boot_connect($cfg);

    require_once BOOT_ROOT . '/include/schema.php';
    $prefix = isset($cfg['table_prefix']) ? (string) $cfg['table_prefix'] : 'zan_';
    zan_create_tables($pdo, $prefix);
    boot_log('数据表已就绪（前缀 ' . $prefix . '）');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'admin`')->fetchColumn();
    if ($count === 0) {
        $email = boot_env('ADMIN_EMAIL', 'admin@example.com');
        $pass  = boot_env('ADMIN_PASSWORD');
        $generated = false;
        if ($pass === '') {
            $pass = boot_random_password(16);
            $generated = true;
        }
        $stmt = $pdo->prepare('INSERT INTO `' . $prefix . 'admin` (`email`, `password_hash`, `created_at`) VALUES (?, ?, NOW())');
        $stmt->execute(array($email, password_hash($pass, PASSWORD_DEFAULT)));

        boot_log('已创建管理员：' . $email);
        if ($generated) {
            boot_log('初始密码（只显示这一次，请立刻登录后台修改）：' . $pass);
        } else {
            boot_log('初始密码取自 ADMIN_PASSWORD 环境变量');
        }
    } else {
        boot_log('管理员已存在（' . $count . ' 个），跳过创建');
    }
    boot_log('自检完成');
} catch (Throwable $e) {
    boot_warn('自检未通过：' . $e->getMessage());
    exit(1);
}
