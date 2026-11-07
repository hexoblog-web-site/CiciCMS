<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 生成静态 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'build') {
        // 上一轮全量还没跑完就接着跑，不要从零重来
        $st     = build_read_state();
        $resume = $st && $st['mode'] === 'full' && (int) $st['pos'] < count($st['plan']);
        if (!$resume) {
            $r = build_start('full', build_plan_all());
            if (!$r['ok']) {
                flash($r['msg'], 'err');
                redirect(admin_url('build'));
            }
        }
        redirect(admin_url('build', array('building' => 1)));
    }

    if ($do === 'clean') {
        $n = static_clean();
        flash('已删除 ' . (int) $n . ' 个静态文件（数据库内容不受影响）');
        redirect(admin_url('build'));
    }
}

$pending = build_pending();
$autoRun = (get_str('building') === '1') || !empty($pending);

$lastBuild = (int) setting('last_build', 0);
?>
<div class="card">
    <div class="card-head">
        <h2>静态发布</h2>
        <form method="post" action="<?php echo e(admin_url('build')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="build">
            <button class="btn btn-primary" type="submit">立即生成全部静态页</button>
        </form>
    </div>
    <div class="card-body">
        <div class="kv"><span>上次生成</span><span><?php echo $lastBuild ? e(date('Y-m-d H:i:s', $lastBuild)) : '尚未生成'; ?></span></div>
        <div class="kv"><span>文件数量</span><span><?php echo (int) setting('last_build_files', 0); ?> 个</span></div>
        <div class="kv"><span>耗时</span><span><?php echo (int) setting('last_build_ms', 0); ?> ms</span></div>
        <div class="kv"><span>输出目录</span><span><?php echo e(CMS_ROOT); ?>（站点根目录）</span></div>
        <div class="kv"><span>自动生成</span><span><?php echo setting('auto_build', '1') === '1' ? '已开启（保存内容后自动重建）' : '已关闭'; ?></span></div>
        <?php if (!is_writable(CMS_ROOT)): ?>
            <div class="alert alert-err" style="margin-top:12px">站点根目录不可写，无法生成静态文件。请将目录权限设置为 755（属主为 PHP 运行用户）。</div>
        <?php endif; ?>
        <div id="build-progress" style="display:none;margin-top:14px">
            <div class="kv"><span id="bp-text">准备中…</span><span id="bp-pct">0%</span></div>
            <div style="height:8px;background:var(--card-soft);border-radius:999px;margin-top:8px;overflow:hidden">
                <div id="bp-bar" style="height:100%;width:0;background:var(--brand);border-radius:999px;transition:width .25s"></div>
            </div>
            <div class="hint" style="margin-top:8px">内容较多时会自动分批执行，请保持本页面打开，不要关闭。</div>
        </div>
        <?php if (!empty($pending) && !$autoRun): ?>
            <div class="alert alert-err" style="margin-top:12px">
                上一次生成还有 <?php echo (int) ($pending['total'] - $pending['pos']); ?> 项没有完成。
                <a href="<?php echo e(admin_url('build', array('building' => 1))); ?>">点此继续</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>删除静态</h2></div>
    <div class="card-body build-maint">
        <p class="hint">清空静态文件只删除磁盘上的 HTML/XML，不会动数据库。清空后前台所有页面将 404，需要重新生成。</p>
        <form method="post" action="<?php echo e(admin_url('build')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="clean">
            <button class="btn btn-danger" type="submit" data-confirm="确定清空所有已生成的静态文件？">清空静态文件</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Nginx 部署</h2></div>
    <div class="card-body deploy-note">
        <p class="hint">
            生成的是真静态文件，<b>本程序不做伪静态</b>，也不需要任何 rewrite 规则；站点配置里加一行
            <code>index index.html index.php;</code> 让静态首页优先即可。发布后可将生成的静态文件同步到 CDN。
        </p>
        <p class="hint">
            Apache 下 <code>content/data</code> 等私有目录由 .htaccess 保护，<b>Nginx 不读 .htaccess</b>，
            必须在站点配置里手动禁止访问，否则备份文件和日志可能被直接下载。
        </p>
        <pre class="code-block"># 系统目录与内容区里的私有目录，一律拒绝直连
location ~ ^/(includes|admin/inc|install)/ {
    deny all;
    return 404;
}
location ~ ^/content/(data|uploads/.*\.(php|phtml|phar)) {
    deny all;
    return 404;
}
# 附件目录只允许静态文件，禁止执行脚本
location ~* ^/content/uploads/.*\.(php|phtml|phar|pht)$ {
    deny all;
    return 404;
}</pre>
        <p class="hint">改完执行 <code>nginx -t &amp;&amp; nginx -s reload</code>。装好后建议删除 install 目录。</p>
    </div>
</div>
<?php if ($autoRun): ?>
<script>
(function () {
    var box = document.getElementById('build-progress');
    var bar = document.getElementById('bp-bar');
    var txt = document.getElementById('bp-text');
    var pct = document.getElementById('bp-pct');
    if (!box) { return; }
    box.style.display = 'block';

    var url   = <?php echo json_encode(admin_url('build', array('action' => 'tick'))); ?>;
    var token = <?php echo json_encode(csrf_token()); ?>;
    var done  = false;

    function render(d) {
        var total = d.total || 0;
        var pos   = d.pos || 0;
        var p     = total ? Math.round(pos / total * 100) : 0;
        bar.style.width = p + '%';
        pct.textContent = p + '%';
        txt.textContent = '正在生成：' + pos + ' / ' + total + ' 个页面';
    }

    function step() {
        if (done) { return; }
        var fd = new FormData();
        fd.append('_token', token);
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.errors && d.errors.length) {
                    txt.textContent = '生成出错：' + d.errors.join('；');
                    bar.style.background = '#d9534f';
                    done = true;
                    return;
                }
                render(d);
                if (d.finished) {
                    done = true;
                    bar.style.width = '100%';
                    pct.textContent = '100%';
                    txt.textContent = '生成完成，共 ' + (d.files || 0) + ' 个文件';
                    setTimeout(function () { window.location.href = <?php echo json_encode(admin_url('build')); ?>; }, 800);
                    return;
                }
                if (d.busy) {
                    txt.textContent = '另一个生成任务正在运行，稍后自动重试…';
                }
                setTimeout(step, 60);
            })
            .catch(function (e) {
                txt.textContent = '生成中断：' + e.message + '（可刷新页面继续）';
                bar.style.background = '#d9534f';
                done = true;
            });
    }

    step();
})();
</script>
<?php endif; ?>
