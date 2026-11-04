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

        set_setting('update_repo', $repo);
        set_setting('update_branch', $branch);
        set_setting('update_interval', (string) max(1, min(168, $interval)));
        set_setting('update_auto_check', post_str('update_auto_check', '0') === '1' ? '1' : '0');
        set_setting('update_auto_apply', post_str('update_auto_apply', '0') === '1' ? '1' : '0');
        log_msg('update settings saved: ' . $repo . '@' . $branch);

        flash('更新设置已保存');
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
?>
<?php if ($hasNew): ?>
    <div class="alert alert-warn">
        发现新版本<?php echo $remoteVer !== '' ? '（' . e($remoteVer) . '）' : ''; ?>，当前是 <?php echo e(CMS_VERSION); ?>。更新前会自动备份，失败会自动回滚。
    </div>
<?php endif; ?>
<?php if ($lastErr !== '' && !$hasNew): ?>
    <div class="alert alert-err">上次检查失败：<?php echo e($lastErr); ?></div>
<?php endif; ?>

<div class="edit-cols">
    <div>
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
    </div>

    <div class="edit-side">
        <div class="card">
            <div class="card-head"><h2>更新设置</h2></div>
            <div class="card-body">
                <form method="post" action="<?php echo e(admin_url('update')); ?>" data-busy="保存中…">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="save">

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
</div>
