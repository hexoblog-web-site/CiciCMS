<?php
/**
 * CiciCMS 公共函数库
 * 兼容 PHP 7.0+ / MySQL 5.6+，无 Composer 依赖
 */

/* ============ 基础 IO ============ */

function e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function is_post()
{
    return isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST';
}

function post($key, $default = '')
{
    if (!isset($_POST[$key])) {
        return $default;
    }
    $v = $_POST[$key];
    return is_array($v) ? $v : trim((string) $v);
}

function get($key, $default = '')
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $v = $_GET[$key];
    return is_array($v) ? $v : trim((string) $v);
}

function client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    // 站点在 CDN / 反向代理后面时 REMOTE_ADDR 是代理地址，所有访客共用一个 IP，
    // 登录失败限速会把全站用户一起锁住。只有后台显式开启 trust_proxy 才采信
    // X-Forwarded-For —— 否则任何人都能伪造这个头绕过限速。
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        && function_exists('setting') && setting('trust_proxy', '0') === '1') {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $first = trim($parts[0]);
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return substr($ip, 0, 45);
}

/**
 * 按需启动会话。
 * 前台静态页面不需要 session，若每次请求都启动，等于每个访客都在服务器上
 * 留一个 session 文件。后台、登录、CSRF、flash 这些真正需要的地方再调用。
 */
function cms_session_start()
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ));
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_name('CICICMSSID');
    session_start();
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function json_out($data)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============ 站点配置（cms_config 键值表） ============ */

function settings_all($refresh = false)
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = array();
        try {
            foreach (all('SELECT k, v FROM {p}config') as $r) {
                $cache[$r['k']] = $r['v'];
            }
        } catch (Exception $ex) {
            $cache = array();
        }
    }
    return $cache;
}

function setting($key, $default = '')
{
    $all = settings_all();
    return (isset($all[$key]) && $all[$key] !== '') ? $all[$key] : $default;
}

function set_setting($key, $value)
{
    q('INSERT INTO {p}config (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', array($key, (string) $value));
    settings_all(true);
}

/* ============ URL ============ */

