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
    CMS_session_start();
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
        // 统一用站点根目录绝对路径，避免相对路径在不同目录层级下解析错误。
        redirect(site_url('admin/login.php') . ($back !== '' ? '?back=' . urlencode($back) : ''));
    }
}

function auth_login($username, $password)
{
    CMS_session_start();
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

/**
 * 退出登录
 *
 * 只 unset 三个键是不够的：session 文件还留在服务器上，共享主机上同机的
 * 其他站点若能读到 session 目录，就能拿这个「已经登出」的会话继续用。
 * 必须清空数组 + 销毁会话 + 过期 cookie，三件事都做。
 */
function auth_logout()
{
    CMS_session_start();
    $_SESSION = array();
    if (function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }
    if (function_exists('session_destroy')) {
        @session_destroy();
    }
    if (!headers_sent()) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(session_name(), '', time() - 3600, '/', '', $secure, true);
    }
}

/* ---------- 登录失败限速（文件存储，无需额外表） ---------- */

function login_guard_file()
{
    return CMS_DATA . '/login_guard.json';
}

/**
 * 在文件锁内完成「读 → 改 → 写」
 *
 * 早期版本是 read() / 改数组 / write() 三步分开的，只有 write 那一瞬间加了
 * LOCK_EX。并发 20 个登录请求时，20 个进程都读到 n=0，各自写回 n=1，
 * 「15 分钟 8 次」实际退化成「每轮只记 1 次」，8 位以内的密码几分钟就能跑完。
 * 判断与记账必须在同一把锁里，否则检查完再记账之间仍然有窗口。
 *
 * @param callable $fn 收到当前数据，返回要写回的数据
 * @return array|null 写回后的数据；拿不到锁返回 null
 */
function login_guard_update($fn)
{
    $file = login_guard_file();
    if (!ensure_dir(dirname($file))) {
        return null;
    }
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return null;
    }
    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        return null;
    }

    $raw  = stream_get_contents($fp);
    $data = json_decode((string) $raw, true);
    $data = is_array($data) ? $data : array();

    $out = call_user_func($fn, $data);
    if (!is_array($out)) {
        $out = $data;
    }

    if (@ftruncate($fp, 0)) {
        @rewind($fp);
        @fwrite($fp, (string) json_encode($out));
        @fflush($fp);
    }
    @flock($fp, LOCK_UN);
    @fclose($fp);

    return $out;
}

/** 只读快照（共享锁）：给后台展示用，任何判断都不要基于它 */
function login_guard_read()
{
    $file = login_guard_file();
    if (!is_file($file)) {
        return array();
    }
    $fp = @fopen($file, 'r');
    if (!$fp) {
        return array();
    }
    @flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    $arr = json_decode((string) $raw, true);
    return is_array($arr) ? $arr : array();
}

/** 清掉 24 小时前的记录；_global 是全局计数器，单独保留 */
function login_guard_prune($data, $now)
{
    foreach ((array) $data as $k => $v) {
        if ($k === '_global' || !is_array($v) || !isset($v['t'])) {
            continue;
        }
        if ($now - (int) $v['t'] > 86400) {
            unset($data[$k]);
        }
    }
    return $data;
}

/** 返回 true 表示允许继续，字符串表示拒绝原因 */
function login_guard_check()
{
    $ip     = client_ip();
    $reason = '';
    $now    = time();

    login_guard_update(function ($data) use ($ip, $now, &$reason) {
        $data = login_guard_prune($data, $now);

        // 1) 全站失败速率：按 IP 限速对「一批肉鸡」无效，这一层兜住那种攻击
        $g = isset($data['_global']) && is_array($data['_global']) ? $data['_global'] : array();
        if (!empty($g['until']) && (int) $g['until'] > $now) {
            $reason = '站点登录失败过于频繁，请 10 分钟后再试';
            return $data;
        }

        // 2) 单 IP：15 分钟内失败 8 次
        if (isset($data[$ip]) && is_array($data[$ip])) {
            $rec = $data[$ip];
            if ((int) $rec['n'] >= 8 && $now - (int) $rec['t'] < 900) {
                $reason = '失败次数过多，请 15 分钟后再试';
            }
        }
        return $data;
    });

    return $reason === '' ? true : $reason;
}

function login_guard_fail()
{
    $ip  = client_ip();
    $now = time();

    login_guard_update(function ($data) use ($ip, $now) {
        $data = login_guard_prune($data, $now);

        if (!isset($data[$ip]) || !is_array($data[$ip]) || $now - (int) $data[$ip]['t'] > 900) {
            $data[$ip] = array('n' => 1, 't' => $now);
        } else {
            $data[$ip]['n'] = (int) $data[$ip]['n'] + 1;
            $data[$ip]['t'] = $now;
        }

        // 全局滑动窗口：5 分钟内 200 次失败 → 暂停登录 10 分钟
        $g = isset($data['_global']) && is_array($data['_global']) ? $data['_global'] : array();
        if (empty($g['t']) || $now - (int) $g['t'] > 300) {
            $g['n'] = 1;
            $g['t'] = $now;
        } else {
            $g['n'] = (int) $g['n'] + 1;
        }
        if ((int) $g['n'] >= 200) {
            $g['until'] = $now + 600;
            $g['n']     = 0;
            $g['t']     = $now;
        }
        $data['_global'] = $g;

        return $data;
    });
}

function login_guard_clear()
{
    $ip = client_ip();
    login_guard_update(function ($data) use ($ip) {
        unset($data[$ip]);
        return $data;
    });
}
