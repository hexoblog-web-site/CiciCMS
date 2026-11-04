<?php
/**
 * 自动更新（把 GitHub 仓库当分发服务器）
 *
 * 原理很简单：和 GitHub 比一下「最新提交」和「版本号」，有新的就把源码 zip 拉下来，
 * 只覆盖程序自己的系统区（includes/、admin/、根入口），内容区（content/）与 config.php 一根指头都不碰。
 *
 * 几条硬规则，改代码时别破坏：
 *   · 只走 https，只认 github.com / api.github.com / codeload.github.com 三个域名（URL 由本文件拼，不接受外部传入）
 *   · 更新前先备份系统区，任何一步失败整批回滚，不留半新半旧
 *   · 解压前校验路径，拒绝 ../、绝对路径、以及白名单之外的任何文件
 *   · 远程版本低于本地一律拒绝，避免手滑把站点降级
 *
 * 状态（上次检查时间、已应用的 commit）存在 content/data/update.json，
 * 开关存在 config 表：update_repo / update_branch / update_interval / update_auto_check / update_auto_apply。
 */

/** 状态文件 */
function update_state_file()
{
    return CMS_DATA . '/update.json';
}

/** 读状态，永不出错：文件坏了就当空状态 */
function update_state_read()
{
    $out = array();
    $f   = update_state_file();
    if (is_file($f)) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j)) {
            $out = $j;
        }
    }
    return $out;
}

/** 写状态 */
function update_state_write($state)
{
    return write_file_atomic(
        update_state_file(),
        json_encode((array) $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

/* ---------------- 配置 ---------------- */

/** 仓库，格式 owner/repo；不合法返回空串 */
function update_repo()
{
    $r = trim((string) setting('update_repo', 'hexoblog-web-site/CiciCMS'));
    return update_repo_valid($r) ? $r : '';
}

function update_repo_valid($r)
{
    return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#', (string) $r);
}

/** 分支，默认 main */
function update_branch()
{
    $b = trim((string) setting('update_branch', 'main'));
    if ($b === '' || strlen($b) > 100 || strpos($b, '..') !== false || !preg_match('#^[A-Za-z0-9._/-]+$#', $b)) {
        return 'main';
    }
    return $b;
}

function update_auto_check()
{
    return setting('update_auto_check', '1') === '1';
}

function update_auto_apply()
{
    return setting('update_auto_apply', '0') === '1';
}

/** 检查间隔（秒），1～168 小时 */
function update_interval()
{
    return max(1, min(168, (int) setting('update_interval', '6'))) * 3600;
}

/** 自动检查是否到点（只读本地状态，不联网） */
function update_auto_due()
{
    if (!update_auto_check()) {
        return false;
    }
    $s = update_state_read();
    return empty($s['checked_at']) || (time() - (int) $s['checked_at']) >= update_interval();
}

/** 下次自动检查的时间戳；从没查过或已关闭返回 0 */
function update_next_check_at()
{
    if (!update_auto_check()) {
        return 0;
    }
    $s = update_state_read();
    return empty($s['checked_at']) ? 0 : (int) $s['checked_at'] + update_interval();
}

/* ---------------- 通知 ---------------- */

/** 通知邮箱；留空表示不发邮件 */
function update_notify_email()
{
    $m = trim((string) setting('update_notify_email', ''));
    return ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)) ? $m : '';
}

/** 计划任务入口的令牌，没有就生成一个 */
function update_cron_token()
{
    $t = (string) setting('update_cron_token', '');
    if (!preg_match('#^[a-f0-9]{32}$#', $t)) {
        try {
            $t = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $t = md5(uniqid('cicicms', true));
        }
        set_setting('update_cron_token', $t);
    }
    return $t;
}

/** 定时检查地址：交给服务器计划任务，不登录后台也能查 */
function update_cron_url()
{
    return site_url('admin/cron.php') . '?token=' . update_cron_token();
}

/** 站点主机名，用于邮件标题与发件人 */
function update_mail_host()
{
    $host = (string) parse_url(site_url(), PHP_URL_HOST);
    if ($host === '') {
        $host = 'localhost';
    }
    return preg_replace('#^www\.#i', '', $host);
}

/**
 * 发一封纯文本邮件
 *
 * 只用 PHP 自带的 mail()，不引入 SMTP 依赖；虚拟主机上一般可用，
 * 禁用了就返回失败并把原因写进日志与状态，界面上会明确显示。
 */
function update_mail($to, $subject, $body, &$err = '')
{
    $err = '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = '收件地址不合法';
        return false;
    }
    if (!function_exists('mail')) {
        $err = '服务器禁用了 mail() 函数';
        return false;
    }
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'From: =?UTF-8?B?' . base64_encode('CiciCMS') . '?= <no-reply@' . update_mail_host() . ">\r\n";

    $ok = @mail(
        $to,
        '=?UTF-8?B?' . base64_encode((string) $subject) . '?=',
        chunk_split(base64_encode((string) $body), 76, "\r\n"),
        $headers
    );
    if (!$ok) {
        $err = 'mail() 返回失败，检查服务器的发信配置';
    }
    return (bool) $ok;
}