function detect_site_url()
{
    if (PHP_SAPI === 'cli') {
        return 'http://localhost';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/index.php';
    $dir    = rtrim(dirname($script), '/');
    $dir    = preg_replace('#/(admin|install)$#', '', $dir);
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function site_url($path = '')
{
    $base = rtrim((string) setting('site_url', ''), '/');
    if ($base === '') {
        $base = rtrim(detect_site_url(), '/');
    }
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

/** 是否处于静态生成模式（生成时链接输出为 .html，动态预览时输出为查询串） */
function cms_static_mode()
{
    return !empty($GLOBALS['cms_static_mode']);
}

function u_home($page = 1)
{
    $page = max(1, (int) $page);
    if (!cms_static_mode()) {
        return site_url('index.php' . ($page > 1 ? '?page=' . $page : ''));
    }
    return $page === 1 ? site_url('index.html') : site_url('page/' . $page . '/index.html');
}

/**
 * 顶级目录保留字：分类目录直接落在站点根，不能和系统目录/固定页重名
 */
function cms_reserved_dirs()
{
    return array(
        'index', 'page', 'tag', 'tags', 'post', 'cat', 'archive', 'links', '404',
        'admin', 'includes', 'install', 'content', 'data', 'uploads', 'themes',
        'api', 'config', 'sitemap', 'rss',
    );
}

/**
 * 系统目录：程序自身代码所在位置，运行时任何写入/删除都不允许落进来。
 *
 * 与 cms_reserved_dirs() 不是一回事：那个是「栏目别名不能重名」的黑名单，
 * 里面的 page / tag 等仍然是系统要写静态文件的输出目录；这里列的是纯程序区，
 * 部署时可以把它们整体设成只读，静态生成、清理都不会因此失败。
 */
function cms_system_dirs()
{
    return array('admin', 'includes', 'install', 'content', 'docs');
}

/** 相对路径是否落在系统目录内（静态文件写入/删除前的最后一道校验） */
function cms_is_system_rel($rel)
{
    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
    // 空路径与带 .. 的路径一律当作不可写，函数自己兜住，不依赖调用方先校验
    if ($rel === '' || strpos($rel, '..') !== false) {
        return true;
    }
    $top = strtolower((string) strtok($rel, '/'));
    return in_array($top, cms_system_dirs(), true);
}

/** 分类目录名：非法或为空时兜底到 uncategorized，保证目录名安全 */
function cat_dir_of($cat)
{
    $dir = is_array($cat) ? (string) $cat['dirname'] : (string) $cat;
    if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $dir)) {
        return $dir;
    }
    return 'uncategorized';
}

/**
 * 文章静态相对路径：<分类目录>/<文章ID>.html
 *
 * 用 ID 不用 slug：改标题、改 slug 都不会让地址失效，只有换分类才会变地址。
 * 传数组最快（列表页已经 join 出 cat_dir）；只传 slug 时补一次查询。
 */
function post_static_rel($post)
{
    $id    = 0;
    $dir   = '';
    $slug  = '';

    if (is_array($post)) {
        $id   = isset($post['id']) ? (int) $post['id'] : 0;
        $dir  = isset($post['cat_dir']) ? (string) $post['cat_dir'] : '';
        $slug = isset($post['slug']) ? (string) $post['slug'] : '';
    } else {
        $slug = (string) $post;
    }

    // 只有 slug：查一次拿 id 和分类目录
    if ($id <= 0 && $slug !== '') {
        $row = one('SELECT a.id, c.dirname AS cat_dir
                    FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                    WHERE a.slug = ? LIMIT 1', array($slug));
        if (!$row) {
            return '';
        }
        $id  = (int) $row['id'];
        $dir = (string) $row['cat_dir'];
    }

    // 有 id 没分类目录（只传了 id 的场合）：优先用分类缓存，避免每行一次查询
    if ($id > 0 && $dir === '') {
        $catId = (isset($post['cat_id']) && is_array($post)) ? (int) $post['cat_id'] : 0;
        if ($catId > 0 && function_exists('fe_cat_by_id')) {
            $c = fe_cat_by_id($catId);
            if ($c) {
                $dir = (string) $c['dirname'];
            }
        }
        if ($dir === '') {
            $dir = (string) val('SELECT c.dirname FROM {p}category c
                                 JOIN {p}article a ON a.cat_id = c.id WHERE a.id = ?', array($id));
        }
    }

    return $id > 0 ? cat_dir_of($dir) . '/' . $id . '.html' : '';
}

/** 文章静态地址（无论当前是否在生成模式，始终给静态地址，301 用） */
function post_static_url($post)
{
    $rel = post_static_rel($post);
    return $rel !== '' ? site_url($rel) : '';
}

function u_post($post)
{
    $slug = is_array($post) ? (string) $post['slug'] : (string) $post;
    if (!cms_static_mode()) {
        return site_url('index.php?p=' . rawurlencode($slug));
    }
    $rel = post_static_rel($post);
    return $rel !== '' ? site_url($rel) : site_url('index.php?p=' . rawurlencode($slug));
}

/** 分类静态地址：<目录>/index.html、<目录>/page/N/index.html */
function cat_static_url($cat, $page = 1)
{
    $dir  = cat_dir_of($cat);
    $page = max(1, (int) $page);
    return site_url($page === 1 ? $dir . '/index.html' : $dir . '/page/' . $page . '/index.html');
}

function u_cat($cat, $page = 1)
{
    $dir  = cat_dir_of($cat);
    $page = max(1, (int) $page);
    if (!cms_static_mode()) {
        return site_url('index.php?cat=' . rawurlencode($dir) . ($page > 1 ? '&page=' . $page : ''));
    }
    return cat_static_url($cat, $page);
}

function u_page($page)
{
    $slug = is_array($page) ? $page['slug'] : (string) $page;
    if (!cms_static_mode()) {
        return site_url('index.php?single=' . rawurlencode($slug));
    }
    return site_url(rawurlencode($slug) . '.html');
}

function tag_slug($name)
{
    return substr(md5('cicicms-tag:' . $name), 0, 10);
}

function u_tag($name, $page = 1)
{
    if (!cms_static_mode()) {
        return site_url('index.php?tag=' . rawurlencode($name) . ($page > 1 ? '&page=' . (int) $page : ''));
    }
    return site_url('tag/' . tag_slug($name) . '.html');
}

function u_search($kw = '')
{
    return site_url('index.php?s=' . urlencode((string) $kw));
}

/* ============ 主题与模板 ============ */

function theme_name()
{
    $t = (string) setting('theme', 'default');
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $t)) {
        return 'default';
    }
    // 主题目录被删（比如旧版 cici 已合并进 default）时回落到默认主题，
    // 否则整站会变成"模板文件缺失"的 500。
    if (!is_dir(CMS_THEMES . '/' . $t)) {
        return is_dir(CMS_THEMES . '/default') ? 'default' : $t;
    }
    return $t;
}

function theme_path($file = '')
{
    return CMS_THEMES . '/' . theme_name() . '/' . ltrim((string) $file, '/');
}

function theme_url($file = '')
{
    return site_url(CMS_CONTENT_REL . '/themes/' . theme_name() . '/' . ltrim((string) $file, '/'));
}

/* ============ 上传附件 ============ */

/**
 * 附件在磁盘上的绝对路径，$rel 是相对 uploads 目录的部分。
 */
function upload_path($rel = '')
{
    return CMS_UPLOADS . '/' . ltrim((string) $rel, '/');
}

/**
 * 把各种写法的附件路径规范成「相对 uploads 目录」的形式。
 * 兼容两种历史数据：content/uploads/2026/11/a.jpg 和 uploads/2026/11/a.jpg。
 * 路径非法（含 .. 或不在上传目录内）时返回空字符串。
 */
function upload_rel($path)
{
    $path = str_replace('\\', '/', ltrim((string) $path, '/'));
    if ($path === '') {
        return '';
    }

    // 三种历史格式都要认：
    //   content/uploads/2026/11/a.jpg（旧数据）
    //   uploads/2026/11/a.jpg（更旧）
    //   2026/11/a.jpg（上传函数产出的标准相对路径）
    $prefix = CMS_CONTENT_REL . '/uploads/';
    if (strpos($path, $prefix) === 0) {
        $path = substr($path, strlen($prefix));
    } elseif (strpos($path, 'uploads/') === 0) {
        $path = substr($path, 8);
    }

    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    return $path;
}

/**
 * 附件对外 URL。内部路径统一走这里，外链原样返回。
 */
function upload_url($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $path) || strpos($path, 'data:') === 0) {
        return $path;
    }

    $rel = upload_rel($path);
    if ($rel !== '') {
        return site_url(CMS_CONTENT_REL . '/uploads/' . $rel);
    }
    return $path;
}

