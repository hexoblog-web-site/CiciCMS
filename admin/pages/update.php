<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 系统更新：以 GitHub 仓库为分发源 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'save') {
        $repo = trim(post_str('update_repo'));
        if ($repo !== '' && !update_repo_valid($repo)) {
            flash('仓库地址格式不对，应形如 owner/repo', 'err');
            redirect(admin_url('update'));
        }
        $branch = trim(post_str('update_branch', 'main'));
        if ($branch === '' || strpos($branch, '..') !== false || !preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
            flash('分支名不合法', 'err');
            redirect(admin_url('update'));
        }
        $interval = (int) post_str('update_interval', '6');
        $email    = trim(post_str('update_notify_email'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('通知邮箱格式不对，应形如 you@example.com', 'err');
            redirect(admin_url('update'));
        }

        /* 发信方式：mail() 或 SMTP */
        $driver = post_str('mail_driver', 'mail') === 'smtp' ? 'smtp' : 'mail';
        $mhost  = trim(post_str('mail_host'));
        $mport  = (int) post_str('mail_port', '0');
        $msec   = post_str('mail_secure', 'ssl');
        $muser  = trim(post_str('mail_user'));
        $mfrom  = trim(post_str('mail_from'));
        $mpass  = (string) post_str('mail_pass');

        if (!in_array($msec, array('ssl', 'tls', 'none'), true)) {
            $msec = 'ssl';
        }
        if ($mport !== 0 && ($mport < 1 || $mport > 65535)) {
            flash('SMTP 端口应在 1–65535 之间', 'err');
            redirect(admin_url('update'));
        }
        if ($mfrom !== '' && !filter_var($mfrom, FILTER_VALIDATE_EMAIL)) {
            flash('发件邮箱格式不对，应形如 no-reply@example.com', 'err');
            redirect(admin_url('update'));
        }
        if ($driver === 'smtp') {
            if ($mhost === '' || $muser === '') {
                flash('选了 SMTP 发信，服务器地址和账号都要填', 'err');
                redirect(admin_url('update'));
            }
            if ($mpass === '' && (string) setting('mail_pass', '') === '') {
                flash('选了 SMTP 发信，授权码（或密码）不能为空', 'err');
                redirect(admin_url('update'));
            }
        }

        set_setting('update_repo', $repo);
        set_setting('update_branch', $branch);
        set_setting('update_interval', (string) max(1, min(168, $interval)));
        set_setting('update_auto_check', post_str('update_auto_check', '0') === '1' ? '1' : '0');
        set_setting('update_auto_apply', post_str('update_auto_apply', '0') === '1' ? '1' : '0');
        set_setting('update_notify_email', $email);

        set_setting('mail_driver', $driver);
        set_setting('mail_host', $mhost);
        set_setting('mail_port', (string) max(0, $mport));
        set_setting('mail_secure', $msec);
        set_setting('mail_user', $muser);
        set_setting('mail_from', $mfrom);
        set_setting('mail_from_name', trim(post_str('mail_from_name', 'CiciCMS')));
        set_setting('mail_verify', post_str('mail_verify', '0') === '1' ? '1' : '0');
        /* 授权码留空表示不改，避免每次保存都要重填，也避免明文回显在页面源码里 */
        if ($mpass !== '') {
            set_setting('mail_pass', $mpass);
        }

        log_msg('update settings saved: ' . $repo . '@' . $branch . ' / mail=' . $driver);

        flash('更新设置已保存');
        redirect(admin_url('update'));
    }

    if ($do === 'testmail') {
        $to = update_notify_email();
        if ($to === '') {
            flash('先填写通知邮箱并保存，再发测试邮件', 'err');
        } else {
            $ok = update_mail(
                $to,
                '[' . update_mail_host() . '] 测试邮件',
                "这是一封来自 CiciCMS 的测试邮件。\n\n"
                . "站点：" . site_url() . "\n"
                . "时间：" . date('Y-m-d H:i') . "\n\n"
                . "收到这封邮件，说明「发现新版本自动通知」可以正常发出。\n",
                $merr
            );
            if ($ok) {
                flash('测试邮件已发送到 ' . $to . '（发信方式：' . (cms_mail_config()['driver'] === 'smtp' ? 'SMTP' : 'PHP mail()') . '），去收件箱看一下，也可能在垃圾箱');
            } else {
                flash('发送失败：' . $merr, 'err');
            }
        }
        redirect(admin_url('update'));
    }

    if ($do === 'rotate') {
        set_setting('update_cron_token', '');
        update_cron_token();
        log_msg('update cron token rotated');
        flash('定时检查地址已重置，旧地址立即失效，记得去服务器改计划任务');
        redirect(admin_url('update'));
    }

    if ($do === 'check') {
        $r = update_check(true, $err);
        if (!$r['ok']) {
            flash('检查失败：' . $err, 'err');
        } elseif (!empty($r['has_update'])) {
            $v = !empty($r['state']['remote']['version']) ? $r['state']['remote']['version'] : '新提交';
            flash('发现新版本：' . $v);
        } else {
            flash('已是最新版本（当前 ' . CMS_VERSION . '）');
        }
        redirect(admin_url('update'));
    }

    if ($do === 'apply') {
        $r = update_apply($err);
        if ($r === false) {
            flash('更新失败：' . $err, 'err');
        } else {
            flash('已更新到 ' . $r['version'] . '（覆盖 ' . (int) $r['files'] . ' 个文件）。刷新页面后生效。');
        }
        redirect(admin_url('update'));
    }

    if ($do === 'skip') {
        $state = update_state_read();
        if (empty($state['remote']['sha'])) {
            flash('还没有检查结果，先点「立即检查」', 'err');
        } else {
            $state['applied_sha']     = (string) $state['remote']['sha'];
            $state['applied_version'] = isset($state['remote']['version']) ? (string) $state['remote']['version'] : '';
            $state['skipped_at']      = time();
            update_state_write($state);
            flash('已忽略这次更新，仓库有新提交时会再提示');
        }
        redirect(admin_url('update'));
    }

    if ($do === 'rollback') {
        $name = post_str('name');
        $hit  = null;
        foreach (update_backups() as $b) {
            if ($b['name'] === $name) {
                $hit = $b;
                break;
            }
        }
        if ($hit === null) {
            flash('备份不存在', 'err');
        } elseif (update_rollback($hit['path'], $err)) {
            flash('已回滚到 ' . $name . ' 的版本。刷新页面后生效。');
        } else {
            flash('回滚失败：' . $err, 'err');
        }
        redirect(admin_url('update'));
    }
}

