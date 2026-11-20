<?php
/**
 * CiciCms 后台 JSON API
 *
 * 所有请求必须已登录 + CSRF（登录接口除外）。
 * 统一返回：{ ok:true, ... } 或 { ok:false, msg:'...' }
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once CMS_INC . '/legacy-store.php';

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

/* 登录只走 admin/login.php：那里有失败限速、会话重建和统一跳转。
   这里保留一个明确拒绝，避免旧脚本把账号密码发到 JSON 接口来试。 */
if ($r === 'login') {
    api_fail('请使用后台登录页登录', 403);
}

/* 系统更新写盘期间，后台 API 也暂停，避免读写半新半旧的数据 */
if (function_exists('update_maintenance_active') && update_maintenance_active()) {
    update_maintenance_page(true);
    exit;
}

require_login();
$me = auth_user();

/*
 * 后端权限门禁：前端 CAP_ROLES 只负责藏菜单，这里才是真正拦请求的。
 * 新增 action 时务必在这里登记，漏登记的 action 默认不设限——
 * 所以宁可显式列全，也不要靠“猜”。
 */
$CAP_MAP = array(
    'state'           => null,
    'me'              => null,
    'logout'          => null,
    'save'            => 'content',
    'upload'          => 'media',
    'build'           => 'publish',
    'build-all'       => 'publish',
    'db-list'         => 'data',
    'db-backup'       => 'data',
    'db-delete'       => 'data',
    'db-restore-file' => 'data',
    'db-restore'      => 'data',
    'update-status'   => 'system',
    'update-check'    => 'system',
    'update-apply'    => 'system',
    'update-rollback' => 'system',
    'update-save'     => 'system',
    'health'          => 'data',
    'media-clean'     => 'data',
    'user-role'       => 'accounts',
);
if (isset($CAP_MAP[$r]) && $CAP_MAP[$r] !== null) {
    auth_need($CAP_MAP[$r]);
}