/**
 * 渲染一个 PHP 模板文件，$vars 直接成为模板内的变量。
 */
function render_php_file($file, $vars = array())
{
    extract((array) $vars, EXTR_SKIP);
    ob_start();
    include $file;
    return ob_get_clean();
}

/**
 * 渲染主题模板。
 * 主题目录下存在同名 .html 时走 {cici:} 标签引擎，否则走 PHP 模板。
 * 两种模板可以同时存在、互相 include。
 */
function render($tpl, $vars = array())
{
    $html = theme_path($tpl . '.html');
    if (is_file($html)) {
        return cms_tag_render_file($html, (array) $vars);
    }

    $file = theme_path($tpl . '.php');
    if (!is_file($file)) {
        $msg = '模板文件缺失：' . $tpl . '.html 或 ' . $tpl . '.php';
        // 生成静态时不能 exit：exit 会跳过 build_tick 的 finally，任务状态不落盘，
        // 于是这一轮永远停在同一个任务上，用户看到的是「生成中断」且再也生成不完。
        // 抛出异常交给 build_tick 的 catch(Throwable) 处理：记致命标记、跳过该任务、本轮收尾。
        if (!empty($GLOBALS['cms_static_mode'])) {
            throw new RuntimeException($msg);
        }
        http_response_code(500);
        exit(e($msg));
    }
    return render_php_file($file, $vars);
}

/* ============ 提示消息 ============ */

function flash($msg, $type = 'ok')
{
    cms_session_start();
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = array();
    }
    $_SESSION['flash'][] = array('msg' => $msg, 'type' => $type);
}