/**
 * 发现新版本时通知一次
 *
 * 同一个提交只发一封：状态里记下 notified_sha，之后重复检查直接跳过。
 * 发信失败不记 notified_sha，下次到点会再试一次。
 */
function update_notify($state, &$err = '')
{
    $err = '';
    $to  = update_notify_email();
    $sha = isset($state['remote']['sha']) ? (string) $state['remote']['sha'] : '';
    if ($to === '' || $sha === '') {
        return false;
    }
    if (!empty($state['notified_sha']) && (string) $state['notified_sha'] === $sha) {
        return true;
    }

    $ver = isset($state['remote']['version']) ? (string) $state['remote']['version'] : '';
    $msg = isset($state['remote']['msg']) ? (string) $state['remote']['msg'] : '';
    $subject = '[' . update_mail_host() . '] 有新版本 ' . ($ver !== '' ? $ver : substr($sha, 0, 7));
    $body = "自动检查发现新版本。\n\n"
          . "站点：     " . site_url() . "\n"
          . "当前版本： " . CMS_VERSION . "\n"
          . "最新版本： " . ($ver !== '' ? $ver : '未读到版本号') . "\n"
          . "最新提交： " . substr($sha, 0, 7) . ($msg !== '' ? '  ' . $msg : '') . "\n"
          . "发现时间： " . date('Y-m-d H:i') . "\n\n"
          . "去后台处理： " . admin_url('update') . "\n\n"
          . "--\n"
          . "这封邮件由 CiciCMS 自动发出，同一版本只发一次。\n"
          . "可在「后台 → 系统更新」里关闭自动检查或修改收件邮箱。\n";

    $ok = update_mail($to, $subject, $body, $merr);

    if ($ok) {
        $state['notified_sha'] = $sha;
        $state['notified_at']  = time();
        $state['notified_to']  = $to;
        $state['notify_error'] = '';
        log_msg('update notify sent: ' . $to . ' (' . substr($sha, 0, 7) . ')');
    } else {
        $state['notify_error']    = $merr;
        $state['notify_error_at'] = time();
        $err = $merr;
        log_msg('update notify failed: ' . $merr);
    }
    update_state_write($state);
    return $ok;
}

/** 自动更新完成后回一封，让「自己报告」这件事有始有终 */
function update_notify_applied($version, $files, &$err = '')
{
    $err = '';
    $to  = update_notify_email();
    if ($to === '') {
        return false;
    }
    $body = "站点已完成自动更新。\n\n"
          . "站点：     " . site_url() . "\n"
          . "更新到：   " . $version . "\n"
          . "覆盖文件： " . (int) $files . " 个\n"
          . "完成时间： " . date('Y-m-d H:i') . "\n\n"
          . "如果页面有异常，可在「系统更新」页回滚到更新前的备份。\n"
          . admin_url('update') . "\n";
    return update_mail($to, '[' . update_mail_host() . '] 已自动更新到 ' . $version, $body, $err);
}

/**
 * 自动检查的公共入口：后台静默请求、计划任务都走这里
 *
 * 到点才动、全程持锁；发现新版时按设置自动安装，并尽力发一封通知。
 *
 * @return array|false false = 这次没跑（开关关着 / 没到点 / 别的进程正在跑）
 */