switch ($r) {

    /* 拉取完整状态 */
    case 'state':
        try {
            $db = legacy_bootstrap();
            api_out(array(
                'ok'      => true,
                'db'      => $db,
                'user'    => array(
                    'name' => isset($me['username']) ? $me['username'] : 'admin',
                    'role' => auth_role(),
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
            /* 非管理员不得改站点设置/账号/序号：这几块用库里的真值覆盖提交包。
               否则 editor 只要在保存包里塞一份 settings，就能改站点配置甚至给自己提权。 */
            if (!auth_can('settings')) {
                $fresh = legacy_bootstrap();
                foreach (array('settings', 'users', 'seq') as $protected) {
                    if (isset($fresh[$protected])) {
                        $b['db'][$protected] = $fresh[$protected];
                    }
                }
            }

            /* rev 由前端从 state 带回：不一致说明别的窗口已经改过，拒绝覆盖 */
            $rev = isset($b['rev']) ? (int) $b['rev'] : null;
            $counts = legacy_commit($b['db'], $rev);
            api_out(array(
                'ok'     => true,
                'counts' => $counts,
                'rev'    => isset($counts['rev']) ? (int) $counts['rev'] : 0,
                'at'     => date('Y/m/d H:i:s'),
            ));
        } catch (Legacy_Conflict $ex) {
            api_fail($ex->getMessage(), 409);
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
        $info = CMS_upload_image($_FILES['file'], $err, true);
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
        api_out(array('ok' => true, 'name' => isset($me['username']) ? $me['username'] : 'admin', 'role' => auth_role()));
        break;

    /* 改用户角色：仅管理员可用。不给 editor 自己提权的口子 */
    case 'user-role':
        api_csrf();
        $uid  = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;
        $b    = api_body();
        if ($uid === 0 && isset($b['uid'])) {
            $uid = (int) $b['uid'];
        }
        $role = isset($_POST['role']) ? (string) $_POST['role'] : '';
        if ($role === '' && isset($b['role'])) {
            $role = (string) $b['role'];
        }
        if ($uid < 1 || !in_array($role, auth_roles(), true)) {
            api_fail('用户或角色不合法');
        }
        $target = one('SELECT id, username FROM {p}user WHERE id = ?', array($uid));
        if (!$target) {
            api_fail('用户不存在', 404);
        }
        if ((int) $me['id'] === $uid && $role !== 'admin') {
            api_fail('不能降低自己的角色，请让另一位管理员操作');
        }
        update_row('user', array('role' => $role), 'id = ?', array($uid));
        log_msg('user role changed: ' . $target['username'] . ' -> ' . $role . ' by ' . $me['username']);
        api_out(array('ok' => true, 'uid' => $uid, 'role' => $role));
        break;

    /* ===== MySQL 数据库备份（.sql） ===== */

    /* 备份列表：返回服务器 data/backup 下的 .sql 文件 */
    case 'db-list':
        try {
            $list = array();
            foreach (db_backup_list() as $name) {
                $list[] = array(
                    'name' => $name,
                    'size' => db_backup_size($name),
                    'time' => filemtime(db_backup_find($name)),
                );
            }
            api_out(array('ok' => true, 'list' => $list));
        } catch (Throwable $ex) {
            api_fail('读取备份列表失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 立即备份：导出整库 .sql 到服务器 */
    case 'db-backup':
        api_csrf();
        try {
            $name = db_backup();
            if (!$name) {
                api_fail('备份失败，请检查服务器 data/backup 目录权限', 500);
            }
            api_out(array('ok' => true, 'name' => $name, 'size' => db_backup_size($name)));
        } catch (Throwable $ex) {
            api_fail('备份失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 删除一份备份 */
    case 'db-delete':
        api_csrf();
        $b = api_body();
        $name = isset($b['name']) ? (string) $b['name'] : '';
        if ($name === '') {
            api_fail('缺少文件名');
        }
        if (!db_backup_delete($name)) {
            api_fail('删除失败，文件不存在或无权限');
        }
        api_out(array('ok' => true));
        break;

    /* 从服务器上已有的备份文件恢复（列表里的「导入」按钮） */
    case 'db-restore-file':
        api_csrf();
        $b = api_body();
        $name = isset($b['name']) ? (string) $b['name'] : '';
        $path = $name !== '' ? db_backup_find($name) : '';
        if ($path === '') {
            api_fail('备份文件不存在');
        }
        try {
            $info = array();
            $ok = db_restore_file($path, $info);
            if (!$ok) {
                api_fail(!empty($info['error']) ? $info['error'] : '恢复失败', 500);
            }
            api_out(array('ok' => true) + $info);
        } catch (Throwable $ex) {
            api_fail('恢复失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 从上传的 .sql 文件恢复 */
    case 'db-restore':
        api_csrf();
        if (empty($_FILES['file'])) {
            api_fail('没有收到 SQL 文件，请检查是否已选择备份文件');
        }
        $up = $_FILES['file'];
        if (!empty($up['error'])) {
            $upErr = array(
                1 => '文件超过服务器上传上限（upload_max_filesize），请改用「备份记录」里的「导入」',
                2 => '文件超过表单提交上限（post_max_size），请改用「备份记录」里的「导入」',
                3 => '文件只上传了一部分，请重试或改用「备份记录」导入',
                4 => '没有选择文件',
                6 => '服务器临时目录不可写，请联系管理员',
                7 => '文件写入失败，请重试',
            );
            $code = (int) $up['error'];
            api_fail(isset($upErr[$code]) ? $upErr[$code] : ('上传失败，错误码 ' . $code));
        }
        if (!is_uploaded_file($up['tmp_name'])) {
            api_fail('非法的上传文件');
        }
        if (substr(strtolower((string) $up['name']), -4) !== '.sql') {
            api_fail('只接受 .sql 备份文件');
        }
        if (filesize($up['tmp_name']) > 64 * 1024 * 1024) {
            api_fail('文件超过 64MB，请用命令行导入');
        }
        try {
            $info = array();
            $ok = db_restore_file($up['tmp_name'], $info);
            if (!$ok) {
                api_fail(!empty($info['error']) ? $info['error'] : '恢复失败', 500);
            }
            api_out(array('ok' => true) + $info);
        } catch (Throwable $ex) {
            api_fail('恢复失败：' . $ex->getMessage(), 500);
        }
        break;

    /* ===== 系统更新 ===== */

    /* 更新状态：只读本地状态与配置，不联网，用于页面首屏渲染 */
    case 'update-status':
        try {
            $st     = update_state_read();
            $remote = (isset($st['remote']) && is_array($st['remote'])) ? $st['remote'] : array();
            $bks    = array();
            foreach (update_backups() as $b) {
                $bks[] = array('name' => $b['name'], 'time' => (int) $b['time']);
            }
            api_out(array(
                'ok'          => true,
                'version'     => CMS_VERSION,
                'hasUpdate'   => update_has_new($st),
                'repo'        => update_repo(),
                'repoError'   => update_repo_error(),
                'branch'      => update_branch(),
                'checkedAt'   => isset($st['checked_at']) ? (int) $st['checked_at'] : 0,
                'nextCheckAt' => update_next_check_at(),
                'lastError'   => isset($st['error']) ? (string) $st['error'] : '',
                'remote'      => array(
                    'version' => isset($remote['version']) ? (string) $remote['version'] : '',
                    'sha'     => isset($remote['sha']) ? (string) $remote['sha'] : '',
                    'msg'     => isset($remote['msg']) ? (string) $remote['msg'] : '',
                    'date'    => isset($remote['date']) ? (string) $remote['date'] : '',
                ),
                'appliedSha'  => isset($st['applied_sha']) ? (string) $st['applied_sha'] : '',
                'appliedVer'  => isset($st['applied_version']) ? (string) $st['applied_version'] : '',
                'lastRollbackAt' => isset($st['last_rollback_at']) ? (int) $st['last_rollback_at'] : 0,
                'settings'    => array(
                    'autoCheck'   => update_auto_check(),
                    'autoApply'   => update_auto_apply(),
                    'interval'    => (int) setting('update_interval', '6'),
                    'notifyEmail' => (string) setting('update_notify_email', ''),
                ),
                'cronUrl'     => update_cron_url(),
                'backups'     => $bks,
            ));
        } catch (Throwable $ex) {
            api_fail('读取更新状态失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 检查更新：强制联网比对最新提交与版本号 */
    case 'update-check':
        api_csrf();
        try {
            $r = update_check(true, $err, 60);
            if (empty($r['ok'])) {
                api_fail($err !== '' ? $err : '检查更新失败，请稍后重试', 500);
            }
            $st     = isset($r['state']) && is_array($r['state']) ? $r['state'] : array();
            $remote = (isset($st['remote']) && is_array($st['remote'])) ? $st['remote'] : array();
            api_out(array(
                'ok'        => true,
                'hasUpdate' => !empty($r['has_update']),
                'checkedAt' => isset($st['checked_at']) ? (int) $st['checked_at'] : 0,
                'remote'    => array(
                    'version' => isset($remote['version']) ? (string) $remote['version'] : '',
                    'sha'     => isset($remote['sha']) ? (string) $remote['sha'] : '',
                    'msg'     => isset($remote['msg']) ? (string) $remote['msg'] : '',
                    'date'    => isset($remote['date']) ? (string) $remote['date'] : '',
                ),
            ));
        } catch (Throwable $ex) {
            api_fail('检查更新失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 一键更新：下载 → 校验 → 备份 → 覆盖 → 失败回滚 */
    case 'update-apply':
        api_csrf();
        try {
            $r = update_apply($err);
            if ($r === false) {
                api_fail($err !== '' ? $err : '更新失败，已保留原文件', 500);
            }
            api_out(array(
                'ok'      => true,
                'version' => isset($r['version']) ? (string) $r['version'] : '',
                'files'   => isset($r['files']) ? (int) $r['files'] : 0,
                'backup'  => isset($r['backup']) && $r['backup'] ? basename((string) $r['backup']) : '',
            ));
        } catch (Throwable $ex) {
            api_fail('更新失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 回滚到某次更新前的系统区备份 */
    case 'update-rollback':
        api_csrf();
        $b    = api_body();
        $name = isset($b['name']) ? (string) $b['name'] : '';
        if ($name === '') {
            api_fail('缺少备份名称');
        }
        $target = '';
        foreach (update_backups() as $bk) {
            if ($bk['name'] === $name) {
                $target = $bk['path'];
                break;
            }
        }
        if ($target === '') {
            api_fail('更新备份不存在，可能已被清理');
        }
        try {
            if (!update_rollback($target, $err)) {
                api_fail($err !== '' ? $err : '回滚失败', 500);
            }
            api_out(array('ok' => true, 'name' => $name));
        } catch (Throwable $ex) {
            api_fail('回滚失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 保存更新设置（自动检查 / 间隔 / 自动安装 / 通知邮箱） */
    case 'update-save':
        api_csrf();
        $b = api_body();
        $em = isset($b['notifyEmail']) ? trim((string) $b['notifyEmail']) : '';
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            api_fail('通知邮箱格式不正确');
        }
        $iv = isset($b['interval']) ? (int) $b['interval'] : 6;
        set_setting('update_auto_check', !empty($b['autoCheck']) ? '1' : '0');
        set_setting('update_auto_apply', !empty($b['autoApply']) ? '1' : '0');
        set_setting('update_interval', (string) max(1, min(168, $iv)));
        set_setting('update_notify_email', $em);
        api_out(array('ok' => true));
        break;

    /* ===== 站点体检 / 无主文件清理 ===== */

    /* 只读体检：数据完整性 + 目录权限 + 上传目录统计 */
    case 'health':
        try {
            $health = CMS_db_health_check();
            $perm   = CMS_permission_audit();
            $clean  = CMS_clean_scan();
            api_out(array(
                'ok'     => true,
                'health' => $health,
                'perm'   => $perm,
                'files'  => array(
                    'total'   => (int) $clean['total'],
                    'used'    => (int) $clean['used'],
                    'unused'  => (int) $clean['unused'],
                    'bytes'   => (int) $clean['bytes'],
                    'missing' => (int) $clean['missing'],
                ),
            ));
        } catch (Throwable $ex) {
            api_fail('体检失败：' . $ex->getMessage(), 500);
        }
        break;

    /* 清理未引用的上传文件；不传 paths 时清理全部未引用项 */
    case 'media-clean':
        api_csrf();
        $b     = api_body();
        $paths = isset($b['paths']) && is_array($b['paths']) ? $b['paths'] : array();
        if (!$paths) {
            $scan = CMS_clean_scan();
            foreach ($scan['files'] as $f) {
                if (empty($f['used']) && empty($f['missing'])) {
                    $paths[] = $f['rel'];
                }
            }
        }
        if (!$paths) {
            api_out(array('ok' => true, 'deleted' => 0, 'skip' => array(), 'fail' => array()));
        }
        try {
            $r = CMS_clean_delete_many($paths);
            api_out(array(
                'ok'      => true,
                'deleted' => (int) $r['ok'],
                'skip'    => $r['skip'],
                'fail'    => $r['fail'],
            ));
        } catch (Throwable $ex) {
            api_fail('清理失败：' . $ex->getMessage(), 500);
        }
        break;

    default:
        api_fail('未知的 API：' . $r, 404);
}
