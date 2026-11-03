<?php
/**
 * 轻量接口：静态页面浏览量统计
 * 静态 HTML 无法计数，用前端 beacon 调用本接口
 *
 * 注意：静态站不需要会话，这里用 cookie 记住「这台浏览器 1 小时内看过这篇」，
 * 不启动 session —— 否则每个访客都会在服务器上留一个 session 文件。
 */

require __DIR__ . '/includes/bootstrap.php';

$act = (string) get('act');

if ($act === 'view') {
    $id = (int) get('id');
    if ($id < 1) {
        json_out(array('ok' => false));
    }

    // 只统计已发布的文章：草稿的浏览数不应对外可见
    $row = one('SELECT views FROM {p}article WHERE id = ? AND status = 1', array($id));
    if (!$row) {
        json_out(array('ok' => false));
    }

    $key  = 'cms_v_' . $id;
    $last = isset($_COOKIE[$key]) ? (int) $_COOKIE[$key] : 0;
    if (time() - $last > 3600) {
        q('UPDATE {p}article SET views = views + 1 WHERE id = ? AND status = 1', array($id));
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie($key, (string) time(), time() + 86400, '/', '', $secure, true);
        }
        $row['views'] = (int) $row['views'] + 1;
    }

    json_out(array('ok' => true, 'views' => (int) $row['views']));
}

json_out(array('ok' => false, 'msg' => 'unknown action'));
