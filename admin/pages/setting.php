<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 系统设置：站点信息 / 管理员密码 / 目录权限 / 系统信息（数据备份在「数据系统」） */

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
}
?>
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

<div class="card" id="perms">
    <div class="card-head">
        <h2>目录权限自检</h2>
        <span class="hint">部署时看一眼，之后不必常查</span>
    </div>
    <div class="card-body tight">
        <table class="table">
            <tr>
                <th style="width:120px">目录</th>
                <th>说明</th>
                <th style="width:80px;white-space:nowrap">期望</th>
                <th style="width:90px;text-align:right">实际</th>
            </tr>
            <?php foreach (cms_permission_audit() as $row): ?>
                <tr>
                    <td><?php echo e($row['label']); ?></td>
                    <td class="hint"><?php echo e($row['note']); ?></td>
                    <td class="hint" style="white-space:nowrap"><?php echo e($row['want']); ?></td>
                    <td style="white-space:nowrap;text-align:right">
                        <?php if ($row['ok']): ?>
                            <span class="badge badge-green"><?php echo e($row['actual']); ?></span>
                        <?php else: ?>
                            <span class="badge badge-amber"><?php echo e($row['actual']); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="card-foot hint" style="line-height:1.9">
        代码区只执行不写入，内容区只写入不执行，黄色项是部署建议。<br>
        <code style="background:var(--card);border-radius:4px;padding:1px 5px">chmod -R a-w includes admin</code><br>
        只去写位，目录要保留 <code style="background:var(--card);border-radius:4px;padding:1px 5px">r-x</code>，否则标签注册表扫不到文件，前台会白屏。
    </div>
</div>

<div class="grid2 cards">
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
<div class="card" id="sysinfo">
    <div class="card-head"><h2>系统信息</h2></div>
    <div class="card-body">
        <div class="kv"><span>程序版本</span><span>CiciCMS <?php echo e(CMS_VERSION); ?></span></div>
        <div class="kv"><span>PHP</span><span><?php echo e(PHP_VERSION); ?></span></div>
        <div class="kv"><span>MySQL</span><span><?php echo e((string) val('SELECT VERSION()')); ?></span></div>
        <div class="kv"><span>当前模板</span><span><?php echo e(theme_name()); ?></span></div>
        <div class="kv"><span>自动生成</span><span><?php echo setting('auto_build', '1') === '1' ? '开启' : '关闭'; ?></span></div>
        <div class="kv"><span>数据库主机</span><span><?php echo e(DB_HOST . ':' . DB_PORT); ?></span></div>
        <div class="kv"><span>数据库名</span><span><?php echo e(DB_NAME); ?></span></div>
        <div class="kv"><span>表前缀</span><span><?php echo e(DB_PREFIX); ?></span></div>
        <div class="kv"><span>字符集</span><span><?php echo e(DB_CHARSET); ?></span></div>
    </div>
</div>
</div>

<div class="card">
    <div class="card-body">
        <div class="hint" style="line-height:1.9">
            安全建议：<br>
            · 安装后删除 install 目录<br>
            · config.php 权限设为 644<br>
            · 定期到「数据系统」生成并下载备份
        </div>
    </div>
</div>