$state   = update_state_read();
$remote  = isset($state['remote']) && is_array($state['remote']) ? $state['remote'] : array();
$hasNew  = update_has_new($state);
$backups = update_backups();
$history = isset($state['history']) && is_array($state['history']) ? $state['history'] : array();
$repo    = (string) setting('update_repo', 'hexoblog-web-site/CiciCMS');
$branch  = update_branch();
$lastErr = (string) (isset($state['error']) ? $state['error'] : '');

$remoteVer = isset($remote['version']) ? (string) $remote['version'] : '';
$remoteSha = isset($remote['sha']) ? (string) $remote['sha'] : '';
$remoteMsg = isset($remote['msg']) ? (string) $remote['msg'] : '';
$remoteAt  = isset($remote['date']) ? strtotime((string) $remote['date']) : 0;

/* 自动检查与通知的状态 */
$notifyTo   = update_notify_email();
$nextCheck  = update_next_check_at();
$notifiedAt = isset($state['notified_at']) ? (int) $state['notified_at'] : 0;
$notifiedTo = isset($state['notified_to']) ? (string) $state['notified_to'] : '';
$notifyErr  = isset($state['notify_error']) ? (string) $state['notify_error'] : '';
$cronUrl    = update_cron_url();

/* 发信配置 */
$mailCfg  = cms_mail_config();
$hasPass  = (string) setting('mail_pass', '') !== '';
?>
<?php if ($hasNew): ?>
    <div class="alert alert-warn">
        发现新版本<?php echo $remoteVer !== '' ? '（' . e($remoteVer) . '）' : ''; ?>，当前是 <?php echo e(CMS_VERSION); ?>。更新前会自动备份，失败会自动回滚。
    </div>
