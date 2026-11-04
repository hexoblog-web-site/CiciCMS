<?php
/**
 * CiciCMS 登录认证（单管理员 / 多管理员通用）
 */

function auth_user()
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    cms_session_start();
    $cached = null;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    if (!empty($_SESSION['last_act']) && time() - (int) $_SESSION['last_act'] > 7200) {
        auth_logout();
        return null;
    }
    $_SESSION['last_act'] = time();
    $cached = one('SELECT id, username, email FROM {p}user WHERE id = ?', array((int) $_SESSION['uid']));
    if (!$cached) {
        unset($_SESSION['uid'], $_SESSION['uname']);
    }
    return $cached;
}

function auth_check()
{
    return auth_user() !== null;
}

function require_login()
{
    if (!auth_check()) {
        $back = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        redirect('login.php' . ($back !== '' ? '?back=' . urlencode($back) : ''));
    }
}

function auth_login($username, $password)
{
    cms_session_start();
    $user = one('SELECT * FROM {p}user WHERE username = ?', array($username));
    if (!$user || !password_verify($password, $user['password'])) {
        return '用户名或密码错误';
    }
    if (function_exists('session_regenerate_id')) {
        session_regenerate_id(true);
    }
    $_SESSION['uid']      = (int) $user['id'];
    $_SESSION['uname']    = $user['username'];
    $_SESSION['last_act'] = time();
    update_row('user', array(
        'last_login' => time(),
        'last_ip'    => client_ip(),
    ), 'id = ?', array((int) $user['id']));
    return true;
}

function auth_logout()
{
    cms_session_start();
    unset($_SESSION['uid'], $_SESSION['uname'], $_SESSION['last_act']);
    if (function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }
}

/* ---------- 登录失败限速（文件存储，无需额外表） ---------- */

function login_guard_file()
{
    return CMS_DATA . '/login_guard.json';
}

function login_guard_read()
{
    $file = login_guard_file();
    if (!is_file($file)) {
        return array();
    }
    $raw = @file_get_contents($file);
    $arr = json_decode((string) $raw, true);
    return is_array($arr) ? $arr : array();
}

function login_guard_write($data)
{
    @file_put_contents(login_guard_file(), json_encode($data), LOCK_EX);
}

/** 返回 true 表示允许继续，字符串表示拒绝原因 */
function login_guard_check()
{
    $ip = client_ip();
    $data = login_guard_read();
    if (!isset($data[$ip])) {
        return true;
    }
    $rec = $data[$ip];
    if ((int) $rec['n'] >= 8 && time() - (int) $rec['t'] < 900) {
        return '失败次数过多，请 15 分钟后再试';
    }
    return true;
}

function login_guard_fail()
{
    $ip = client_ip();
    $data = login_guard_read();
    $now = time();
    foreach ($data as $k => $v) {
        if ($now - (int) $v['t'] > 86400) {
            unset($data[$k]);
        }
    }
    if (!isset($data[$ip]) || $now - (int) $data[$ip]['t'] > 900) {
        $data[$ip] = array('n' => 1, 't' => $now);
    } else {
        $data[$ip]['n'] = (int) $data[$ip]['n'] + 1;
        $data[$ip]['t'] = $now;
    }
    login_guard_write($data);
}

function login_guard_clear()
{
    $ip = client_ip();
    $data = login_guard_read();
    unset($data[$ip]);
    login_guard_write($data);
}
