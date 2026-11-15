<?php
/**
 * 安装入口：没有 config.php 先去配数据库，有则继续建表建管理员。
 */
define('ZAN_INSTALL_UI', true);
require_once dirname(__DIR__) . '/include/install-ui.php';

header('Location: ' . (zan_config_exists() ? 'install.php' : 'setup-config.php'));
exit;
