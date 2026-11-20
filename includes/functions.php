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
function CMS_session_start()
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

/* ============ 站点配置（CMS_config 键值表） ============ */

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

/** 是否处于静态生成模式（生成时模板缺失要抛异常而不是直接 exit，见 render()） */
function CMS_static_mode()
{
    return !empty($GLOBALS['CMS_static_mode']);
}

function u_home($page = 1)
{
    $page = max(1, (int) $page);
    return $page === 1 ? site_url('index.html') : site_url('page/' . $page . '/index.html');
}

/**
 * 顶级目录保留字：分类目录直接落在站点根，不能和系统目录/固定页重名
 */
function CMS_reserved_dirs()
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
 * 与 CMS_reserved_dirs() 不是一回事：那个是「栏目别名不能重名」的黑名单，
 * 里面的 page / tag 等仍然是系统要写静态文件的输出目录；这里列的是纯程序区，
 * 部署时可以把它们整体设成只读，静态生成、清理都不会因此失败。
 */
function CMS_system_dirs()
{
    return array('admin', 'includes', 'install', 'content', 'docs');
}

/** 相对路径是否落在系统目录内（静态文件写入/删除前的最后一道校验） */
function CMS_is_system_rel($rel)
{
    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
    // 空路径与带 .. 的路径一律当作不可写，函数自己兜住，不依赖调用方先校验
    if ($rel === '' || strpos($rel, '..') !== false) {
        return true;
    }
    $top = strtolower((string) strtok($rel, '/'));
    return in_array($top, CMS_system_dirs(), true);
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

function u_post($post)
{
    $rel = post_static_rel($post);
    return $rel !== '' ? site_url($rel) : '#';
}

/**
 * 后台「打开静态页」用的地址
 *
 * 早期版本在静态页不存在时现场 build_incremental()：管理员已登录时，
 * 任何指向后台列表页的 GET（比如攻击页里一个空 <img>）都会让服务器反复写盘。
 * 现在只判断文件在不在，不构建 —— 生成由保存、发布、生成页那条明确链路负责。
 * 没有静态文件时返回空串，调用方据此隐藏「打开静态页」。
 */
function u_post_preview($post)
{
    if (is_array($post) && isset($post['status']) && (int) $post['status'] !== 1) {
        return '';
    }
    $rel = post_static_rel($post);
    if ($rel === '' || !is_file(CMS_ROOT . '/' . $rel)) {
        return '';
    }
    return site_url($rel);
}

/** 单页的静态地址：同样只判断，不构建 */
function u_page_preview($page)
{
    if (is_array($page) && isset($page['status']) && (int) $page['status'] !== 1) {
        return '';
    }
    $slug = is_array($page) ? (string) $page['slug'] : (string) $page;
    if ($slug === '') {
        return '';
    }
    $rel = rawurlencode($slug) . '.html';
    if (!is_file(CMS_ROOT . '/' . $rel)) {
        return '';
    }
    return site_url($rel);
}

/** 后台动态预览地址：不落盘，草稿也能看，权限靠 preview.php 里的 require_login */
function u_admin_preview($type, $id)
{
    $id = (int) $id;
    if ($id <= 0) {
        return '';
    }
    return site_url('admin/preview.php?type=' . urlencode((string) $type) . '&id=' . $id);
}

/**
 * 列表页的「预览」链接：静态页在就用静态页，不在就退回后台动态预览
 *
 * 两者对读者都是一次真实的页面渲染，区别只是后者的 HTML 是现场拼的、带 noindex。
 */
function u_post_preview_link($post)
{
    if (is_array($post) && isset($post['status']) && (int) $post['status'] !== 1) {
        return '';
    }
    $static = u_post_preview($post);
    if ($static !== '') {
        return $static;
    }
    $id = is_array($post) && isset($post['id']) ? (int) $post['id'] : 0;
    return u_admin_preview('article', $id);
}

function u_page_preview_link($page)
{
    if (is_array($page) && isset($page['status']) && (int) $page['status'] !== 1) {
        return '';
    }
    $static = u_page_preview($page);
    if ($static !== '') {
        return $static;
    }
    $id = is_array($page) && isset($page['id']) ? (int) $page['id'] : 0;
    return u_admin_preview('page', $id);
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
    return cat_static_url($cat, $page);
}

function u_page($page)
{
    $slug = is_array($page) ? $page['slug'] : (string) $page;
    return site_url(rawurlencode($slug) . '.html');
}

function tag_slug($name)
{
    return substr(md5('cicicms-tag:' . $name), 0, 10);
}

function u_tag($name, $page = 1)
{
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
 * 判断一个地址能不能由服务器去抓
 *
 * 「从网扯一张图回来」有两个入口：媒体信息里的「按地址上传」和 Hexo 导入时
 * 把外链图片本地化。两处规则必须一致，所以判定收在这里。
 *
 * 只放行 http / https，且解析出的 IP 不能是内网、回环、链路本地或保留地址。
 * 挡不住 DNS 重绑定（解析后到真正连接之间域名可能变），但足以挡住
 * 「填个 127.0.0.1 / 169.254.169.254 探内网」这类误用与顺手利用。
 *
 * @param string $url
 * @return string 不通过时返回原因，通过返回空串
 */
function CMS_url_public_check($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '地址为空';
    }
    if (!preg_match('#^https?://#i', $url)) {
        return '只支持 http / https 地址';
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '') {
        return '地址格式不正确';
    }

    // 形如 [::1] 的 IPv6 字面量要去掉方括号
    $host = trim($host, '[]');

    $ip = filter_var($host, FILTER_VALIDATE_IP)
        ? $host
        : (string) gethostbyname($host);

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '无法解析该域名';
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '不允许抓取内网或保留地址';
    }
    return '';
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
 * 从一段正文里抽出「本站在用的上传文件」的相对路径
 *
 * 只认 uploads/年/月日/文件名 这种由上传器生成的形状，避免把外链图片也算进来。
 */
function attachment_refs_in($text)
{
    $out = array();
    $re  = '#uploads/([0-9]{4}/[0-9A-Za-z/._-]+?\.(?:jpe?g|png|gif|webp|bmp|avif|svg|ico|pdf|zip|rar|7z|txt|docx?|xlsx?|pptx?|mp3|mp4))#i';
    if (!preg_match_all($re, (string) $text, $m)) {
        return $out;
    }
    foreach ($m[1] as $rel) {
        if (strpos($rel, '..') === false) {
            $out[$rel] = true;
        }
    }
    return array_keys($out);
}

/**
 * 把正文引用到的图片登记到 attachment.content_id
 *
 * 只是「哪篇文章在用这张图」的快速索引，媒体信息里能反查；
 * 清理未引用附件**不依赖**它（清理走全站文件名检索，更保守）。
 * 所以这里允许失败、允许重复执行，绝不因为绑定失败影响保存。
 */
function attachment_bind_content($articleId, $text)
{
    $articleId = (int) $articleId;
    if ($articleId <= 0) {
        return;
    }
    try {
        q('UPDATE {p}attachment SET content_id = 0 WHERE content_id = ?', array($articleId));
        $rels = attachment_refs_in($text);
        if (!$rels) {
            return;
        }
        $paths = array();
        foreach ($rels as $rel) {
            $paths[] = CMS_CONTENT_REL . '/uploads/' . $rel;
            $paths[] = 'uploads/' . $rel;
            $paths[] = $rel;
        }
        $in = implode(',', array_fill(0, count($paths), '?'));
        q(
            'UPDATE {p}attachment SET content_id = ? WHERE path IN (' . $in . ')',
            array_merge(array($articleId), $paths)
        );
    } catch (Throwable $ex) {
        log_msg('attachment bind failed: ' . $ex->getMessage());
    }
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
        return CMS_tag_render_file($html, (array) $vars);
    }

    $file = theme_path($tpl . '.php');
    if (!is_file($file)) {
        $msg = '模板文件缺失：' . $tpl . '.html 或 ' . $tpl . '.php';
        // 生成静态时不能 exit：exit 会跳过 build_tick 的 finally，任务状态不落盘，
        // 于是这一轮永远停在同一个任务上，用户看到的是「生成中断」且再也生成不完。
        // 抛出异常交给 build_tick 的 catch(Throwable) 处理：记致命标记、跳过该任务、本轮收尾。
        if (!empty($GLOBALS['CMS_static_mode'])) {
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
    CMS_session_start();
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
    CMS_session_start();
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
    CMS_session_start();
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

/**
 * 生成全局唯一的 slug。
 *
 * 修复（跨表 slug 冲突）：文章与单页各自有 UNIQUE(slug)，但两表是分开的，
 * 旧逻辑只查当前表，导致文章与单页可以占用同一个 slug。而前台按 slug 反查
 * 文章、模板里 {cici:} 标签也可能按 slug 取内容，重名会带来歧义。
 * 这里改为同时检查 article 与 page 两张表，保证全站 slug 唯一。
 *
 * 注意：本函数不改变入参为空串时的既有行为——空 slug 由调用方回退为
 * tmp 随机串或文章 ID（见后台保存逻辑），此处原样返回。
 */
function unique_slug($table, $slug, $id = 0)
{
    $table = in_array($table, array('article', 'page'), true) ? $table : 'article';
    if ($slug === '') {
        return '';
    }
    $id = (int) $id;
    $base = $slug;
    $i    = 2;
    while ($i < 1000) {
        // 本表内查重（排除自身）
        $hit = val('SELECT id FROM {p}' . $table . ' WHERE slug = ? AND id <> ?', array($slug, $id));
        // 跨表查重：另一侧的同名 slug 也算冲突（另一侧不涉及自身 id）
        if (!$hit) {
            $other = $table === 'article' ? 'page' : 'article';
            $hit   = val('SELECT id FROM {p}' . $other . ' WHERE slug = ?', array($slug));
        }
        if (!$hit) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
    return $base . '-' . time();
}

/**
 * 数据库健康巡检（只读）：报告四类可能的数据完整性问题。
 * 供运维/后台诊断使用，不修改任何数据。
 *
 * 返回 array：
 *   - orphan_article_cat : 文章指向不存在的栏目（cat_id 无对应 category）
 *   - orphan_attach      : 附件指向不存在的内容（content_id 非 0 但无对应 article）
 *   - dup_slug_cross     : 文章与单页之间重复的 slug（跨表冲突）
 *   - empty_slug         : slug 为空字符串的记录（正常流程不会产生，异常落库时会出现）
 */
function CMS_db_health_check()
{
    $result = array(
        'orphan_article_cat' => array(),
        'orphan_attach'      => array(),
        'dup_slug_cross'     => array(),
        'empty_slug'         => array(),
    );
    try {
        // 1. 文章的 cat_id 悬空（category 表无此 id，且 cat_id 非 0）
        $result['orphan_article_cat'] = all(
            'SELECT a.id, a.title, a.cat_id FROM {p}article a
             LEFT JOIN {p}category c ON c.id = a.cat_id
             WHERE a.cat_id <> 0 AND c.id IS NULL'
        );
    } catch (Throwable $ex) {
    }
    try {
        // 2. 附件的 content_id 悬空（非 0 但找不到对应文章）
        $result['orphan_attach'] = all(
            'SELECT at.id, at.name, at.content_id FROM {p}attachment at
             LEFT JOIN {p}article a ON a.id = at.content_id
             WHERE at.content_id <> 0 AND a.id IS NULL'
        );
    } catch (Throwable $ex) {
    }
    try {
        // 3. 跨表重复 slug（文章与单页用了同一个 slug）
        $result['dup_slug_cross'] = all(
            'SELECT a.slug, a.id AS article_id, p.id AS page_id
             FROM {p}article a JOIN {p}page p ON p.slug = a.slug'
        );
    } catch (Throwable $ex) {
    }
    try {
        // 4. 空 slug（应为空数组；非空说明有绕过程序直接写库的脏数据）
        $r1 = all("SELECT id, title FROM {p}article WHERE slug = ''");
        $r2 = all("SELECT id, title FROM {p}page WHERE slug = ''");
        $result['empty_slug'] = array_merge($r1, $r2);
    } catch (Throwable $ex) {
    }
    return $result;
}

/* ============ 正文净化 ============ */

/**
 * 正文允许的标签与属性
 *
 * 之前是黑名单（列一堆要删的东西），黑名单天生列举不完：每出一个新属性、
 * 一种新协议就要补一条，漏一次就是存储型 XSS。这里反过来写：只承认
 * Markdown 正文可能产出的标签，其余去标签留文字；属性同理。
 *
 * 白名单比渲染器能力略宽一点（视频、任务清单、代码块复制按钮都在里面），
 * 目的是「净化之后不改变正常文章的渲染结果」，不是尽可能多删。
 */
function CMS_html_tags()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $head = array('id');
    $map  = array(
        'a'          => array('href', 'title', 'target', 'rel', 'download', 'hreflang', 'type'),
        'img'        => array('src', 'alt', 'title', 'width', 'height', 'loading', 'decoding', 'srcset', 'sizes'),
        'p'          => array(),
        'br'         => array(),
        'hr'         => array(),
        'h1'         => $head,
        'h2'         => $head,
        'h3'         => $head,
        'h4'         => $head,
        'h5'         => $head,
        'h6'         => $head,
        'strong'     => array(),
        'b'          => array(),
        'em'         => array(),
        'i'          => array(),
        'u'          => array(),
        's'          => array(),
        'del'        => array(),
        'ins'        => array(),
        'mark'       => array(),
        'small'      => array(),
        'sub'        => array(),
        'sup'        => array(),
        'cite'       => array(),
        'q'          => array('cite'),
        'code'       => array('class'),
        'pre'        => array('class'),
        'kbd'        => array(),
        'samp'       => array(),
        'var'        => array(),
        'blockquote' => array('cite'),
        'ul'         => array('start', 'reversed', 'type'),
        'ol'         => array('start', 'reversed', 'type'),
        'li'         => array('value'),
        'dl'         => array(),
        'dt'         => array(),
        'dd'         => array(),
        'table'      => array('width', 'border', 'cellpadding', 'cellspacing', 'summary', 'align'),
        'caption'    => array(),
        'colgroup'   => array('span'),
        'col'        => array('span', 'width'),
        'thead'      => array(),
        'tbody'      => array(),
        'tfoot'      => array(),
        'tr'         => array('align', 'valign'),
        'th'         => array('colspan', 'rowspan', 'scope', 'align', 'valign', 'width'),
        'td'         => array('colspan', 'rowspan', 'align', 'valign', 'width'),
        'figure'     => array(),
        'figcaption' => array(),
        'div'        => array(),
        'span'       => array(),
        'details'    => array('open'),
        'summary'    => array(),
        'video'      => array('src', 'poster', 'controls', 'loop', 'muted', 'playsinline', 'preload', 'width', 'height'),
        'audio'      => array('src', 'controls', 'loop', 'muted', 'preload'),
        'source'     => array('src', 'srcset', 'type', 'media', 'sizes'),
        'track'      => array('src', 'kind', 'srclang', 'label', 'default'),
        'picture'    => array(),
        'input'      => array('type', 'checked', 'disabled'),
        'button'     => array('type', 'aria-label'),
        'time'       => array('datetime'),
        'style'      => array('media'),
    );
    return $map;
}

/** 全局属性：任何白名单标签都能带 */
function CMS_html_global_attrs()
{
    return array('id', 'class', 'title', 'style', 'role', 'lang', 'dir');
}

/** 空元素：不需要闭合标签，也不能写成 <img></img> */
function CMS_html_void_tags()
{
    return array('br', 'hr', 'img', 'input', 'source', 'track', 'col');
}

/**
 * 与浏览器一致的属性值归一化
 *
 * 浏览器解析 URL 前会先把 HTML5 命名实体（&Tab; &NewLine; &colon; …）还原成
 * 字符、再去掉控制字符与空白，所以 java&#09;script: 是能执行的。
 * 这里先解实体、再剥控制字符，判断一定落在「浏览器真正看到的那个串」上。
 */
function CMS_html_attr_decode($val)
{
    $v = html_entity_decode((string) $val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return str_replace(array("\0"), '', $v);
}

/** URL 属性能否放行：只挡「能执行代码」与「能变成文档」的写法 */
function CMS_html_url_safe($val)
{
    $v = (string) $val;
    // 去掉所有空白与控制字符后再判协议（与浏览器解析一致）
    $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $v);
    if ($probe === '') {
        return true;
    }
    if (preg_match('#^(javascript|vbscript|livescript|mocha)\s*:#i', $probe)) {
        return false;
    }
    if (preg_match('#^data\s*:#i', $probe)) {
        // 只允许几种位图；data:text/html 与 data:image/svg+xml 都是直接的 XSS
        return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $probe);
    }
    return true;
}

/** style 属性里能执行代码的老写法 */
function CMS_html_css_clean($css)
{
    $css = preg_replace('/expression\s*\(/i', '', (string) $css);
    $css = preg_replace('/behaviou?r\s*:/i', '', $css);
    $css = preg_replace('#@import[^;]*;?#i', '', $css);
    $css = preg_replace('#url\s*\(\s*[\'"]?\s*(javascript|vbscript|data)\s*:[^)]*\)#i', 'none', $css);
    return $css;
}

/**
 * 重建一个开始标签的属性串
 *
 * @param string $tag     小写标签名
 * @param string $attrStr 原始属性部分（不含标签名）
 * @param array  $allowed 该标签允许的属性
 */
function CMS_html_attrs($tag, $attrStr, $allowed)
{
    $attrStr = (string) $attrStr;
    if (trim($attrStr) === '') {
        return '';
    }
    if (!preg_match_all(
        '#([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'`=<>]+)))?#',
        $attrStr,
        $mm,
        PREG_SET_ORDER
    )) {
        return '';
    }

    $global  = CMS_html_global_attrs();
    $urlAttr = array('href', 'src', 'srcset', 'poster', 'cite', 'data', 'background', 'formaction', 'action', 'longdesc', 'ping', 'xlink:href', 'usemap');
    $out     = array();
    $seen    = array();

    foreach ($mm as $a) {
        $name = strtolower($a[1]);
        if (isset($seen[$name])) {
            continue; // 重复属性只保留第一个，避免后面的覆盖前面的
        }
        $allowedHere = in_array($name, $global, true)
            || strpos($name, 'data-') === 0
            || strpos($name, 'aria-') === 0
            || in_array($name, $allowed, true);
        if (!$allowedHere) {
            continue;
        }

        $hasVal = isset($a[2]) && $a[2] !== '';
        if (!$hasVal) {
            // 布尔属性：controls / checked / open / disabled / reversed ……
            // 只允许该标签白名单里的，避免凭空加上 download 之类
            if (in_array($name, $allowed, true)) {
                $out[]       = $name;
                $seen[$name] = true;
            }
            continue;
        }

        $val = '';
        if (isset($a[3]) && $a[3] !== '') {
            $val = $a[3];
        } elseif (isset($a[4]) && $a[4] !== '') {
            $val = $a[4];
        } elseif (isset($a[5])) {
            $val = $a[5];
        }
        $val = CMS_html_attr_decode($val);

        if (in_array($name, $urlAttr, true)) {
            if ($name === 'srcset') {
                $ok = true;
                foreach (explode(',', $val) as $part) {
                    $bits = preg_split('/\s+/', trim($part));
                    if (!empty($bits[0]) && !CMS_html_url_safe($bits[0])) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            } elseif (!CMS_html_url_safe($val)) {
                continue;
            }
        }

        if ($name === 'style') {
            $val = CMS_html_css_clean($val);
            if (trim($val) === '') {
                continue;
            }
        }

        // 正文里的 input 只可能是任务清单的复选框，别的类型一律按 checkbox 处理
        if ($tag === 'input' && $name === 'type') {
            $val = 'checkbox';
        }
        // 复选框只能是禁用的（否则访客能改状态，看着像坏了）
        if ($tag === 'input' && $name === 'disabled') {
            $out[]       = 'disabled';
            $seen[$name] = true;
            continue;
        }

        $out[]       = $name . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
        $seen[$name] = true;
    }

    /* 新窗口打开的链接补 rel：不加 noopener 时，目标页可以用 window.opener 改本页地址 */
    if ($tag === 'a' && !empty($seen['target']) && !empty($seen['rel'])) {
        return implode(' ', $out);
    }
    if ($tag === 'a' && !empty($seen['target']) && !isset($seen['rel'])) {
        foreach ($out as $pair) {
            if (stripos($pair, 'target=') === 0 && stripos($pair, '_blank') !== false) {
                $out[] = 'rel="noopener noreferrer"';
                break;
            }
        }
    }

    return implode(' ', $out);
}

/** 过滤正文中的脚本与事件属性（防存储型 XSS） */
function clean_html($html)
{
    $html = (string) $html;
    if (trim($html) === '') {
        return '';
    }

    // 1) 危险标签：连内容一起删（内部可以执行脚本或加载外部资源）
    $kill = 'script|iframe|frame|frameset|object|embed|applet|svg|math|template|noscript|form|base|meta|link';
    $html = preg_replace('#<(' . $kill . ')\b[^>]*>.*?</\1\s*>#is', '', $html);
    // 没有闭合的残留标签也一并清掉
    $html = preg_replace('#</?(' . $kill . ')\b[^>]*>#is', '', $html);
    // 文档级标签：只去标签，内容可能还要
    $html = preg_replace('#</?(body|html|head)\b[^>]*>#is', '', $html);

    // 2) 注释整段丢弃：IE 条件注释已死，注释是夹带 <script> 的经典位置
    $html = preg_replace('#<!--.*?-->#s', '', $html);

    // 3) <style> 摘出来单独消毒：CSS 里的尖括号不该被当成标签重写
    $styles = array();
    $html   = preg_replace_callback('#<style\b[^>]*>(.*?)</style>#is', function ($m) use (&$styles) {
        $css = CMS_html_css_clean((string) $m[1]);
        $css = str_replace('</style', '&lt;/style', $css);
        $key = "\x02ST" . count($styles) . "\x03";
        $styles[$key] = '<style>' . $css . '</style>';
        return $key;
    }, $html);

    // 4) 标签重写：白名单内重建，白名单外去标签留文字
    $tags = CMS_html_tags();
    $void = CMS_html_void_tags();
    $html = preg_replace_callback(
        '#<(/)?([a-zA-Z][a-zA-Z0-9]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#',
        function ($m) use ($tags, $void) {
            $name = strtolower($m[2]);
            if (!isset($tags[$name])) {
                return ''; // 未知标签：标签去掉，里面的文字保留
            }
            if ($m[1] === '/') {
                return in_array($name, $void, true) ? '' : '</' . $name . '>';
            }
            if (in_array($name, $void, true)) {
                $attr = CMS_html_attrs($name, isset($m[3]) ? $m[3] : '', $tags[$name]);
                return '<' . $name . ($attr !== '' ? ' ' . $attr : '') . '>';
            }
            $attr = CMS_html_attrs($name, isset($m[3]) ? $m[3] : '', $tags[$name]);
            return '<' . $name . ($attr !== '' ? ' ' . $attr : '') . '>';
        },
        $html
    );

    // 5) 属性之外残留的危险写法（纯文本里的伪协议、被实体拆开的协议）
    $html = preg_replace('/j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', '', $html);
    $html = preg_replace('/v\s*b\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', '', $html);
    $html = preg_replace('/l\s*i\s*v\s*e\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', '', $html);
    $html = preg_replace('/data\s*:\s*text\/html/i', '', $html);
    $html = preg_replace('/data\s*:\s*image\/svg/i', '', $html);
    $html = preg_replace('/expression\s*\(/i', '', $html);

    // 6) 还原 style 块
    if ($styles) {
        $html = strtr($html, $styles);
    }

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
        ? '<a href="' . e($urlFn($page - 1)) . '" aria-label="上一页" title="上一页">‹</a>'
        : '<span class="dis" aria-hidden="true">‹</span>';
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
        ? '<a href="' . e($urlFn($page + 1)) . '" aria-label="下一页" title="下一页">›</a>'
        : '<span class="dis" aria-hidden="true">›</span>';
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
function CMS_permission_audit()
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
