<?php
/**
 * 定时检查入口（给服务器的计划任务用，不走登录）
 *
 * 用法：
 *   计划任务里填下面这个地址即可（每 6 小时一次）：
 *   curl -s "https://你的站点/admin/cron.php?token=XXXXXXXX" >/dev/null
 * 宝塔 / 1Panel 的「访问 URL」类型任务可以直接粘贴，返回 JSON，方便看结果。
 *
 * 安全：token 存在 config 表（update_cron_token），由「系统更新」页生成并可一键重置。
 * token 不对一律返回 404，不暴露任何信息。
 *
 * 这个入口只做「检查 + 按设置自动安装 + 发通知」，与后台静默检查共用 update_auto_run()，
 * 到点限制、文件锁、自动更新开关都一样。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$real  = (string) setting('update_cron_token', '');

/* 令牌不对就当这个地址不存在：返回和普通 404 一样的响应，避免被扫出后台结构 */
if ($real === '' || $token === '' || !hash_equals($real, $token)) {
    http_response_code(404);
    echo json_encode(array('ok' => false), JSON_UNESCAPED_UNICODE);
    exit;
}

$r = update_auto_run(25, $err);

if ($r === false) {
    echo json_encode(array(
        'ok'         => true,
        'ran'        => false,
        'has_update' => update_available_cached(),
        'msg'        => update_auto_check() ? '还没到检查时间，或另一个进程正在检查' : '自动检查已关闭',
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(array(
    'ok'         => !empty($r['ok']),
    'ran'        => true,
    'has_update' => !empty($r['has_update']),
    'applied'    => !empty($r['applied']),
    'notified'   => !empty($r['notified']),
    'current'    => CMS_VERSION,
    'version'    => (string) $r['version'],
    'files'      => (int) $r['files'],
    'checked_at' => date('c'),
    'msg'        => (string) $err,
), JSON_UNESCAPED_UNICODE);
