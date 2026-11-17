<?php
/**
 * CiciCms 后台 JSON API
 *
 * 所有请求必须已登录 + CSRF（登录接口除外）。
 * 统一返回：{ ok:true, ... } 或 { ok:false, msg:'...' }
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once CMS_INC . '/nebula-store.php';

while (ob_get_level() > 0) {
    @ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function api_out($arr, $code = 200)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function api_fail($msg, $code = 400)
{
    api_out(array('ok' => false, 'msg' => (string) $msg), $code);
}
function api_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return array();
    }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : array();
}
function api_csrf()
{
    $token = '';
    if (isset($_POST['_token'])) {
        $token = (string) $_POST['_token'];
    } else {
        $b = api_body();
        if (isset($b['_token'])) {
            $token = (string) $b['_token'];
        }
    }
    if ($token === '' || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
        api_fail('安全校验失败，请刷新页面后重试', 403);
    }
    return true;
}

$r = get_str('r', 'state');

/* 登录：仅用于原型内嵌登录框；已登录会话直接返回当前用户 */
if ($r === 'login') {
    $b = api_body();
    $u = isset($b['u']) ? (string) $b['u'] : post_str('u');
    $p = isset($b['p']) ? (string) $b['p'] : post_str('p');
    if (auth_user()) {
        $me = auth_user();
        api_out(array('ok' => true, 'user' => array(
            'name' => isset($me['username']) ? $me['username'] : 'admin', 'role' => 'admin')));
    }
    if ($u === '' || $p === '') {
        api_fail('请输入账号和密码');
    }
    api_fail('请使用后台登录页登录', 403);
}

require_login();
$me = auth_user();

switch ($r) {

    /* 拉取完整状态 */
    case 'state':
        try {
            $db = nebula_bootstrap();
            api_out(array(
                'ok'      => true,
                'db'      => $db,
                'user'    => array(
                    'name' => isset($me['username']) ? $me['username'] : 'admin',
                    'role' => 'admin',
                ),
                'version' => defined('CMS_VERSION') ? CMS_VERSION : '',
            ));
        } catch (Throwable $ex) {
            api_fail('读取数据失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 保存完整状态（增量写回） */
    case 'save':
        api_csrf();
        $b = api_body();
        if (!isset($b['db']) || !is_array($b['db'])) {
            api_fail('缺少数据包');
        }
        /* 安全阀：一次提交若让文章/单页/媒体骤降，多半是前端数据没拉全，拒绝执行 */
        try {
            $inPosts = isset($b['db']['posts']) && is_array($b['db']['posts']) ? count($b['db']['posts']) : 0;
            $inPages = isset($b['db']['pages']) && is_array($b['db']['pages']) ? count($b['db']['pages']) : 0;
            $inMedia = isset($b['db']['media']) && is_array($b['db']['media']) ? count($b['db']['media']) : 0;
            $nowPosts = (int) val('SELECT COUNT(*) FROM {p}article');
            $nowPages = (int) val('SELECT COUNT(*) FROM {p}page');
            $nowMedia = (int) val('SELECT COUNT(*) FROM {p}attachment');
            $pairs = array(array($nowPosts, $inPosts, '文章'), array($nowPages, $inPages, '单页'), array($nowMedia, $inMedia, '媒体'));
            foreach ($pairs as $pair) {
                list($old, $new, $label) = $pair;
                if ($old > 5 && $new < $old * 0.7) {
                    api_fail($label . '数量从 ' . $old . ' 骤降到 ' . $new . '，已拒绝保存。请刷新页面重试。', 409);
                }
            }
        } catch (Throwable $ex) {
            api_fail('数据校验失败：' . $ex->getMessage(), 500);
        }
        try {
            $counts = nebula_commit($b['db']);
            api_out(array('ok' => true, 'counts' => $counts, 'at' => date('Y/m/d H:i:s')));
        } catch (Throwable $ex) {
            api_fail('保存失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 上传图片：文章编辑器里拖拽/选择图片 */
    case 'upload':
        api_csrf();
        if (empty($_FILES['file'])) {
            api_fail('没有收到文件');
        }
        $err = '';
        $info = cms_upload_image($_FILES['file'], $err, true);
        if ($info === false || $err !== '') {
            api_fail($err !== '' ? $err : '上传失败');
        }
        api_out(array(
            'ok'   => true,
            'url'  => isset($info['url']) ? $info['url'] : '',
            'path' => isset($info['path']) ? $info['path'] : '',
            'name' => isset($info['name']) ? $info['name'] : '',
        ));
        break;

    /* 生成静态页：分批执行，前端轮询到 finished */
    case 'build':
        api_csrf();
        try {
            $out = build_tick(8.0);
            api_out(array('ok' => true) + $out);
        } catch (Throwable $ex) {
            api_fail('生成失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 全量生成（从零重建） */
    case 'build-all':
        api_csrf();
        try {
            $out = build_all(20.0);
            api_out(array('ok' => true) + (is_array($out) ? $out : array()));
        } catch (Throwable $ex) {
            api_fail('生成失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 退出登录 */
    case 'logout':
        api_csrf();
        auth_logout();
        api_out(array('ok' => true, 'redirect' => site_url('admin/login.php')));
        break;

    /* 当前用户信息 */
    case 'me':
        api_out(array('ok' => true, 'name' => isset($me['username']) ? $me['username'] : 'admin', 'role' => 'admin'));
        break;

    default:
        api_fail('未知的 API：' . $r, 404);
}