function update_auto_run($timeout = 20, &$err = '')
{
    $err = '';
    if (!update_auto_check() || !update_auto_due()) {
        return false;
    }

    $lock = update_lock();
    if ($lock === false) {
        return false;
    }
    /* 拿到锁后再确认一次：另一个进程可能刚检查完 */
    if (!update_auto_due()) {
        update_unlock($lock);
        return false;
    }

    $r   = update_check(false, $cerr, $timeout);
    $err = $cerr;
    $out = array(
        'ok'         => !empty($r['ok']),
        'has_update' => !empty($r['has_update']),
        'applied'    => false,
        'notified'   => false,
        'version'    => '',
        'files'      => 0,
    );
    if (!empty($r['state']['remote']['version'])) {
        $out['version'] = (string) $r['state']['remote']['version'];
    }

    if ($out['ok'] && $out['has_update']) {
        if (update_auto_apply()) {
            $a = update_apply_locked($aerr);
            if ($a === false) {
                $err = $aerr;
            } else {
                $out['applied']  = true;
                $out['version']  = $a['version'];
                $out['files']    = (int) $a['files'];
                $out['notified'] = update_notify_applied($a['version'], $a['files'], $nerr);
                if ($nerr !== '') {
                    $err = trim($err . ' ' . $nerr);
                }
            }
        } else {
            $out['notified'] = update_notify($r['state'], $nerr);
            if ($nerr !== '') {
                $err = trim($err . ' ' . $nerr);
            }
        }
    }

    update_unlock($lock);
    return $out;
}

/* ---------------- HTTP ---------------- */

/**
 * 取一个地址
 *
 * @param string      $url
 * @param string|null $dest     给了就写文件，否则返回字符串
 * @param int         $maxBytes
 * @param string      $err
 * @return string|bool 文件模式返回 true/false；字符串模式返回内容/false
 */
function update_http($url, $dest, $maxBytes, &$err, $timeout = 90)
{
    $err    = '';
    $body   = '';
    $fp     = null;
    $got    = 0;
    $tooBig = false;

    if ($dest !== null) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            $err = '无法写入临时文件';
            return false;
        }
    }

    $write = function ($ch, $chunk) use (&$body, &$fp, &$got, $maxBytes, &$tooBig) {
        $got += strlen($chunk);
        if ($got > $maxBytes) {
            $tooBig = true;
            return 0; // 中断传输
        }
        if ($fp) {
            fwrite($fp, $chunk);
        } else {
            $body .= $chunk;
        }
        return strlen($chunk);
    };

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(10, (int) $timeout));
        curl_setopt($ch, CURLOPT_USERAGENT, 'CiciCMS-Updater/' . CMS_VERSION);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/vnd.github+json'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $write);

        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($fp) {
            fclose($fp);
            $fp = null;
        }

        if ($tooBig) {
            $err = '响应内容超过 ' . format_bytes($maxBytes);
            return false;
        }
        if ($ok === false) {
            $err = $cerr !== '' ? '连接失败：' . $cerr : '连接失败';
            if (stripos($cerr, 'certificate') !== false || stripos($cerr, 'SSL') !== false) {
                $err .= '。服务器缺少 CA 证书：Windows 请在 php.ini 设置 curl.cainfo 指向 cacert.pem，'
                      . 'Linux 装 ca-certificates 即可；证书问题不解决，更新无法安全进行。';
            }
            return false;
        }
        if ($code === 404) {
            $err = '仓库或分支不存在（HTTP 404），请检查仓库地址与分支名';
            return false;
        }
        if ($code === 403) {
            $err = 'GitHub 拒绝访问（HTTP 403），可能是接口调用过于频繁，请稍后再试';
            return false;
        }
        if ($code < 200 || $code >= 300) {
            $err = '请求失败（HTTP ' . $code . '）';
            return false;
        }
        return $dest !== null ? true : $body;
    }

    /* 没有 curl 的主机：流式读取兜底 */
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout'         => max(10, (int) $timeout),
            'user_agent'      => 'CiciCMS-Updater/' . CMS_VERSION,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'header'          => "Accept: application/vnd.github+json\r\n",
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) {
        $err = '无法打开地址（可能是网络不通或证书问题）';
        return false;
    }
    while (!feof($src)) {
        $chunk = fread($src, 8192);
        if ($chunk === false) {
            break;
        }
        /* 闭包签名与 curl 的回调一致（$ch, $chunk），这里没有句柄，传 null 占位 */
        if ($write(null, $chunk) === 0) {
            break;
        }
    }
    fclose($src);
    if ($fp) {
        fclose($fp);
    }
    if ($tooBig) {
        $err = '响应内容超过 ' . format_bytes($maxBytes);
        return false;
    }
    if ($dest !== null) {
        return is_file($dest) && filesize($dest) > 0 ? true : false;
    }
    if ($body === '') {
        $err = '返回内容为空';
        return false;
    }
    return $body;
}

