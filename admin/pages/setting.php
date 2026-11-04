<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 系统设置：站点信息 / 管理员密码 / 数据库备份 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'site') {
        $fields = array('site_name', 'site_url', 'site_desc', 'site_keywords', 'site_icp', 'site_footer', 'timezone');
        foreach ($fields as $f) {
            $v = post_str($f);
            if ($f === 'site_url') {
                $v = rtrim($v, '/');
                if ($v !== '' && !preg_match('#^https?://#i', $v)) {
                    flash('站点地址需以 http:// 或 https:// 开头', 'err');
                    redirect(admin_url('setting'));
                }
            }
            set_setting($f, $v);
        }
        $per = (int) post_str('per_page', '10');
        set_setting('per_page', (string) max(1, min(100, $per)));
        set_setting('auto_build', post_str('auto_build', '0') === '1' ? '1' : '0');
        set_setting('trust_proxy', post_str('trust_proxy', '0') === '1' ? '1' : '0');

        if (post_str('auto_build', '0') === '1') {
            build_all(8.0);
        }
        flash('站点设置已保存');
        redirect(admin_url('setting'));
    }

    if ($do === 'password') {
        $old  = post_str('oldpass');
        $new  = post_str('newpass');
        $new2 = post_str('newpass2');
        $me   = auth_user();
        $row  = one('SELECT * FROM {p}user WHERE id = ?', array((int) $me['id']));

        if (!$row || !password_verify($old, $row['password'])) {
            flash('当前密码不正确', 'err');
        } elseif (strlen($new) < 6) {
            flash('新密码至少 6 位', 'err');
        } elseif ($new !== $new2) {
            flash('两次输入的新密码不一致', 'err');
        } else {
            update_row('user', array('password' => password_hash($new, PASSWORD_DEFAULT)), 'id = ?', array((int) $row['id']));
            flash('管理员密码已更新');
        }
        redirect(admin_url('setting'));
    }

    if ($do === 'backup') {
        $name = db_backup();
        if ($name) {
            flash('备份已生成：' . $name);
        } else {
            flash('备份失败，请检查 content/data/backup 目录权限', 'err');
        }
        redirect(admin_url('setting') . '#backup');
    }

    if ($do === 'backup_delete') {
        $name = post_str('file');
        if (db_backup_delete($name)) {
            flash('已删除备份：' . $name);
        } else {
            flash('删除失败，文件可能已经不存在', 'err');
        }
        redirect(admin_url('setting') . '#backup');
    }

    /* 从已有备份恢复：必须手打 RESTORE，防止误点 */
    if ($do === 'restore') {
        if (post_str('confirm') !== 'RESTORE') {
            flash('请输入 RESTORE 确认恢复，本次未执行', 'err');
            redirect(admin_url('setting') . '#backup');
        }
        $path = db_backup_find(post_str('file'));
        if ($path === '') {
            flash('备份文件不存在', 'err');
            redirect(admin_url('setting') . '#backup');
        }
        $info = array();
        $ok   = db_restore_file($path, $info);
        if ($ok) {
            flash('恢复完成：执行 ' . (int) $info['executed'] . ' 条语句'
                . ($info['skipped'] ? '，忽略 ' . (int) $info['skipped'] . ' 条非备份语句' : '')
                . ($info['backup'] !== '' ? '；恢复前的数据已备份为 ' . $info['backup'] : ''));
        } else {
            flash($info['error'] !== '' ? $info['error'] : '恢复失败', 'err');
        }
        redirect(admin_url('setting') . '#backup');
    }

    /* 上传 .sql 恢复 */
    if ($do === 'restore_upload') {
        if (post_str('confirm') !== 'RESTORE') {
            flash('请输入 RESTORE 确认恢复，本次未执行', 'err');
            redirect(admin_url('setting') . '#backup');
        }
        $f = isset($_FILES['sql']) ? $_FILES['sql'] : null;
        if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('没有收到 SQL 文件', 'err');
            redirect(admin_url('setting') . '#backup');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'sql') {
            flash('只接受 .sql 备份文件', 'err');
            redirect(admin_url('setting') . '#backup');
        }
        $info = array();
        $ok   = db_restore_file($f['tmp_name'], $info);
        @unlink($f['tmp_name']);
        if ($ok) {
            flash('恢复完成：执行 ' . (int) $info['executed'] . ' 条语句'
                . ($info['backup'] !== '' ? '；恢复前的数据已备份为 ' . $info['backup'] : ''));
        } else {
            flash($info['error'] !== '' ? $info['error'] : '恢复失败', 'err');
        }
        redirect(admin_url('setting') . '#backup');
    }
}

