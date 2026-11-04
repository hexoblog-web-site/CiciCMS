<?php
/**
 * CiciCMS 引导文件
 * 所有入口（前台 / 后台 / API）统一加载此文件
 */

define('CMS_ROOT', dirname(__DIR__));
define('CMS_INC', __DIR__);

/*
 * 目录分三类（详见 README 的「目录结构」一节）
 *   系统区：includes/、admin/、install/   —— 升级时整体覆盖，部署后可设只读
 *   内容区：content/                      —— 升级时必须保留，必须可写
 *   入口：  index.php、api.php、config.php —— 只放引导代码
 *
 * 内容区放用户的主题、上传附件、缓存日志。
 * 以前这三样直接摊在站点根目录，升级时容易和系统文件混在一起被覆盖。
 */
define('CMS_CONTENT', CMS_ROOT . '/content');
/** content 目录相对站点的路径，拼 URL 时用，只在这里写一次 */
define('CMS_CONTENT_REL', 'content');
define('CMS_THEMES', CMS_CONTENT . '/themes');
define('CMS_UPLOADS', CMS_CONTENT . '/uploads');
define('CMS_DATA', CMS_CONTENT . '/data');
/** 插件目录：content/plugins/<id>/，每个插件一个文件夹，升级不覆盖 */
define('CMS_PLUGINS', CMS_CONTENT . '/plugins');

define('CMS_VERSION', '2.1.0');

if (!is_file(CMS_ROOT . '/config.php')) {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $dir = preg_replace('#/(admin|install)$#', '', $dir);
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(503);
    }
    echo '<div style="font:14px/1.9 -apple-system,Segoe UI,sans-serif;padding:60px;text-align:center;color:#334155">'
       . '<h2 style="color:#0f172a">CiciCMS 尚未安装</h2>'
       . '<p>请先运行安装向导完成初始化：</p>'
       . '<p><a style="color:#2563eb" href="' . htmlspecialchars($dir . '/install/index.php', ENT_QUOTES, 'UTF-8') . '">进入安装向导 &rarr;</a></p>'
       . '</div>';
    exit;
}

/**
 * 旧版目录兼容：1.3 及以前 templates / uploads / data 都在站点根目录，
 * 1.4 起统一收进 content/ 下。用户直接覆盖程序文件升级时自动搬一次，
 * 已经搬过的站点什么都不做。
 *
 * @return array 实际搬动的目录名
 */
function cms_migrate_dirs()
{
    $moves = array(
        'templates' => CMS_THEMES,
        'uploads'   => CMS_UPLOADS,
        'data'      => CMS_DATA,
    );

    $done = array();
    foreach ($moves as $old => $new) {
        $src = CMS_ROOT . '/' . $old;
        if (!is_dir($src)) {
            continue;
        }

        // 目标已存在时，只有里面全是占位文件才清掉，绝不覆盖真实数据。
        // 占位文件（.htaccess / index.html）是程序自带的访问保护，
        // 先读出来，等老目录搬过来后如果缺就补回去。
        $keep = array();
        if (is_dir($new)) {
            $ph   = array('.htaccess', 'index.html', 'index.htm');
            $left = array_diff((array) @scandir($new), array_merge(array('.', '..'), $ph));
            if ($left) {
                continue;
            }
            foreach ($ph as $f) {
                if (is_file($new . '/' . $f)) {
                    $keep[$f] = (string) @file_get_contents($new . '/' . $f);
                    @unlink($new . '/' . $f);
                }
            }
            @rmdir($new);
        }

        $parent = dirname($new);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        if (@rename($src, $new)) {
            foreach ($keep as $f => $body) {
                if (!is_file($new . '/' . $f)) {
                    @file_put_contents($new . '/' . $f, $body);
                }
            }
            $done[] = $old;
        }
    }

    return $done;
}

cms_migrate_dirs();

require CMS_ROOT . '/config.php';
require CMS_INC . '/db.php';
require CMS_INC . '/functions.php';
require CMS_INC . '/content.php';
require CMS_INC . '/auth.php';
require CMS_INC . '/frontend.php';
require CMS_INC . '/builder.php';
require CMS_INC . '/backup.php';
require CMS_INC . '/plugin.php';
require CMS_INC . '/uploader.php';
require CMS_INC . '/upload.php';
require CMS_INC . '/zipreader.php';
require CMS_INC . '/updater.php';
require CMS_INC . '/clean.php';
require CMS_INC . '/tags.php';
require CMS_INC . '/upgrade.php';

if (!defined('CMS_DEBUG')) {
    define('CMS_DEBUG', false);
}

date_default_timezone_set(setting('timezone', 'Asia/Shanghai'));

// 数据库结构自动升级（有锁文件时只做一次文件检查）
cms_upgrade();

/*
 * 插件加载必须在数据库就绪之后：插件开关存在 config 表里。
 * 停用的插件不会被 include，性能与安全上都不留负担。
 */
cms_plugin_load();

error_reporting(E_ALL);
ini_set('display_errors', CMS_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (ensure_dir(CMS_DATA . '/logs')) {
    ini_set('error_log', CMS_DATA . '/logs/php-' . date('Ym') . '.log');
}

set_exception_handler(function ($ex) {
    log_msg('Exception: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if (CMS_DEBUG) {
        echo '<pre style="padding:20px;white-space:pre-wrap">' . e($ex->getMessage() . "\n\n" . $ex->getTraceAsString()) . '</pre>';
    } else {
        echo '<div style="font:14px/1.9 sans-serif;padding:40px;color:#b91c1c">服务器内部错误，详情请查看 content/data/logs 目录下的日志。</div>';
    }
    exit;
});

/*
 * 会话改为按需启动（cms_session_start）。
 * 静态站的前台请求根本用不到 session，原来的写法让每个访客都在服务器上
 * 留下一个 session 文件——虚拟主机上 inode 很快就被这些空文件吃满。
 * 后台、登录、CSRF、flash 会在用到时自行启动。
 */

if (!isset($GLOBALS['cms_static_mode'])) {
    $GLOBALS['cms_static_mode'] = false;
}

/*
 * 输出缓冲回调：前台页面统一走这里收尾，给插件留出插入 CSS / JS 的位置。
 * 后台与安装器不参与（它们的资源由 admin_head / admin_foot 单独处理），
 * 以免前台插件把脚本注入到后台页面里。
 */
ob_start(function ($html) {
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
    if (defined('CMS_ADMIN') || defined('CMS_INSTALL')
        || strpos($script, '/admin/') !== false || strpos($script, '/install/') !== false) {
        return $html;
    }
    if (!is_string($html) || $html === '') {
        return $html;
    }
    // 只处理 HTML 文档；静态文件下载、JSON 接口、图片一律原样返回
    if (stripos($html, '</body>') === false) {
        return $html;
    }
    if (!empty($GLOBALS['cms_no_plugin_output'])) {
        return $html;
    }
    return cms_finalize_html($html, 'dynamic');
});