function flashes()
{
    if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        return array();
    }
    $list = isset($_SESSION['flash']) ? $_SESSION['flash'] : array();
    unset($_SESSION['flash']);
    return is_array($list) ? $list : array();
}

/* ============ CSRF ============ */

function csrf_token()
{
    cms_session_start();
    if (empty($_SESSION['_csrf'])) {
        if (function_exists('random_bytes')) {
            $t = bin2hex(random_bytes(16));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $t = bin2hex(openssl_random_pseudo_bytes(16));
        } else {
            $t = md5(uniqid((string) mt_rand(), true));
        }
        $_SESSION['_csrf'] = $t;
    }
    return $_SESSION['_csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify()
{
    cms_session_start();
    $t = isset($_POST['_token']) ? (string) $_POST['_token'] : '';
    if ($t === '' || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $t)) {
        http_response_code(403);
        exit('安全校验失败：会话已过期，请返回上一页刷新后重试。');
    }
}

/* ============ 文本处理 ============ */

function str_limit($s, $len = 150, $suffix = '…')
{
    $s = (string) $s;
    if (function_exists('mb_strlen')) {
        if (mb_strlen($s, 'UTF-8') <= $len) {
            return $s;
        }
        return mb_substr($s, 0, $len, 'UTF-8') . $suffix;
    }
    if (strlen($s) <= $len) {
        return $s;
    }
    return substr($s, 0, $len) . $suffix;
}

function make_excerpt($html, $len = 150)
{
    // 不能直接 strip_tags：正文里讲代码时 "<?php" 会被当成标签吞掉，
    // 代码块内容也会混进摘要。统一交给 content_plain 处理。
    $text = function_exists('content_plain')
        ? content_plain((string) $html)
        : preg_replace('/\s+/u', ' ', strip_tags((string) $html));
    return str_limit(trim($text), $len);
}

/** 生成 ASCII slug；中文标题会得到空串，保存后回退为文章 ID */
function make_slug($str)
{
    $str = strtolower(trim((string) $str));
    if ($str === '') {
        return '';
    }
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim($str, '-');
    return substr($str, 0, 60);
}

function unique_slug($table, $slug, $id = 0)
{
    $table = in_array($table, array('article', 'page'), true) ? $table : 'article';
    if ($slug === '') {
        return '';
    }
    $base = $slug;
    $i    = 2;
    while ($i < 1000) {
        $hit = val('SELECT id FROM {p}' . $table . ' WHERE slug = ? AND id <> ?', array($slug, (int) $id));
        if (!$hit) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
    return $base . '-' . time();
}

/** 过滤正文中的脚本与事件属性（防存储型 XSS） */
function clean_html($html)
{
    $html = (string) $html;

    // 1) 危险标签：连内容一起删（这些标签内部可以执行脚本或加载外部资源）
    $kill = 'script|iframe|frame|frameset|object|embed|applet|svg|math|template|noscript';
    $html = preg_replace('#<(' . $kill . ')\b[^>]*>.*?</\1\s*>#is', '', $html);
    // 没有闭合的残留标签也一并清掉
    $html = preg_replace('#</?(' . $kill . ')\b[^>]*>#is', '', $html);
    // 只去标签、保留内容（正文里出现这些标签只会破坏页面结构）
    $html = preg_replace('#</?(form|base|meta|link|body|html|head)\b[^>]*>#is', '', $html);
    // style 标签保留（正文里可能自定义样式），只清掉其中能执行代码的写法
    $html = preg_replace('#@import[^;]*;?#i', '', $html);
    $html = preg_replace('/expression\s*\(/i', '', $html);
    $html = preg_replace('/behaviou?r\s*:/i', '', $html);

    // 2) 事件属性：onerror=、onload=、onclick=…（带双引号 / 单引号 / 不带引号三种写法）
    $html = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html);
    $html = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $html);
    $html = preg_replace('#\son[a-z]+\s*=\s*[^\s"\'>]+#i', '', $html);

    // 3) 危险协议：逐个检查链接类属性的值
    //
    //    这里必须无条件执行，不能像早期版本那样先用「全文是否出现过 javascript:」
    //    做前置判断：浏览器会把 &Tab; &NewLine; &colon; 这类 HTML5 命名实体
    //    还原成控制字符和冒号，而全文正则匹配不到拆分后的写法，前置判断一为假，
    //    整段防护就被跳过（java&#09;script:alert(1) 可直接落地）。
    $html = preg_replace_callback(
        '#\b(href|src|action|formaction|poster|background|xlink:href|data)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>\'"]+))#i',
        function ($m) {
            $val = '';
            if (isset($m[3]) && $m[3] !== '') {
                $val = $m[3];
            } elseif (isset($m[4]) && $m[4] !== '') {
                $val = $m[4];
            } elseif (isset($m[5])) {
                $val = $m[5];
            }
            // 与浏览器一致的归一化：解 HTML5 命名实体，再去掉控制字符与所有空白
            $dec = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $dec = preg_replace('/[\x00-\x20\x7f]+/', '', $dec);
            if (preg_match('/^(javascript|vbscript|livescript):/i', $dec)
                || preg_match('/^data:(text\/html|image\/svg)/i', $dec)) {
                return $m[1] . '="#"';
            }
            return $m[0];
        },
        $html
    );

    // 属性之外的残留写法（注释、CSS、非法属性位置）再清一遍
    $html = preg_replace('/j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', '', $html);
    $html = preg_replace('/v\s*b\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', '', $html);
    $html = preg_replace('/l\s*i\s*v\s*e\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', '', $html);
    $html = preg_replace('/data\s*:\s*text\/html/i', '', $html);
    $html = preg_replace('/data\s*:\s*image\/svg/i', '', $html);

    // 4) CSS 里的表达式与老 IE 行为
    $html = preg_replace('/expression\s*\(/i', '', $html);
    $html = preg_replace('/behavior\s*:/i', '', $html);
    $html = preg_replace('#@import#i', '', $html);

    return $html;
}

