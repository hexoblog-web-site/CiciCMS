<?php
/** 数据库备份下载（需登录）
 *
 * 只有「发文件」这一个动作，没有界面，所以留在这里当一个轻接口；
 * 路径拼接与文件名校验都交给 includes/backup.php 的 db_backup_find()。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

$f = get_str('f');
if ($f === '') {
    http_response_code(400);
    exit('缺少文件名');
}

$path = db_backup_find($f);
if ($path === '') {
    http_response_code(404);
    exit('备份文件不存在');
}

$name = basename($path);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
// 备份里是整站数据，禁止任何缓存/代理留存
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
