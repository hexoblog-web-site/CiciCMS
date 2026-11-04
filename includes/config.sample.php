<?php
/**
 * CiciCMS 配置示例
 * 安装向导会自动生成 config.php，一般无需手工创建
 * 手工迁移时：复制本文件为 config.php 并填写数据库信息
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'cicicms');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PREFIX', 'cms_');
define('DB_CHARSET', 'utf8mb4');

// 调试模式：生产环境务必保持 false
define('CMS_DEBUG', false);