$backups = db_backup_list();
?>
<div class="edit-cols">
    <div>
        <div class="card">
            <div class="card-head"><h2>站点信息</h2></div>
            <div class="card-body">
                <form method="post" action="<?php echo e(admin_url('setting')); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="site">
                    <div class="grid2">
                        <div class="form-row">
                            <label for="site_name">网站名称</label>
                            <input class="input" type="text" id="site_name" name="site_name" value="<?php echo e(setting('site_name')); ?>" required>
                        </div>
                        <div class="form-row">
                            <label for="site_url">站点地址</label>
                            <input class="input" type="text" id="site_url" name="site_url" value="<?php echo e(setting('site_url')); ?>" required>
                            <div class="hint">静态页面中的所有链接以此为准，末尾不要加斜杠</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="site_desc">网站描述</label>
                        <input class="input" type="text" id="site_desc" name="site_desc" value="<?php echo e(setting('site_desc')); ?>">
                    </div>
                    <div class="form-row">
                        <label for="site_keywords">关键词（SEO）</label>
                        <input class="input" type="text" id="site_keywords" name="site_keywords" value="<?php echo e(setting('site_keywords')); ?>">
                    </div>
                    <div class="grid3">
                        <div class="form-row">
                            <label for="per_page">每页文章数</label>
                            <input class="input" type="number" id="per_page" name="per_page" min="1" max="100" value="<?php echo (int) setting('per_page', 10); ?>">
                        </div>
                        <div class="form-row">
                            <label for="site_icp">ICP 备案号</label>
                            <input class="input" type="text" id="site_icp" name="site_icp" value="<?php echo e(setting('site_icp')); ?>">
                        </div>
                        <div class="form-row">
                            <label for="timezone">时区</label>
                            <select class="input" id="timezone" name="timezone">
                                <?php foreach (array('Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Taipei', 'UTC') as $tz): ?>
                                    <option value="<?php echo e($tz); ?>" <?php echo setting('timezone', 'Asia/Shanghai') === $tz ? 'selected' : ''; ?>><?php echo e($tz); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="site_footer">页脚代码（支持 HTML）</label>
                        <textarea class="input" id="site_footer" name="site_footer" style="min-height:80px"><?php echo e(setting('site_footer')); ?></textarea>
                    </div>
                    <div class="form-row">
                        <label class="inline" style="font-weight:400">
                            <input class="check" type="checkbox" name="auto_build" value="1" <?php echo setting('auto_build', '1') === '1' ? 'checked' : ''; ?>>
                            保存内容后自动重新生成静态页
                        </label>
                        <div class="hint">文章较多时可关闭，改为手动批量生成以提升保存速度。</div>
                    </div>
                    <div class="form-row">
                        <label class="inline" style="font-weight:400">
                            <input class="check" type="checkbox" name="trust_proxy" value="1" <?php echo setting('trust_proxy', '0') === '1' ? 'checked' : ''; ?>>
                            站点在 CDN / 反向代理后面（采信 X-Forwarded-For）
                        </label>
                        <div class="hint">开启后按真实访客 IP 限制登录失败次数。没有走 CDN 时不要开，否则访客可以伪造该请求头绕过限速。</div>
                    </div>
                    <button class="btn btn-primary" type="submit">保存设置</button>
                </form>
            </div>
        </div>

        <div class="card" id="backup">
            <div class="card-head"><h2>数据库备份</h2></div>
            <div class="card-body">
                <p class="hint" style="margin-top:0">备份文件保存在 content/data/backup 目录，包含全部内容表，可用于迁移或恢复。</p>
                <form method="post" action="<?php echo e(admin_url('setting')); ?>" style="margin-bottom:14px">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="backup">
                    <button class="btn" type="submit">立即备份数据库</button>
                </form>
                <?php if (empty($backups)): ?>
                    <div class="hint">暂无备份文件</div>
                <?php else: ?>
                    <table class="table">
                        <?php foreach (array_slice($backups, 0, 10) as $b): ?>
                            <tr>
                                <td>
                                    <?php echo e($b); ?>
                                    <div class="hint" style="font-size:11px"><?php echo e(db_backup_size($b) > 1048576
                                        ? round(db_backup_size($b) / 1048576, 1) . ' MB'
                                        : round(db_backup_size($b) / 1024) . ' KB'); ?></div>
                                </td>
                                <td class="actions-cell" style="white-space:nowrap">
                                    <a class="btn btn-sm" href="download.php?f=<?php echo e(urlencode($b)); ?>">下载</a>
                                    <form method="post" action="<?php echo e(admin_url('setting')); ?>" style="display:inline"
                                          onsubmit="return confirm('确定删除这个备份文件？');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="do" value="backup_delete">
                                        <input type="hidden" name="file" value="<?php echo e($b); ?>">
                                        <button class="btn btn-sm" type="submit">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>

                    <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:14px">
                        <h3 style="margin:0 0 8px;font-size:13px">从备份恢复</h3>
                        <p class="hint" style="margin:0 0 10px;line-height:1.8">
                            恢复会用备份内容<strong>覆盖现有全部内容表</strong>，执行前会自动再备份一次当前数据。
                            输入 <code>RESTORE</code> 后才能执行。
                        </p>
                        <form method="post" action="<?php echo e(admin_url('setting')); ?>" class="form-row">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="do" value="restore">
                            <select class="input" name="file">
                                <?php foreach (array_slice($backups, 0, 30) as $b): ?>
                                    <option value="<?php echo e($b); ?>"><?php echo e($b); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input class="input" name="confirm" placeholder="输入 RESTORE 确认" style="margin-top:8px">
                            <button class="btn" type="submit" style="margin-top:10px">恢复这个备份</button>
                        </form>
                    </div>
                <?php endif; ?>

                <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:14px">
                    <h3 style="margin:0 0 8px;font-size:13px">上传 SQL 恢复</h3>
                    <form method="post" action="<?php echo e(admin_url('setting')); ?>" enctype="multipart/form-data" class="form-row">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="do" value="restore_upload">
                        <input class="input" type="file" name="sql" accept=".sql">
                        <input class="input" name="confirm" placeholder="输入 RESTORE 确认" style="margin-top:8px">
                        <div class="hint">只接受本程序生成的备份（文件头必须是 CiciCMS 数据备份），上限 64MB。</div>
                        <button class="btn" type="submit" style="margin-top:10px">上传并恢复</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="edit-side">
        <div class="card">
            <div class="card-head"><h2>修改密码</h2></div>
            <div class="card-body">
                <form method="post" action="<?php echo e(admin_url('setting')); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="password">
                    <div class="form-row">
                        <label for="oldpass">当前密码</label>
                        <input class="input" type="password" id="oldpass" name="oldpass" required>
                    </div>
                    <div class="form-row">
                        <label for="newpass">新密码</label>
                        <input class="input" type="password" id="newpass" name="newpass" required>
                    </div>
                    <div class="form-row">
                        <label for="newpass2">确认新密码</label>
                        <input class="input" type="password" id="newpass2" name="newpass2" required>
                    </div>
                    <button class="btn btn-primary btn-block" type="submit">更新密码</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>数据库信息</h2></div>
            <div class="card-body">
                <div class="kv"><span>主机</span><span><?php echo e(DB_HOST . ':' . DB_PORT); ?></span></div>
                <div class="kv"><span>数据库</span><span><?php echo e(DB_NAME); ?></span></div>
                <div class="kv"><span>表前缀</span><span><?php echo e(DB_PREFIX); ?></span></div>
                <div class="kv"><span>字符集</span><span><?php echo e(DB_CHARSET); ?></span></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="hint" style="line-height:1.9">
                    安全建议：<br>
                    · 安装后删除 install 目录<br>
                    · config.php 权限设为 644<br>
                    · 定期下载数据库备份
                </div>
            </div>
        </div>
    </div>
</div>
