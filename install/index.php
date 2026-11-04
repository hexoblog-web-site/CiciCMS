<?php
/**
 * HtmlCms 安装向导
 * 单文件多步安装：环境检测 -> 参数配置 -> 建库建表 -> 完成
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('INS_VERSION', '1.9.4');
define('INS_ROOT', dirname(__DIR__));
define('INS_CONTENT', INS_ROOT . '/content');
define('INS_LOCK', INS_CONTENT . '/data/install.lock');
define('INS_CONFIG', INS_ROOT . '/config.php');

if (empty($_SESSION['ins_token'])) {
    $_SESSION['ins_token'] = bin2hex(random_bytes(16));
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function is_post_request()
{
    return isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST';
}

function dir_writable($dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        return false;
    }
    $f = $dir . '/.write_test_' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($f, 'ok') === false) {
        return false;
    }
    @unlink($f);
    return true;
}

function detect_install_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir   = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/install/index.php'));
    $dir   = preg_replace('#/install$#', '', $dir);
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function env_items()
{
    $items = array();

    $items[] = array('PHP 版本', version_compare(PHP_VERSION, '7.0.0', '>='), PHP_VERSION, '>= 7.0', true);
    $items[] = array('PDO 扩展', extension_loaded('pdo'), extension_loaded('pdo') ? '已安装' : '未安装', '必需', true);
    $items[] = array('PDO_MySQL 扩展', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已安装' : '未安装', '必需', true);
    $items[] = array('JSON 扩展', extension_loaded('json'), extension_loaded('json') ? '已安装' : '未安装', '必需', true);
    $items[] = array('Mbstring 扩展', extension_loaded('mbstring'), extension_loaded('mbstring') ? '已安装' : '未安装', '推荐（中文截断）', false);
    $items[] = array('GD 扩展', extension_loaded('gd'), extension_loaded('gd') ? '已安装' : '未安装', '推荐（图片处理）', false);
    $items[] = array('根目录可写', dir_writable(INS_ROOT), dir_writable(INS_ROOT) ? '可写' : '不可写', '生成静态文件 / config.php', true);
    $items[] = array('content 目录可写', dir_writable(INS_CONTENT), dir_writable(INS_CONTENT) ? '可写' : '不可写', '主题 / 附件 / 缓存', true);
    $items[] = array('content/data 可写', dir_writable(INS_CONTENT . '/data'), dir_writable(INS_CONTENT . '/data') ? '可写' : '不可写', '缓存与日志', true);
    $items[] = array('content/themes 可写', dir_writable(INS_CONTENT . '/themes'), dir_writable(INS_CONTENT . '/themes') ? '可写' : '不可写', '在线编辑模板', false);
    $items[] = array('content/uploads 可写', dir_writable(INS_CONTENT . '/uploads'), dir_writable(INS_CONTENT . '/uploads') ? '可写' : '不可写', '媒体上传', false);
    $items[] = array('PHP Session', function_exists('session_start'), function_exists('session_start') ? '可用' : '不可用', '后台登录', true);

    return $items;
}

function install_sql($prefix)
{
    $sql   = array();
    $sql[] = "CREATE TABLE IF NOT EXISTS `{$prefix}config` (
  `k` varchar(64) NOT NULL,
  `v` mediumtext,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS `{$prefix}user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(120) NOT NULL DEFAULT '',
  `last_login` int unsigned NOT NULL DEFAULT 0,
  `last_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS `{$prefix}category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `dirname` varchar(60) NOT NULL,
  `parent_id` int unsigned NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `description` varchar(255) NOT NULL DEFAULT '',
  `keywords` varchar(255) NOT NULL DEFAULT '',
  `banner` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `dirname` (`dirname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS `{$prefix}article` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cat_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(200) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `excerpt` varchar(500) NOT NULL DEFAULT '',
  `content` mediumtext,
  `content_src` mediumtext NULL,
  `cover` varchar(255) NOT NULL DEFAULT '',
  `tags` varchar(255) NOT NULL DEFAULT '',
  `flag` varchar(60) NOT NULL DEFAULT '',
  `author_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `is_top` tinyint NOT NULL DEFAULT 0,
  `views` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `cat_id` (`cat_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS `{$prefix}page` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `content` mediumtext,
  `content_src` mediumtext NULL,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS `{$prefix}flink` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT '',
  `logo` varchar(255) NOT NULL DEFAULT '',
  `desc` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `target` tinyint NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS `{$prefix}attachment` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(255) NOT NULL,
  `name` varchar(200) NOT NULL,
  `mime` varchar(80) NOT NULL DEFAULT '',
  `size` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    return $sql;
}

function install_config_content($conf)
{
    $esc = function ($v) {
        return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $v);
    };
    $out  = "<?php\n";
    $out .= "/**\n * HtmlCms 配置文件\n * 由安装程序于 " . date('Y-m-d H:i:s') . " 自动生成\n * 迁移站点时只需修改本文件的数据库信息\n */\n\n";
    $out .= "define('DB_HOST', '" . $esc($conf['host']) . "');\n";
    $out .= "define('DB_PORT', '" . $esc($conf['port']) . "');\n";
    $out .= "define('DB_NAME', '" . $esc($conf['name']) . "');\n";
    $out .= "define('DB_USER', '" . $esc($conf['user']) . "');\n";
    $out .= "define('DB_PASS', '" . $esc($conf['pass']) . "');\n";
    $out .= "define('DB_PREFIX', '" . $esc($conf['prefix']) . "');\n";
    $out .= "define('DB_CHARSET', 'utf8mb4');\n\n";
    $out .= "// 调试模式：生产环境请保持 false\n";
    $out .= "define('CMS_DEBUG', false);\n";
    return $out;
}

