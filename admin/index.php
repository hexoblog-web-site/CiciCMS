<?php
/**
 * CiciCMS 后台入口（单入口路由）
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
$allow = array('dashboard', 'article', 'category', 'page', 'media', 'flink', 'build', 'clean', 'data', 'theme', 'setting', 'plugin', 'update');

/*
 * 插件后台页：插件在 plugin.json 里声明 admin.file 之后，可以用 ?p=<插件ID> 直接打开，
 * 这样 admin_menu 加进左侧菜单的条目才真能点进去。核心页面优先，插件 ID 不许抢占核心路由。
 */
$PLUGIN_PAGE = null;
if (!in_array($p, $allow, true)) {
    $pluginPages = cms_plugin_admin_pages();
    if (isset($pluginPages[$p])) {
        $PLUGIN_PAGE = $pluginPages[$p];
    } else {
        $p = 'dashboard';
    }
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

/**
 * 系统更新的静默检查接口
 *
 * 后台任意页面到点后会调它一次；开关都关着时前端根本不会发请求。
 * 发现新版且「自动更新」开着，就在这个请求里直接装完。
 */
if ($p === 'update' && get_str('action') === 'tick') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = isset($_POST['_token']) ? (string) $_POST['_token'] : '';
    if (!is_post() || $token === '' || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'msg' => '安全校验失败，请刷新页面后重试'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!update_auto_check()) {
        echo json_encode(array('ok' => true, 'has_update' => false, 'applied' => false, 'msg' => ''), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $r   = update_check(false, $cerr, 20);
    $out = array(
        'ok'         => (bool) $r['ok'],
        'has_update' => !empty($r['has_update']),
        'applied'    => false,
        'current'    => CMS_VERSION,
        'version'    => '',
        'msg'        => (string) $cerr,
    );
    if (!empty($r['state']['remote']['version'])) {
        $out['version'] = (string) $r['state']['remote']['version'];
    }

    if ($r['ok'] && $r['has_update'] && update_auto_apply()) {
        $a = update_apply($aerr);
        if ($a === false) {
            $out['msg'] = $aerr;
        } else {
            $out['applied'] = true;
            $out['version'] = $a['version'];
            $out['files']   = (int) $a['files'];
            log_msg('auto update applied: ' . $a['version']);
        }
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/inc/header.php';
if ($PLUGIN_PAGE !== null) {
    /* 插件页面在后台布局内部执行，出错只影响这一页 */
    $GLOBALS['cms_plugin_current'] = $p;
    include_once $PLUGIN_PAGE['file'];
} else {
    require __DIR__ . '/pages/' . $p . '.php';
}
require __DIR__ . '/inc/footer.php';
