<?php
/**
 * HtmlCms 后台入口（单入口路由）
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* 退出登录：必须 POST + CSRF，避免 GET 被 CSRF 触发 */
if (is_post() && post_str('do') === 'logout') {
    csrf_verify();
    auth_logout();
    redirect('login.php');
}

require_login();

define('CMS_ADMIN', true);

$p     = get_str('p', 'dashboard');
$allow = array('dashboard', 'article', 'category', 'page', 'media', 'flink', 'build', 'clean', 'theme', 'setting');
if (!in_array($p, $allow, true)) {
    $p = 'dashboard';
}

/**
 * 静态生成的分批执行接口：文章很多时一次请求做不完，
 * 前端每完成一批就再调一次，避免撞上 max_execution_time。
 */
if ($p === 'build' && get_str('action') === 'tick') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = isset($_POST['_token']) ? (string) $_POST['_token'] : '';
    if (!is_post() || $token === '' || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'finished' => false, 'errors' => array('安全校验失败，请刷新页面后重试')), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = build_tick(8.0);
    if (!empty($r['finished']) && !empty($r['ok'])) {
        flash('生成完成：' . (int) $r['files'] . ' 个静态文件');
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/inc/header.php';
require __DIR__ . '/pages/' . $p . '.php';
require __DIR__ . '/inc/footer.php';
