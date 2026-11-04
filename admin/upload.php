<?php
/**
 * 后台图片上传接口（编辑器插图、媒体库共用）
 *
 * 只做一件事：收图 → 交给 Cms_Image_Uploader → 返回 JSON。
 * 权限与 CSRF 都必须在进入业务前过一遍：这是唯一能从后台往服务器写文件的口子。
 *
 * 请求：POST admin/upload.php
 *   _token  会话令牌（页面上的 csrf_field）
 *   mode    file（默认）| url
 *   file    mode=file 时的文件字段
 *   url     mode=url 时要抓取的图片地址
 *
 * 返回：{"ok":true,"url":"...","path":"content/uploads/...","name":"...","width":0,"height":0,"size":"123 KB"}
 *   失败：{"ok":false,"msg":"原因"}
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* 统一按 JSON 回话，任何中断都不能吐 HTML */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * 输出并结束
 */
function cms_upload_json($ok, $data = array())
{
    $body = array('ok' => (bool) $ok);
    $body += (array) $data;
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    cms_upload_json(false, array('msg' => '只接受 POST 请求'));
}

/* 会话过期时不要跳登录页，前端拿到 401 会提示刷新 */
if (!auth_check()) {
    http_response_code(401);
    cms_upload_json(false, array('msg' => '登录已过期，请刷新页面后重试'));
}

/* 上传体积超过 post_max_size 时 PHP 会丢掉整个 POST，连 token 都收不到，
   这时报「安全校验失败」会让人一头雾水，单独说清楚 */
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
    http_response_code(413);
    cms_upload_json(false, array('msg' => '文件超过服务器 post_max_size 限制（当前 ' . ini_get('post_max_size') . '），请调大后重试或换小一点的图片'));
}

$token = isset($_POST['_token']) ? (string) $_POST['_token'] : '';
if ($token === '' || empty($_SESSION['_csrf']) || !hash_equals((string) $_SESSION['_csrf'], $token)) {
    http_response_code(403);
    cms_upload_json(false, array('msg' => '安全校验失败，请刷新页面后重试'));
}

$mode = isset($_POST['mode']) ? (string) $_POST['mode'] : 'file';

if ($mode === 'url') {
    $url = isset($_POST['url']) ? trim((string) $_POST['url']) : '';
    $r   = cms_upload_remote($url, $err);
} else {
    $r = cms_upload_image(isset($_FILES['file']) ? $_FILES['file'] : null, $err);
}

if (!$r) {
    cms_upload_json(false, array('msg' => $err !== '' ? $err : '上传失败'));
}

log_msg('upload ok: ' . $r['path'] . ' (' . $mode . ')');

cms_upload_json(true, array(
    'url'    => $r['url'],
    'path'   => $r['path'],
    'name'   => $r['name'],
    'mime'   => $r['mime'],
    'width'  => (int) $r['width'],
    'height' => (int) $r['height'],
    'size'   => format_bytes($r['size']),
));