/* ---------------- 查询远程 ---------------- */

/**
 * 远程最新提交与版本号
 *
 * @return array|false array('sha','version','msg','date','branch')
 */
function update_remote_info(&$err, $timeout = 90)
{
    $err = '';
    $repo = update_repo();
    if ($repo === '') {
        $err = '仓库地址未设置或格式不正确，应为 owner/repo';
        return false;
    }
    $branch = update_branch();

    $api = 'https://api.github.com/repos/' . $repo;
    $json = update_http($api . '/commits/' . rawurlencode($branch), null, 1048576, $err, $timeout);
    if ($json === false) {
        return false;
    }
    $j = json_decode($json, true);
    if (!is_array($j) || empty($j['sha'])) {
        $hint = (is_array($j) && !empty($j['message'])) ? (string) $j['message'] : '返回内容不是 JSON';
        $err  = '查询提交失败：' . $hint;
        return false;
    }

    $sha = (string) $j['sha'];
    $msg = '';
    if (!empty($j['commit']['message'])) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $j['commit']['message']);
        $msg   = str_limit(trim($lines[0]), 100);
    }
    $date = !empty($j['commit']['committer']['date']) ? (string) $j['commit']['committer']['date'] : '';

    /* 版本号从 bootstrap.php 里取：仓库还没打 tag 时也能判断新旧 */
    $version = '';
    $raw = update_http($api . '/contents/includes/bootstrap.php?ref=' . rawurlencode($sha), null, 2097152, $e2, $timeout);
    if ($raw !== false) {
        $f = json_decode($raw, true);
        if (!empty($f['content'])) {
            $src = base64_decode(str_replace(array("\n", "\r"), '', (string) $f['content']));
            if (preg_match("/define\\(\\s*'CMS_VERSION'\\s*,\\s*'([0-9][0-9A-Za-z._-]*)'\\s*\\)/", (string) $src, $m)) {
                $version = $m[1];
            }
        }
    }

    return array(
        'sha'     => $sha,
        'version' => $version,
        'msg'     => $msg,
        'date'    => $date,
        'branch'  => $branch,
    );
}

/** 状态里是否存在"有新版" */
function update_has_new($state)
{
    if (empty($state['remote']['sha'])) {
        return false;
    }
    $remote  = (string) $state['remote']['sha'];
    $applied = isset($state['applied_sha']) ? (string) $state['applied_sha'] : '';
    if ($remote === $applied) {
        return false;
    }
    if (!empty($state['remote']['version']) && version_compare((string) $state['remote']['version'], CMS_VERSION, '>')) {
        return true;
    }
    /* 版本号没变（提交者忘了改），但确实推送了新代码：只有记录过已应用 commit 时才提示，
       否则本地开发中的版本永远显示"有更新" */
    if ($applied !== '' && $remote !== $applied) {
        return true;
    }
    return false;
}

/** 后台菜单红点用：只读本地状态，不联网 */
function update_available_cached()
{
    return update_has_new(update_state_read());
}

/**
 * 检查更新
 *
 * @param bool $force 忽略间隔，强制联网
 * @return array ok / cached / has_update / state / err
 */
function update_check($force = false, &$err = '', $timeout = 90)
{
    $err   = '';
    $state = update_state_read();

    if (!$force && !empty($state['checked_at']) && (time() - (int) $state['checked_at']) < update_interval()) {
        return array(
            'ok'         => true,
            'cached'     => true,
            'has_update' => update_has_new($state),
            'state'      => $state,
            'err'        => '',
        );
    }

    $info = update_remote_info($err, $timeout);
    if ($info === false) {
        $state['checked_at'] = time();
        $state['error']      = $err;
        update_state_write($state);
        return array('ok' => false, 'cached' => false, 'has_update' => false, 'state' => $state, 'err' => $err);
    }

    $state['checked_at'] = time();
    $state['error']      = '';
    $state['remote']     = $info;
    update_state_write($state);

    return array(
        'ok'         => true,
        'cached'     => false,
        'has_update' => update_has_new($state),
        'state'      => $state,
        'err'        => '',
    );
}