$installed = is_file(INS_LOCK) || is_file(INS_CONFIG);
$step      = isset($_GET['step']) ? (int) $_GET['step'] : 1;
if ($step < 1 || $step > 4) {
    $step = 1;
}
// 已安装：除“完成页”外一律只展示锁定提示，禁止任何写库动作
$blocked = ($installed && $step !== 4);

$errors  = array();
$old     = array(
    'db_host'    => 'localhost',
    'db_port'    => '3306',
    'db_name'    => 'htmlcms',
    'db_prefix'  => 'cms_',
    'db_user'    => 'root',
    'site_name'  => '我的网站',
    'site_url'   => detect_install_url(),
    'admin_user' => 'admin',
    'admin_email' => '',
);

/* ---------- 第 3 步：执行安装 ---------- */
if ($step === 3 && !$blocked) {
    if (!is_post_request()) {
        header('Location: index.php?step=2');
        exit;
    }
    $token = isset($_POST['_token']) ? (string) $_POST['_token'] : '';
    if (empty($_SESSION['ins_token']) || !hash_equals($_SESSION['ins_token'], $token)) {
        $errors[] = '页面已过期，请重新提交。';
    }

    foreach ($old as $k => $v) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) {
            $old[$k] = trim($_POST[$k]);
        }
    }
    $dbPass  = isset($_POST['db_pass']) ? (string) $_POST['db_pass'] : '';
    $adminPw = isset($_POST['admin_pass']) ? (string) $_POST['admin_pass'] : '';
    $adminPw2 = isset($_POST['admin_pass2']) ? (string) $_POST['admin_pass2'] : '';

    if ($old['db_host'] === '') {
        $errors[] = '数据库地址不能为空';
    }
    if ($old['db_name'] === '') {
        $errors[] = '数据库名不能为空';
    }
    if ($old['db_user'] === '') {
        $errors[] = '数据库用户名不能为空';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,20}$/', $old['db_prefix'])) {
        $errors[] = '表前缀只能包含字母、数字、下划线，长度 1-20';
    }
    if (!preg_match('/^[A-Za-z0-9_@.\-]{3,30}$/', $old['admin_user'])) {
        $errors[] = '管理员账号需为 3-30 位字母、数字或 _ @ . -';
    }
    if (strlen($adminPw) < 6) {
        $errors[] = '管理员密码至少 6 位';
    }
    if ($adminPw !== $adminPw2) {
        $errors[] = '两次输入的管理员密码不一致';
    }
    if ($old['site_name'] === '') {
        $errors[] = '网站名称不能为空';
    }
    if ($old['site_url'] === '' || !preg_match('#^https?://#i', $old['site_url'])) {
        $errors[] = '站点地址需以 http:// 或 https:// 开头';
    }
    if ($old['admin_email'] !== '' && !filter_var($old['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = '管理员邮箱格式不正确';
    }

    if (empty($errors)) {
        $pdo = null;
        try {
            $serverDsn = 'mysql:host=' . $old['db_host'] . ';port=' . $old['db_port'] . ';charset=utf8mb4';
            $opt = array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            );
            $pdo = new PDO($serverDsn, $old['db_user'], $dbPass, $opt);
            $dbNameSafe = str_replace('`', '', $old['db_name']);
            try {
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbNameSafe . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            } catch (PDOException $ignored) {
                // 无建库权限时，数据库可能已由主机面板创建
            }
            $pdo = new PDO(
                'mysql:host=' . $old['db_host'] . ';port=' . $old['db_port'] . ';dbname=' . $old['db_name'] . ';charset=utf8mb4',
                $old['db_user'],
                $dbPass,
                $opt
            );
        } catch (PDOException $ex) {
            $errors[] = '数据库连接失败：' . $ex->getMessage();
        }

        if (empty($errors) && $pdo) {
            try {
                $exists = $pdo->query("SHOW TABLES LIKE '" . str_replace(array("'", '%', '_'), array("\\'", '\\%', '\\_'), $old['db_prefix']) . "config'")->fetchAll();
                if ($exists) {
                    $errors[] = '数据表 ' . $old['db_prefix'] . 'config 已存在，请更换表前缀或清空该数据库';
                }
            } catch (PDOException $ex) {
                $errors[] = '检查数据表失败：' . $ex->getMessage();
            }
        }

        if (empty($errors) && $pdo) {
            try {
                foreach (install_sql($old['db_prefix']) as $statement) {
                    $pdo->exec($statement);
                }

                $now = time();
                $ins = $pdo->prepare('INSERT INTO `' . $old['db_prefix'] . 'config` (`k`,`v`) VALUES (?,?)');
                $defaults = array(
                    'site_name'     => $old['site_name'],
                    'site_url'      => rtrim($old['site_url'], '/'),
                    'site_desc'     => '一个由 HtmlCms 驱动的静态站点',
                    'site_keywords' => '',
                    'site_icp'      => '',
                    'site_footer'   => '',
                    'per_page'      => '10',
                    'theme'         => 'default',
                    'auto_build'    => '1',
                    'timezone'      => 'Asia/Shanghai',
                    'last_build'    => '0',
                );
                foreach ($defaults as $k => $v) {
                    $ins->execute(array($k, $v));
                }

                $userStmt = $pdo->prepare('INSERT INTO `' . $old['db_prefix'] . 'user`
                    (`username`,`password`,`email`,`last_login`,`last_ip`,`created_at`) VALUES (?,?,?,?,?,?)');
                $userStmt->execute(array(
                    $old['admin_user'],
                    password_hash($adminPw, PASSWORD_DEFAULT),
                    $old['admin_email'],
                    0,
                    '',
                    $now,
                ));
                $uid = (int) $pdo->lastInsertId();

                $catStmt = $pdo->prepare('INSERT INTO `' . $old['db_prefix'] . 'category`
                    (`name`,`dirname`,`parent_id`,`sort`,`description`,`keywords`) VALUES (?,?,?,?,?,?)');
                $catStmt->execute(array('默认分类', 'default', 0, 0, '站点默认分类', ''));
                $catId = (int) $pdo->lastInsertId();

                $welcome = '<p>欢迎使用 <strong>HtmlCms</strong>——一个可直接跑在虚拟主机上的 PHP + MySQL 静态化 CMS。</p>'
                         . '<h2>它做了什么</h2>'
                         . '<ul><li>后台发布内容，一键生成纯静态 HTML，访问速度接近 CDN；</li>'
                         . '<li>后台结构参考 Z-Blog PHP：文章、分类、单页、标签、模板、生成管理；</li>'
                         . '<li>数据库访问全部走预处理语句，表单带 CSRF 校验，写入使用原子替换。</li></ul>'
                         . '<h2>接下来</h2>'
                         . '<p>进入后台 → 系统设置 → 改成你的站点信息 → 写第一篇文章 → 点击“生成静态”。</p>'
                         . '<p>为安全起见，安装完成后请删除 <code>install</code> 目录。</p>';

                $artStmt = $pdo->prepare('INSERT INTO `' . $old['db_prefix'] . 'article`
                    (`cat_id`,`title`,`slug`,`excerpt`,`content`,`cover`,`tags`,`author_id`,`status`,`is_top`,`views`,`created_at`,`updated_at`)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $artStmt->execute(array(
                    $catId,
                    '欢迎使用 HtmlCms',
                    'hello-world',
                    'HtmlCms 安装成功，这是一篇示例文章，可在后台修改或删除。',
                    $welcome,
                    '',
                    'HtmlCms,静态CMS,安装',
                    $uid,
                    1,
                    1,
                    0,
                    $now,
                    $now,
                ));

                $pageStmt = $pdo->prepare('INSERT INTO `' . $old['db_prefix'] . 'page`
                    (`title`,`slug`,`content`,`status`,`sort`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?)');
                $pageStmt->execute(array(
                    '关于本站',
                    'about',
                    '<p>这里是「关于本站」页面，可在后台的“单页管理”中修改内容。</p><p>单页适合放关于我们、联系方式、版权声明等固定内容。</p>',
                    1,
                    1,
                    $now,
                    $now,
                ));

                $config = install_config_content(array(
                    'host'   => $old['db_host'],
                    'port'   => $old['db_port'],
                    'name'   => $old['db_name'],
                    'user'   => $old['db_user'],
                    'pass'   => $dbPass,
                    'prefix' => $old['db_prefix'],
                ));
                foreach (array('/content', '/content/data', '/content/data/logs', '/content/data/backup', '/content/uploads') as $dir) {
                    if (!is_dir(INS_ROOT . $dir)) {
                        @mkdir(INS_ROOT . $dir, 0755, true);
                    }
                }
                if (@file_put_contents(INS_CONFIG, $config, LOCK_EX) === false) {
                    $errors[] = '无法写入 config.php，请检查站点根目录写入权限';
                } else {
                    @file_put_contents(INS_LOCK, 'installed at ' . date('Y-m-d H:i:s'), LOCK_EX);
                    @mkdir(INS_CONTENT . '/data/logs', 0755, true);
                    @mkdir(INS_CONTENT . '/data/backup', 0755, true);
                }
            } catch (PDOException $ex) {
                $errors[] = '安装过程出错：' . $ex->getMessage();
            }
        }
    }

    if (empty($errors)) {
        $buildResult = null;
        try {
            require_once INS_ROOT . '/includes/bootstrap.php';
            $buildResult = build_all();
        } catch (Throwable $ex) {
            $buildResult = array('ok' => false, 'files' => 0, 'errors' => array($ex->getMessage()), 'ms' => 0);
        }
        $_SESSION['install_result'] = array(
            'site_url' => rtrim($old['site_url'], '/'),
            'user'     => $old['admin_user'],
            'build'    => $buildResult,
        );
        unset($_SESSION['ins_token']);
        header('Location: index.php?step=4');
        exit;
    }

    // 有错误：回显到第 2 步
    $step = 2;
}

/* ---------- 第 4 步：完成 ---------- */
if ($step === 4) {
    $result = isset($_SESSION['install_result']) ? $_SESSION['install_result'] : null;
    if (!$result) {
        // 已安装过、又直接访问 ?step=4：当作锁定处理，不回显管理员账号等信息
        if ($installed) {
            $blocked = true;
        } else {
            header('Location: index.php?step=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HtmlCms 安装向导</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f1f5f9;color:#0f172a;font:14px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:860px;margin:0 auto;padding:40px 20px 60px}
.brand{font-size:22px;font-weight:700;letter-spacing:.5px;margin-bottom:4px}
.brand span{font-weight:400;font-size:13px;color:#64748b;margin-left:8px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
.steps{display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap}
.steps div{flex:1;min-width:150px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:13px;color:#94a3b8}
.steps div.on{border-color:#2563eb;color:#1d4ed8;background:#eff6ff;font-weight:600}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:26px 28px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
h1{font-size:18px;margin:0 0 6px}
h2{font-size:15px;margin:22px 0 10px}
p.note{color:#64748b;font-size:13px;margin:0 0 18px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #eef2f7}
th{color:#64748b;font-weight:500;background:#f8fafc}
.badge{display:inline-block;padding:1px 8px;border-radius:20px;font-size:12px}
.ok{background:#ecfdf5;color:#047857}
.warn{background:#fffbeb;color:#b45309}
.bad{background:#fef2f2;color:#b91c1c}
.row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.field{flex:1;min-width:240px}
label{display:block;font-size:13px;color:#334155;margin-bottom:5px}
input[type=text],input[type=password],input[type=email],input[type=number]{width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;outline:none;background:#fff}
input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.hint{font-size:12px;color:#94a3b8;margin-top:4px}
.btn{display:inline-block;background:#2563eb;color:#fff;border:0;padding:10px 22px;border-radius:8px;font-size:14px;cursor:pointer;text-decoration:none}
.btn:hover{background:#1d4ed8}
.btn.ghost{background:#fff;color:#334155;border:1px solid #cbd5e1}
.btn[disabled]{background:#cbd5e1;cursor:not-allowed}
.actions{display:flex;justify-content:space-between;align-items:center;margin-top:24px;gap:12px;flex-wrap:wrap}
.alert{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.alert ul{margin:6px 0 0 18px;padding:0}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:12px}
.ok-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;font-size:13px;color:#166534;margin:16px 0}
.kv{display:flex;gap:10px;font-size:13px;padding:4px 0;color:#334155}
.kv b{color:#0f172a;font-weight:600;min-width:80px}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">HtmlCms <span>PHP + MySQL 静态化内容管理系统 v<?php echo INS_VERSION; ?></span></div>
    <div class="sub">三步完成安装，安装后即可在后台发布内容并生成纯静态 HTML。</div>

    <?php if ($blocked): ?>
        <div class="card">
            <h1>系统已安装</h1>
            <p class="note">检测到 <code>config.php</code> 或 <code>data/install.lock</code>，安装程序已锁定。</p>
            <div class="ok-box">如需重新安装，请先删除 <code>config.php</code> 与 <code>data/install.lock</code>，并清空原数据表。</div>
            <div class="actions">
                <a class="btn" href="../admin/">进入后台</a>
                <a class="btn ghost" href="../">访问前台</a>
            </div>
        </div>
    <?php elseif ($step === 1): ?>
        <?php $items = env_items(); $fatal = false; foreach ($items as $it) { if ($it[4] && !$it[1]) { $fatal = true; } } ?>
        <div class="steps">
            <div class="on">1. 环境检测</div><div>2. 参数配置</div><div>3. 完成</div>
        </div>
        <div class="card">
            <h1>运行环境检测</h1>
            <p class="note">以下为当前服务器环境。标红的必需项未通过时无法继续安装。</p>
            <table>
                <tr><th style="width:38%">检测项</th><th style="width:20%">当前</th><th style="width:26%">要求</th><th>结果</th></tr>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?php echo h($it[0]); ?></td>
                        <td><?php echo h($it[2]); ?></td>
                        <td><?php echo h($it[3]); ?></td>
                        <td>
                            <?php if ($it[1]): ?>
                                <span class="badge ok">通过</span>
                            <?php elseif ($it[4]): ?>
                                <span class="badge bad">不通过</span>
                            <?php else: ?>
                                <span class="badge warn">建议开启</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <div class="actions">
                <span class="hint">建议 PHP 7.2+、MySQL 5.6+，虚拟主机无需特殊扩展。</span>
                <?php if ($fatal): ?>
                    <a class="btn" href="javascript:void(0)" style="background:#cbd5e1;cursor:not-allowed">请先修复必需项</a>
                <?php else: ?>
                    <a class="btn" href="index.php?step=2">下一步：配置参数</a>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($step === 2): ?>
        <div class="steps">
            <div>1. 环境检测</div><div class="on">2. 参数配置</div><div>3. 完成</div>
        </div>
        <div class="card">
            <h1>数据库与站点配置</h1>
            <p class="note">数据库需提前在主机面板创建（多数虚拟主机不提供建库权限）。</p>
            <?php if (!empty($errors)): ?>
                <div class="alert">
                    <strong>请修正以下问题：</strong>
                    <ul><?php foreach ($errors as $er): ?><li><?php echo h($er); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <form method="post" action="index.php?step=3" autocomplete="off">
                <input type="hidden" name="_token" value="<?php echo h($_SESSION['ins_token']); ?>">

                <h2>数据库</h2>
                <div class="row">
                    <div class="field"><label>数据库地址</label><input type="text" name="db_host" value="<?php echo h($old['db_host']); ?>" required></div>
                    <div class="field" style="max-width:160px"><label>端口</label><input type="text" name="db_port" value="<?php echo h($old['db_port']); ?>"></div>
                </div>
                <div class="row">
                    <div class="field"><label>数据库名</label><input type="text" name="db_name" value="<?php echo h($old['db_name']); ?>" required></div>
                    <div class="field"><label>表前缀</label><input type="text" name="db_prefix" value="<?php echo h($old['db_prefix']); ?>" required><div class="hint">同一数据库装多个站点时请使用不同前缀</div></div>
                </div>
                <div class="row">
                    <div class="field"><label>数据库用户名</label><input type="text" name="db_user" value="<?php echo h($old['db_user']); ?>" required></div>
                    <div class="field"><label>数据库密码</label><input type="password" name="db_pass" value=""></div>
                </div>

                <h2>站点</h2>
                <div class="row">
                    <div class="field"><label>网站名称</label><input type="text" name="site_name" value="<?php echo h($old['site_name']); ?>" required></div>
                    <div class="field"><label>站点地址</label><input type="text" name="site_url" value="<?php echo h($old['site_url']); ?>" required><div class="hint">静态页面中的链接基于此地址，请勿以斜杠结尾</div></div>
                </div>

                <h2>管理员</h2>
                <div class="row">
                    <div class="field"><label>管理员账号</label><input type="text" name="admin_user" value="<?php echo h($old['admin_user']); ?>" required></div>
                    <div class="field"><label>管理员邮箱（可选）</label><input type="email" name="admin_email" value="<?php echo h($old['admin_email']); ?>"></div>
                </div>
                <div class="row">
                    <div class="field"><label>管理员密码</label><input type="password" name="admin_pass" required><div class="hint">至少 6 位，使用 password_hash 加密存储</div></div>
                    <div class="field"><label>确认密码</label><input type="password" name="admin_pass2" required></div>
                </div>

                <div class="actions">
                    <a class="btn ghost" href="index.php?step=1">上一步</a>
                    <button class="btn" type="submit">开始安装</button>
                </div>
            </form>
        </div>
    <?php elseif ($step === 4): ?>
        <div class="steps">
            <div>1. 环境检测</div><div>2. 参数配置</div><div class="on">3. 完成</div>
        </div>
        <div class="card">
            <h1>安装完成</h1>
            <p class="note">数据库表已创建，管理员账号已写入，站点信息已初始化。</p>
            <?php if (is_array($result['build'])): ?>
                <div class="ok-box">
                    <?php if (!empty($result['build']['ok'])): ?>
                        已自动生成 <?php echo (int) $result['build']['files']; ?> 个静态文件，耗时 <?php echo (int) $result['build']['ms']; ?> ms，现在访问站点首页即可看到静态页面。
                    <?php else: ?>
                        安装成功，但首次生成静态页未完全成功（<?php echo count((array) $result['build']['errors']); ?> 个问题），请进入后台“生成静态”重试。
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="kv"><b>后台地址</b><span><?php echo h($result['site_url']); ?>/admin/</span></div>
            <div class="kv"><b>管理员</b><span><?php echo h($result['user']); ?></span></div>
            <div class="kv"><b>安全提示</b><span>请立即删除 <code>install</code> 目录，并确认 config.php 权限为 644。</span></div>
            <div class="actions">
                <a class="btn" href="../admin/">进入后台</a>
                <a class="btn ghost" href="../index.php">查看前台（动态预览）</a>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