function normalize_tags($str)
{
    $parts = preg_split('/[,，;；\s]+/u', (string) $str);
    $out   = array();
    foreach ((array) $parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $p = function_exists('mb_substr') ? mb_substr($p, 0, 20, 'UTF-8') : substr($p, 0, 40);
        if (!in_array($p, $out, true)) {
            $out[] = $p;
        }
        if (count($out) >= 8) {
            break;
        }
    }
    return implode(',', $out);
}

function format_date($ts, $fmt = 'Y-m-d')
{
    return $ts ? date($fmt, (int) $ts) : '';
}

function human_date($ts)
{
    $d = time() - (int) $ts;
    if ($d < 60) {
        return '刚刚';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' 分钟前';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' 小时前';
    }
    if ($d < 2592000) {
        return floor($d / 86400) . ' 天前';
    }
    return date('Y-m-d', (int) $ts);
}

function format_bytes($n)
{
    $n = (float) $n;
    $u = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    while ($n >= 1024 && $i < 3) {
        $n = $n / 1024;
        $i++;
    }
    return round($n, 1) . ' ' . $u[$i];
}

/* ============ 文件写入 ============ */

function ensure_dir($dir)
{
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0755, true);
}

/** 原子写入：先写临时文件再 rename，避免并发写坏文件 */
function write_file_atomic($path, $content)
{
    $dir = dirname($path);
    if (!ensure_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp' . substr(md5(uniqid('', true)), 0, 6);
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        // Windows 主机上目标文件已存在时 rename 可能失败，删除后重试一次
        if (is_file($path)) {
            @unlink($path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
    }
    @chmod($path, 0644);
    return true;
}

function log_msg($msg)
{
    $dir = CMS_DATA . '/logs';
    if (!ensure_dir($dir)) {
        return;
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(array("\r", "\n"), ' ', (string) $msg) . "\n";
    @file_put_contents($dir . '/' . date('Ym') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/* ============ 后台辅助 ============ */

function admin_url($p = 'dashboard', $extra = array())
{
    $u = 'index.php?p=' . urlencode($p);
    foreach ((array) $extra as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $u .= '&' . urlencode($k) . '=' . urlencode((string) $v);
    }
    return $u;
}

function is_ajax()
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/** 只接受字符串参数（防止数组注入） */
function post_str($key, $default = '')
{
    $v = post($key, $default);
    return is_string($v) ? $v : $default;
}

function get_str($key, $default = '')
{
    $v = get($key, $default);
    return is_string($v) ? $v : $default;
}

function get_int($key, $default = 0)
{
    return (int) get_str($key, (string) $default);
}

/** 后台分页条 HTML */
function admin_pager_html($page, $pages, $urlFn, $total = 0)
{
    $page  = max(1, (int) $page);
    $pages = max(1, (int) $pages);
    if ($pages <= 1) {
        return '';
    }
    $h = '<div class="pager">';
    $h .= $page > 1
        ? '<a href="' . e($urlFn($page - 1)) . '">上一页</a>'
        : '<span class="dis">上一页</span>';
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1) {
        $h .= '<a href="' . e($urlFn(1)) . '">1</a>';
        if ($start > 2) {
            $h .= '<span class="dis">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $h .= $i === $page
            ? '<span class="cur">' . $i . '</span>'
            : '<a href="' . e($urlFn($i)) . '">' . $i . '</a>';
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $h .= '<span class="dis">…</span>';
        }
        $h .= '<a href="' . e($urlFn($pages)) . '">' . $pages . '</a>';
    }
    $h .= $page < $pages
        ? '<a href="' . e($urlFn($page + 1)) . '">下一页</a>'
        : '<span class="dis">下一页</span>';
    if ($total > 0) {
        $h .= '<span class="total">共 ' . (int) $total . ' 条</span>';
    }
    return $h . '</div>';
}

/* ============ 权限模型自检 ============ */

/**
 * 目录权限自检：代码区只执行不写入，内容区可写入不执行
 *
 * 这是部署层面的约束，代码没法自己强制（PHP 改不了宿主权限），
 * 所以后台把它列出来，让"不合规"看得见。
 *
 * @return array 每项：label 名称 / path 路径 / want 期望 / actual 实际 / ok 是否合规 / note 说明
 */
function cms_permission_audit()
{
    $rows = array();

    // 期望：代码区不可写（升级靠覆盖，运行期不该产生任何新文件）
    $readonly = array(
        'includes/' => array(CMS_INC, '系统代码，升级时整体覆盖'),
        'admin/'    => array(CMS_ROOT . '/admin', '后台程序，升级时整体覆盖'),
    );
    foreach ($readonly as $label => $it) {
        $w = is_writable($it[0]);
        // 「只读」不等于「只留执行位」：includes/tags 下的标签靠 glob 扫描，
        // 目录少了 r（列目录）注册表就是空的，所有 {cici:} 标签会集体失效。
        $listable = is_dir($it[0]) ? is_array(@scandir($it[0])) : is_readable($it[0]);
        if ($w) {
            $actual = '可写';
        } elseif (!$listable) {
            $actual = '不可列目录';
        } else {
            $actual = '只读';
        }
        $rows[] = array(
            'label'  => $label,
            'want'   => '只读',
            'actual' => $actual,
            'ok'     => !$w && $listable,
            'note'   => $it[1],
        );
    }

    // 安装目录：装完应当删掉
    $insExists = is_dir(CMS_ROOT . '/install');
    $rows[] = array(
        'label'  => 'install/',
        'want'   => '装完删除',
        'actual' => $insExists ? '仍存在' : '已删除',
        'ok'     => !$insExists,
        'note'   => '安装向导，装完不删等于把重装入口留给别人',
    );

    // 期望：内容区可写
    $writable = array(
        'content/'         => CMS_CONTENT,
        'content/data/'    => CMS_DATA,
        'content/uploads/' => CMS_UPLOADS,
    );
    foreach ($writable as $label => $path) {
        $w = is_writable($path);
        $rows[] = array(
            'label'  => $label,
            'want'   => '可写',
            'actual' => $w ? '可写' : '不可写',
            'ok'     => $w,
            'note'   => '数据与附件必须能写，否则无法上传 / 生成',
        );
    }

    // 配置文件：装完建议改成只读
    $cfg = CMS_ROOT . '/config.php';
    $rows[] = array(
        'label'  => 'config.php',
        'want'   => '只读',
        'actual' => is_writable($cfg) ? '可写' : '只读',
        'ok'     => !is_writable($cfg),
        'note'   => '数据库账号密码，装完建议 chmod 444',
    );

    return $rows;
}