/* ---------------- 备份与回滚 ---------------- */

/** 系统区里参与更新的目录与根文件 */
function update_system_items()
{
    return array(
        'dirs'  => array('includes', 'admin'),
        'files' => array('index.php', 'api.php', '.htaccess'),
    );
}

/** 备份目录的根 */
function update_backup_root()
{
    return CMS_DATA . '/backup/update';
}

/**
 * 备份当前系统区
 *
 * @return string|false 备份目录
 */
function update_backup_system(&$err)
{
    $err  = '';
    $base = update_backup_root();
    $dir  = $base . '/' . date('Ymd-His') . '-' . str_replace('.', '_', CMS_VERSION);

    if (!ensure_dir($dir)) {
        $err = '创建备份目录失败，请检查 ' . CMS_DATA . '/backup 目录权限';
        return false;
    }

    $items = update_system_items();
    foreach ($items['dirs'] as $d) {
        $src = CMS_ROOT . '/' . $d;
        if (is_dir($src) && !update_copy_tree($src, $dir . '/' . $d, $e)) {
            $err = '备份 ' . $d . ' 失败：' . $e;
            return false;
        }
    }
    foreach ($items['files'] as $f) {
        if (is_file(CMS_ROOT . '/' . $f) && !@copy(CMS_ROOT . '/' . $f, $dir . '/' . $f)) {
            $err = '备份 ' . $f . ' 失败';
            return false;
        }
    }

    update_prune_backups(3);
    return $dir;
}

/** 递归复制目录 */
function update_copy_tree($src, $dst, &$err)
{
    $err = '';
    if (!is_dir($src)) {
        return true;
    }
    if (!ensure_dir($dst)) {
        $err = '创建目录失败：' . $dst;
        return false;
    }
    $dh = @opendir($src);
    if (!$dh) {
        $err = '读取目录失败：' . $src;
        return false;
    }
    while (($item = readdir($dh)) !== false) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            if (!update_copy_tree($s, $d, $err)) {
                closedir($dh);
                return false;
            }
        } elseif (!@copy($s, $d)) {
            $err = '复制失败：' . $item;
            closedir($dh);
            return false;
        }
    }
    closedir($dh);
    return true;
}

/**
 * 用备份覆盖回系统区（回滚）
 *
 * @param string $dir 备份目录
 */
function update_restore($dir, &$err)
{
    $err   = '';
    $root  = realpath(update_backup_root());
    $real  = realpath((string) $dir);
    if ($root === false || $real === false || strpos($real, $root) !== 0 || !is_dir($real)) {
        $err = '备份目录不合法';
        return false;
    }

    $items = update_system_items();
    foreach ($items['dirs'] as $d) {
        if (!is_dir($real . '/' . $d)) {
            continue;
        }
        if (!update_copy_tree($real . '/' . $d, CMS_ROOT . '/' . $d, $e)) {
            $err = '恢复 ' . $d . ' 失败：' . $e;
            return false;
        }
        /* 把「更新时新增、备份里没有」的文件清掉，否则回滚回来还留着新版的文件 */
        update_prune_extra($real . '/' . $d, CMS_ROOT . '/' . $d);
    }
    foreach ($items['files'] as $f) {
        if (is_file($real . '/' . $f) && !@copy($real . '/' . $f, CMS_ROOT . '/' . $f)) {
            $err = '恢复 ' . $f . ' 失败';
            return false;
        }
    }
    return true;
}

/**
 * 删掉目标目录里备份中没有的文件（只用于系统区回滚）
 */
function update_prune_extra($backupDir, $targetDir)
{
    if (!is_dir($targetDir) || !is_dir($backupDir)) {
        return;
    }
    foreach ((array) @scandir($targetDir) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $t = $targetDir . '/' . $n;
        $b = $backupDir . '/' . $n;
        if (is_dir($t)) {
            if (is_dir($b)) {
                update_prune_extra($b, $t);
            } else {
                update_rmdir($t);
            }
        } elseif (!is_file($b)) {
            @unlink($t);
        }
    }
}