<?php endif; ?>
<?php if ($lastErr !== '' && !$hasNew): ?>
    <div class="alert alert-err">上次检查失败：<?php echo e($lastErr); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>版本状态</h2>
        <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="检查中…">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="check">
            <button class="btn" type="submit">立即检查</button>
        </form>
    </div>
    <div class="card-body">
        <div class="kv"><span>当前版本</span><span><b><?php echo e(CMS_VERSION); ?></b></span></div>
        <div class="kv"><span>仓库</span><span><?php echo $repo !== '' ? e($repo) : '未设置'; ?> <span class="badge badge-gray"><?php echo e($branch); ?></span></span></div>
        <div class="kv">
            <span>最新版本</span>
            <span>
                <?php if ($remoteVer === ''): ?>
                    <?php echo $remoteSha !== '' ? '未知（未读到版本号）' : '尚未检查'; ?>
                <?php else: ?>
                    <?php echo e($remoteVer); ?>
                    <?php if ($hasNew): ?><span class="badge badge-amber">可更新</span><?php else: ?><span class="badge badge-green">已是最新</span><?php endif; ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="kv"><span>最新提交</span><span><?php echo $remoteSha !== '' ? e(substr($remoteSha, 0, 7)) . ' · ' . e($remoteMsg) : '—'; ?></span></div>
        <div class="kv"><span>提交时间</span><span><?php echo $remoteAt ? e(date('Y-m-d H:i', $remoteAt)) : '—'; ?></span></div>
        <div class="kv"><span>上次检查</span><span><?php echo !empty($state['checked_at']) ? e(date('Y-m-d H:i', (int) $state['checked_at'])) : '从未'; ?></span></div>
        <div class="kv"><span>下次检查</span><span><?php
            if (!update_auto_check()) {
                echo '已关闭自动检查';
            } elseif ($nextCheck) {
                echo e(date('Y-m-d H:i', $nextCheck));
            } else {
                echo '下次打开后台时';
            }
        ?></span></div>
        <div class="kv"><span>自动检查</span><span><?php echo update_auto_check()
            ? '开启 · 每 ' . (int) setting('update_interval', '6') . ' 小时'
            : '<span class="badge badge-gray">已关闭</span>'; ?></span></div>
        <div class="kv"><span>邮件通知</span><span><?php echo $notifyTo !== ''
            ? e($notifyTo)
            : '<span class="badge badge-gray">未设置</span>'; ?></span></div>
        <div class="kv"><span>上次通知</span><span><?php echo $notifiedAt
            ? e(date('Y-m-d H:i', $notifiedAt)) . ($notifiedTo !== '' ? ' → ' . e($notifiedTo) : '')
            : '还没有发过'; ?></span></div>
        <?php if ($notifyErr !== ''): ?>
            <div class="alert alert-err" style="margin-top:10px">上次发信失败：<?php echo e($notifyErr); ?></div>
        <?php endif; ?>
        <div class="kv"><span>上次更新</span><span><?php echo !empty($state['last_update_at']) ? e(date('Y-m-d H:i', (int) $state['last_update_at'])) : '从未'; ?></span></div>

        <?php if ($hasNew): ?>
            <div style="display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap">
                <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="正在更新，请勿关闭页面…">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="apply">
                    <button class="btn btn-primary" type="submit">立即更新到 <?php echo $remoteVer !== '' ? e($remoteVer) : '最新提交'; ?></button>
                </form>
                <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="处理中…">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="skip">
                    <button class="btn" type="submit" data-confirm="忽略这次更新？以后仓库有新提交时会再提示。">忽略这次</button>
                </form>
            </div>
            <div class="hint" style="margin-top:10px">下载并覆盖需要几秒到几十秒，取决于服务器访问 GitHub 的速度。</div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>更新记录</h2></div>
    <?php if (empty($history)): ?>
        <div class="card-body"><div class="empty">还没有通过后台更新过</div></div>
    <?php else: ?>
        <div class="card-body tight">
            <table class="table">
                    <thead>
                        <tr><th style="width:150px">时间</th><th style="width:110px">版本</th><th>提交</th><th style="width:90px">文件数</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td class="hint"><?php echo e(date('Y-m-d H:i', (int) $h['at'])); ?></td>
                                <td><?php echo e($h['version']); ?></td>
                                <td class="hint"><code><?php echo e($h['sha']); ?></code></td>
                                <td class="hint"><?php echo (int) $h['files']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>更新设置</h2></div>
    <div class="card-body">
        <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="保存中…">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="save">

            <div class="grid2">
            <div class="form-row">
                <label for="update_repo">GitHub 仓库</label>
                <input class="input" type="text" id="update_repo" name="update_repo" value="<?php echo e($repo); ?>" placeholder="owner/repo">
                <div class="hint">只写 <code>owner/repo</code>，不要带 https:// 或 .git。</div>
            </div>
            <div class="form-row">
                <label for="update_branch">跟踪分支</label>
                <input class="input" type="text" id="update_branch" name="update_branch" value="<?php echo e($branch); ?>" placeholder="main">
            </div>
            <div class="form-row">
                <label for="update_interval">检查间隔（小时）</label>
                <input class="input" type="number" id="update_interval" name="update_interval" min="1" max="168" value="<?php echo (int) setting('update_interval', '6'); ?>">
            </div>
            <div class="form-row">
                <label for="update_notify_email">通知邮箱</label>
                <input class="input" type="email" id="update_notify_email" name="update_notify_email" value="<?php echo e((string) setting('update_notify_email', '')); ?>" placeholder="you@example.com">
                <div class="hint">发现新版本时发一封邮件，同一版本只发一次。留空就只在后台提示。</div>
            </div>
            </div>

            <div class="form-row" style="border-top:1px solid var(--line);padding-top:14px;margin-top:14px">
                <label for="mail_driver">发信方式</label>
                <select class="input" id="mail_driver" name="mail_driver">
                    <option value="mail" <?php echo $mailCfg['driver'] === 'mail' ? 'selected' : ''; ?>>PHP mail()（服务器自带）</option>
                    <option value="smtp" <?php echo $mailCfg['driver'] === 'smtp' ? 'selected' : ''; ?>>SMTP（推荐）</option>
                </select>
                <div class="hint">主机禁用了 <code>mail()</code>，或收不到信，就换成 SMTP。下面几项只在 SMTP 时用得上。</div>
            </div>

            <div id="smtp-box">
            <div class="form-row">
                <label for="mail_host">SMTP 服务器</label>
                <input class="input" type="text" id="mail_host" name="mail_host" value="<?php echo e($mailCfg['host']); ?>" placeholder="smtp.qq.com">
            </div>
            <div class="form-row" style="display:flex;gap:10px">
                <div style="flex:1">
                    <label for="mail_port">端口</label>
                    <input class="input" type="number" id="mail_port" name="mail_port" min="0" max="65535" value="<?php echo (int) $mailCfg['port']; ?>">
                </div>
                <div style="flex:1">
                    <label for="mail_secure">加密</label>
                    <select class="input" id="mail_secure" name="mail_secure">
                        <option value="ssl" <?php echo $mailCfg['secure'] === 'ssl' ? 'selected' : ''; ?>>SSL（465）</option>
                        <option value="tls" <?php echo $mailCfg['secure'] === 'tls' ? 'selected' : ''; ?>>STARTTLS（587）</option>
                        <option value="none" <?php echo $mailCfg['secure'] === 'none' ? 'selected' : ''; ?>>不加密（25）</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label for="mail_user">SMTP 账号</label>
                <input class="input" type="text" id="mail_user" name="mail_user" value="<?php echo e($mailCfg['user']); ?>" placeholder="you@qq.com" autocomplete="off">
                <div class="hint">通常是完整邮箱地址。</div>
            </div>
            <div class="form-row">
                <label for="mail_pass">授权码 / 密码</label>
                <input class="input" type="password" id="mail_pass" name="mail_pass" value="" autocomplete="new-password"
                       placeholder="<?php echo $hasPass ? '已设置，留空表示不修改' : '邮箱的 SMTP 授权码'; ?>">
                <div class="hint">QQ / 163 要的是「授权码」，不是登录密码，在邮箱设置里开启 SMTP 后生成。留空表示沿用已保存的值。</div>
            </div>
            <div class="form-row">
                <label for="mail_from">发件邮箱</label>
                <input class="input" type="email" id="mail_from" name="mail_from" value="<?php echo e($mailCfg['from']); ?>" placeholder="留空就用 SMTP 账号">
                <div class="hint">多数邮箱要求发件人和登录账号一致，不确定就留空。</div>
            </div>
            <div class="form-row">
                <label for="mail_from_name">发件人名称</label>
                <input class="input" type="text" id="mail_from_name" name="mail_from_name" value="<?php echo e((string) setting('mail_from_name', 'CiciCMS')); ?>" placeholder="CiciCMS">
            </div>
            <label class="check-row" style="display:flex;gap:8px;align-items:flex-start;margin:10px 0">
                <input type="checkbox" name="mail_verify" value="1" <?php echo $mailCfg['verify'] ? 'checked' : ''; ?> style="margin-top:3px">
                <span>
                    <b style="font-weight:600">校验证书</b>
                    <span class="hint" style="display:block">服务器证书过期或自签时才取消勾选，平时保持勾选。</span>
                </span>
            </label>

            <details style="margin:10px 0">
                <summary class="hint" style="cursor:pointer">常用邮箱参数（点开）</summary>
                <table class="table" style="margin-top:8px;font-size:12px">
                    <tr><th>邮箱</th><th>服务器</th><th>端口</th><th>加密</th></tr>
                    <tr><td>QQ / 腾讯企业邮</td><td><code>smtp.qq.com</code></td><td>465</td><td>SSL</td></tr>
                    <tr><td>163 / 126</td><td><code>smtp.163.com</code></td><td>465</td><td>SSL</td></tr>
                    <tr><td>阿里企业邮</td><td><code>smtp.qiye.aliyun.com</code></td><td>465</td><td>SSL</td></tr>
                    <tr><td>Gmail</td><td><code>smtp.gmail.com</code></td><td>587</td><td>STARTTLS</td></tr>
                    <tr><td>Outlook</td><td><code>smtp.office365.com</code></td><td>587</td><td>STARTTLS</td></tr>
                </table>
                <div class="hint" style="margin-top:6px">账号填完整邮箱；密码一栏填授权码。QQ 邮箱在「设置 → 账号 → POP3/IMAP/SMTP 服务」里生成。</div>
            </details>
            </div>

            <label class="check-row" style="display:flex;gap:8px;align-items:flex-start;margin:10px 0">
                <input type="checkbox" name="update_auto_check" value="1" <?php echo update_auto_check() ? 'checked' : ''; ?> style="margin-top:3px">
                <span>
                    <b style="font-weight:600">自动检查</b>
                    <span class="hint" style="display:block">打开后台任意页面时静默检查一次，有新版本会在菜单上标红点。</span>
                </span>
            </label>

            <label class="check-row" style="display:flex;gap:8px;align-items:flex-start;margin:10px 0">
                <input type="checkbox" name="update_auto_apply" value="1" <?php echo update_auto_apply() ? 'checked' : ''; ?> style="margin-top:3px">
                <span>
                    <b style="font-weight:600">自动更新</b>
                    <span class="hint" style="display:block">发现新版本就直接安装，不再问。仓库只有你能推代码时才建议开启。</span>
                </span>
            </label>

            <button class="btn btn-primary btn-block" type="submit">保存设置</button>
        </form>

        <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="发送中…" style="margin-top:10px">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="testmail">
            <button class="btn btn-sm btn-block" type="submit" <?php echo $notifyTo === '' ? 'disabled' : ''; ?>>发一封测试邮件</button>
        </form>
        <div class="hint" style="margin-top:8px">
            当前发信方式：<b><?php echo $mailCfg['driver'] === 'smtp' ? 'SMTP' : 'PHP mail()'; ?></b><?php
            echo $mailCfg['driver'] === 'smtp' && $mailCfg['host'] !== ''
                ? '（' . e($mailCfg['host']) . ':' . (int) $mailCfg['port'] . '）'
                : ''; ?>。
            改完设置先点这里试一封，成功再去等自动通知。
        </div>
        <script>
        (function () {
            var sel = document.getElementById('mail_driver');
            var box = document.getElementById('smtp-box');
            if (!sel || !box) { return; }
            function sync() { box.style.display = sel.value === 'smtp' ? '' : 'none'; }
            sel.addEventListener('change', sync);
            sync();
        })();
        </script>
    </div>