/** 只保留最近 N 份备份 */
function update_prune_backups($keep = 3)
{
    $root = update_backup_root();
    if (!is_dir($root)) {
        return;
    }
    $list = array();
    foreach ((array) @scandir($root) as $n) {
        if ($n === '.' || $n === '..' || !is_dir($root . '/' . $n)) {
            continue;
        }
        $list[] = $n;
    }
    sort($list);
    $drop = array_slice($list, 0, max(0, count($list) - $keep));
    foreach ($drop as $n) {
        update_rmdir($root . '/' . $n);
    }
}

/** 递归删目录（只用于更新备份这种自己建的目录） */
function update_rmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) @scandir($dir) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $dir . '/' . $n;
        if (is_dir($p)) {
            update_rmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/* ---------------- 覆盖写入 ---------------- */

/**
 * 允许被更新覆盖的相对路径
 *
 * content/ 与 config.php 永远不在里面；install/ 也不动（装完就该删掉，别把它请回来）。
 */
function update_path_allowed($rel)
{
    $rel = str_replace('\\', '/', ltrim((string) $rel, '/'));
    if ($rel === '' || strpos($rel, '..') !== false) {
        return false;
    }
    if (in_array($rel, array('index.php', 'api.php', '.htaccess', 'README.md', 'LICENSE'), true)) {
        return true;
    }
    if (strpos($rel, 'includes/') === 0 || strpos($rel, 'admin/') === 0) {
        return true;
    }
    return false;
}

/**
 * 写一个文件：先写临时名再 rename，单文件原子替换
 */
function update_write_file($rel, $data, &$err)
{
    $err = '';
    $rel = str_replace('\\', '/', $rel);
    if (!update_path_allowed($rel)) {
        $err = '路径不在允许范围内';
        return false;
    }

    $dst = CMS_ROOT . '/' . $rel;
    if (!ensure_dir(dirname($dst))) {
        $err = '创建目录失败';
        return false;
    }

    $tmp = $dst . '.cmsnew';
    if (@file_put_contents($tmp, $data) === false) {
        $err = '写入失败（目录不可写？）';
        return false;
    }
    if (!@rename($tmp, $dst)) {
        // Windows 下目标文件可能被占用，删掉再试一次
        @unlink($dst);
        if (!@rename($tmp, $dst)) {
            @unlink($tmp);
            $err = '替换文件失败';
            return false;
        }
    }
    @chmod($dst, 0644);
    return true;
}

/**
 * 写权限预检：真写一个探针文件，比 is_writable 可靠
 *
 * 部署时 includes/ 与 admin/ 常设成 555，update_open_dirs() 会临时放开；
 * 如果连 chmod 都不行（PHP 不是属主），这里就要拦下来，别等写到一半才发现。
 */
function update_writable_preflight(&$err)
{
    $err = '';
    foreach (array(CMS_ROOT, CMS_ROOT . '/includes', CMS_ROOT . '/admin') as $d) {
        if (!is_dir($d)) {
            $err = str_replace(CMS_ROOT, '站点根', $d) . ' 不存在';
            return false;
        }
        $probe = $d . '/.cms-write-test';
        if (@file_put_contents($probe, 'x') === false) {
            $err = str_replace(CMS_ROOT, '站点根', $d) . ' 目录不可写，请临时设为 755（属主为 PHP 运行用户）后重试';
            return false;
        }
        @unlink($probe);
    }
    return true;
}

/**
 * 临时放开系统区目录的写权限（部署时设了 555 也能更新）
 *
 * @return array 目录 => 原权限
 */
function update_open_dirs()
{
    $saved = array();
    $dirs  = array(CMS_ROOT . '/includes', CMS_ROOT . '/admin');
    foreach ($dirs as $top) {
        if (!is_dir($top)) {
            continue;
        }
        $stack = array($top);
        while ($stack) {
            $cur = array_pop($stack);
            if (!is_writable($cur)) {
                $old = @fileperms($cur);
                if ($old !== false && @chmod($cur, ($old & 0777) | 0755)) {
                    $saved[$cur] = $old & 0777;
                }
            }
            foreach ((array) @scandir($cur) as $n) {
                if ($n === '.' || $n === '..') {
                    continue;
                }
                $p = $cur . '/' . $n;
                if (is_dir($p)) {
                    $stack[] = $p;
                }
            }
        }
    }
    return $saved;
}

/** 还原目录权限 */
function update_close_dirs($saved)
{
    foreach ((array) $saved as $dir => $perm) {
        @chmod($dir, $perm);
    }
}

/** 清模板编译缓存，避免更新后还用旧缓存 */
function update_clear_cache()
{
    $dir = CMS_DATA . '/cache/tpl';
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) @glob($dir . '/*.php') as $f) {
        @unlink($f);
    }
}

/* ---------------- 下载与安装 ---------------- */

/** 按 commit 下载源码包 */
function update_download($sha, &$err)
{
    $err = '';
    $repo = update_repo();
    if ($repo === '' || !preg_match('#^[0-9a-f]{7,40}$#i', (string) $sha)) {
        $err = '提交号不合法';
        return false;
    }

    $dir = CMS_DATA . '/tmp';
    if (!ensure_dir($dir)) {
        $err = '创建临时目录失败';
        return false;
    }
    $zip = $dir . '/update-' . substr($sha, 0, 12) . '.zip';
    @unlink($zip);

    $url = 'https://codeload.github.com/' . $repo . '/zip/' . rawurlencode($sha);
    if (!update_http($url, $zip, 20 * 1024 * 1024, $err)) {
        @unlink($zip);
        return false;
    }
    if (!is_file($zip) || filesize($zip) < 1024) {
        @unlink($zip);
        $err = '下载的压缩包异常';
        return false;
    }
    return $zip;
}

/** zip 内部的统一前缀（GitHub 打包会套一层 repo-sha/） */
function update_zip_prefix($names)
{
    $first = null;
    foreach ((array) $names as $n) {
        $pos = strpos($n, '/');
        if ($pos === false) {
            return '';
        }
        $seg = substr($n, 0, $pos + 1);
        if ($first === null) {
            $first = $seg;
        } elseif ($first !== $seg) {
            return false;
        }
    }
    return $first === null ? false : $first;
}