</div>

<div class="grid2 cards">
<div class="card">
    <div class="card-head"><h2>无人值守</h2></div>
    <div class="card-body">
        <p class="hint" style="margin-top:0;line-height:1.8">
            后台不开也能查：把下面这个地址加到服务器的计划任务（宝塔 / 1Panel / crontab），
            到点由服务器自己来访问，结果会写进状态并发出邮件。
        </p>
        <input class="input" type="text" id="cron-url" value="<?php echo e($cronUrl); ?>" readonly onclick="this.select()" style="font-size:11.5px">
        <div style="display:flex;gap:8px;margin-top:8px">
            <button class="btn btn-sm" type="button" data-copy="#cron-url">复制地址</button>
            <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="重置中…">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="rotate">
                <button class="btn btn-sm" type="submit" data-confirm="重置后旧地址立即失效，服务器上的计划任务要同步修改。继续？">重置令牌</button>
            </form>
        </div>
        <div class="hint" style="margin-top:10px;line-height:1.8">
            命令示例：<br>
            <code>curl -s "上面这个地址" &gt;/dev/null</code><br>
            建议每 6 小时跑一次，和上面的检查间隔保持一致。
        </div>
    </div>
</div>
<div class="card">
    <div class="card-head"><h2>更新了哪些东西</h2></div>
    <div class="card-body">
        <div class="kv"><span>会覆盖</span><span class="path-tags"><code>includes/</code><code>admin/</code><code>index.php</code><code>api.php</code><code>.htaccess</code></span></div>
        <div class="kv"><span>绝不动</span><span class="path-tags"><code>content/</code><code>config.php</code><code>install/</code></span></div>
        <div class="kv"><span>失败处理</span><span>整批回滚到更新前</span></div>
        <div class="hint" style="margin-top:10px">
            更新只换程序文件，数据库结构由程序下次访问时自行升级；静态页需要重新生成一次。
        </div>
    </div>
</div>
</div>

<div class="card">
    <div class="card-head"><h2>备份与回滚</h2></div>
    <div class="card-body">
        <?php if (empty($backups)): ?>
            <div class="empty" style="padding:26px 0">更新前会自动备份到这个列表</div>
        <?php else: ?>
            <?php foreach ($backups as $b): ?>
                <div style="padding:8px 0;border-bottom:1px solid var(--line)">
                    <div style="font-size:12.5px"><?php echo e($b['name']); ?></div>
                    <div class="hint" style="margin:3px 0 7px"><?php echo e(date('Y-m-d H:i', $b['time'])); ?></div>
                    <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="回滚中…">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="do" value="rollback">
                        <input type="hidden" name="name" value="<?php echo e($b['name']); ?>">
                        <button class="btn btn-sm" type="submit" data-confirm="用这份备份覆盖当前程序文件？">回滚到这一份</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