/** 从 bootstrap.php 内容里取版本号 */
function update_version_in($src)
{
    if (preg_match("/define\\(\\s*'CMS_VERSION'\\s*,\\s*'([0-9][0-9A-Za-z._-]*)'\\s*\\)/", (string) $src, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * 从 zip 安装更新
 *
 * @param string $zipPath
 * @param array  $info update_remote_info() 的返回
 * @return array|false array('version','files','backup')
 */
function update_install_zip($zipPath, $info, &$err)
{
    $err = '';

    $zip = new Cms_Zip_Reader($zipPath);
    if ($zip->error() !== '') {
        $err = '压缩包无法读取：' . $zip->error();
        return false;
    }
    $entries = $zip->entries();
    $prefix  = update_zip_prefix(array_keys($entries));
    if ($prefix === false) {
        $err = '压缩包结构不符合预期（找不到统一的根目录）';
        return false;
    }

    /* 关键文件必须在，否则不是这个程序的包 */
    foreach (array('includes/bootstrap.php', 'admin/index.php', 'index.php') as $k) {
        if (!isset($entries[$prefix . $k])) {
            $err = '压缩包缺少关键文件：' . $k;
            return false;
        }
    }

    $boot = $zip->read($prefix . 'includes/bootstrap.php');
    if ($boot === false) {
        $err = '读取版本文件失败：' . $zip->error();
        return false;
    }
    $remoteVer = update_version_in($boot);
    if ($remoteVer === '') {
        $err = '压缩包里读不到版本号，已拒绝更新';
        return false;
    }
    if (version_compare($remoteVer, CMS_VERSION, '<')) {
        $err = '远程版本 ' . $remoteVer . ' 低于当前 ' . CMS_VERSION . '，已取消';
        return false;
    }

    /* 先备份，后动手 */
    $backup = update_backup_system($err);
    if ($backup === false) {
        return false;
    }

    $perms = update_open_dirs();
    if (!update_writable_preflight($perr)) {
        update_close_dirs($perms);
        $err = '系统目录不可写，已取消更新：' . $perr;
        return false;
    }

    $written = 0;
    $failed  = array();

    foreach ($entries as $name => $e) {
        if ($prefix !== '' && strpos($name, $prefix) !== 0) {
            continue;
        }
        $rel = $prefix === '' ? $name : substr($name, strlen($prefix));
        if (!update_path_allowed($rel)) {
            continue;
        }
        $data = $zip->read($name);
        if ($data === false) {
            $failed[] = $rel . '（' . $zip->error() . '）';
            continue;
        }
        if (!update_write_file($rel, $data, $werr)) {
            $failed[] = $rel . '（' . $werr . '）';
        } else {
            $written++;
        }
    }

    /* 写完复核一遍关键文件，避免解压中途出问题还当成成功 */
    if (!$failed && update_version_in((string) @file_get_contents(CMS_ROOT . '/includes/bootstrap.php')) === '') {
        $failed[] = 'includes/bootstrap.php 写入后不可读';
    }

    if ($failed) {
        $restoreErr = '';
        update_restore($backup, $restoreErr);
        update_close_dirs($perms);
        $err = '更新中断，' . ($restoreErr === '' ? '已回滚到更新前的版本' : '回滚也失败了，请手工从备份恢复：' . $backup)
             . '。原因：' . implode('；', array_slice($failed, 0, 3));
        return false;
    }

    update_close_dirs($perms);
    update_clear_cache();
    update_prune_backups(3);

    $state = update_state_read();
    $state['applied_sha']     = (string) $info['sha'];
    $state['applied_version'] = $remoteVer;
    $state['last_update_at']  = time();
    $state['last_backup']     = $backup;
    $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : array();
    array_unshift($history, array(
        'at'      => time(),
        'version' => $remoteVer,
        'sha'     => substr((string) $info['sha'], 0, 12),
        'files'   => $written,
    ));
    $state['history'] = array_slice($history, 0, 10);
    update_state_write($state);

    return array('version' => $remoteVer, 'files' => $written, 'backup' => $backup);
}

/**
 * 更新锁：同一时间只允许一个更新在跑
 *
 * 两个后台标签页同时点到、或者自动更新和手动更新撞上，没有锁就会互相覆盖。
 *
 * @return resource|false
 */
function update_lock()
{
    $fp = @fopen(CMS_DATA . '/update.lock', 'c');
    if (!$fp) {
        return false;
    }
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function update_unlock($fp)
{
    if ($fp) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/**
 * 一键更新：查 → 下载 → 安装
 *
 * @return array|false array('version','files','backup')
 */
function update_apply(&$err)
{
    $err  = '';
    $lock = update_lock();
    if ($lock === false) {
        $err = '另一个更新正在进行，请稍后刷新再试';
        return false;
    }
    $r = update_apply_locked($err);
    update_unlock($lock);
    return $r;
}

/** 真正干活的那个，由 update_apply 持锁调用 */
function update_apply_locked(&$err)
{
    $err = '';
    @set_time_limit(180);

    $info = update_remote_info($err);
    if ($info === false) {
        return false;
    }
    if ($info['version'] !== '' && version_compare($info['version'], CMS_VERSION, '<')) {
        $err = '远程版本 ' . $info['version'] . ' 低于当前 ' . CMS_VERSION . '，已取消';
        return false;
    }

    $zip = update_download($info['sha'], $err);
    if ($zip === false) {
        return false;
    }

    $r = update_install_zip($zip, $info, $err);
    @unlink($zip);

    if ($r === false) {
        return false;
    }

    log_msg('update applied: ' . $r['version'] . ' (' . $r['files'] . ' files)');
    return $r;
}

/** 回滚到指定的更新备份 */
function update_rollback($dir, &$err)
{
    $err = '';

    $perms = update_open_dirs();
    $ok    = update_restore($dir, $err);
    update_close_dirs($perms);

    if (!$ok) {
        return false;
    }

    $state = update_state_read();
    unset($state['applied_sha'], $state['applied_version']);
    $state['last_rollback_at'] = time();
    update_state_write($state);
    update_clear_cache();
    log_msg('update rollback: ' . basename($dir));
    return true;
}

/** 可用备份列表（新 → 旧） */
function update_backups()
{
    $root = update_backup_root();
    $out  = array();
    if (!is_dir($root)) {
        return $out;
    }
    foreach ((array) @scandir($root) as $n) {
        if ($n === '.' || $n === '..' || !is_dir($root . '/' . $n)) {
            continue;
        }
        $out[] = array(
            'name' => $n,
            'path' => $root . '/' . $n,
            'time' => (int) @filemtime($root . '/' . $n),
        );
    }
    /* 目录名是 Ymd-His-版本，字典序就是时间序，比 filemtime 稳（复制备份时 mtime 会变） */
    usort($out, function ($a, $b) {
        return strcmp($b['name'], $a['name']);
    });
    return $out;
}
