<?php if (!defined('CMS_ADMIN')) { http_response_code(404); exit; } ?>
    <div class="zb-footer">
        <?php echo e(setting('site_name', 'CiciCMS')); ?> v<?php echo e(CMS_VERSION); ?>
        &nbsp;·&nbsp; 已生成 <?php echo (int) static_files_count(); ?> 个静态文件
        &nbsp;·&nbsp; <a href="<?php echo e(admin_url('build')); ?>">重新生成</a>
    </div>
    </div><!-- /#divMain -->
</section><!-- /.main -->
<script src="<?php echo e(site_url('admin/assets/admin.js')); ?>?v=<?php echo (int) @filemtime(CMS_ROOT . '/admin/assets/admin.js'); ?>"></script>
<?php
/* 自动更新检查：到点才发一次请求；发现新版且开了「自动更新」，就在那次请求里装完 */
$upDue = update_auto_due();
if ($upDue):
?>
<script>
(function () {
    function showBadge(text) {
        var bar = document.getElementById('topmenu');
        if (!bar || bar.querySelector('.zb-update')) { return; }
        var li = document.createElement('li');
        li.className = 'zb-update';
        var a = document.createElement('a');
        a.href = <?php echo json_encode(admin_url('update')); ?>;
        a.innerHTML = '<i class="icon-cloud-download"></i><span></span>';
        a.querySelector('span').textContent = text;
        li.appendChild(a);
        bar.appendChild(li);
    }

    var fd = new FormData();
    fd.append('_token', <?php echo json_encode(csrf_token()); ?>);
    fetch(<?php echo json_encode(admin_url('update', array('action' => 'tick'))); ?>, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).then(function (r) {
        return r.json();
    }).then(function (d) {
        if (!d || !d.ok) { return; }
        if (d.applied) {
            if (window.cmsToast) {
                cmsToast('已自动更新到 ' + d.version + '，刷新页面后生效', { sticky: true });
            }
            return;
        }
        if (d.has_update) {
            showBadge('发现新版本' + (d.version ? ' ' + d.version : ''));
            if (window.cmsToast) {
                cmsToast('发现新版本' + (d.version ? ' ' + d.version : '') + '，点击去更新', {
                    sticky: true,
                    href: <?php echo json_encode(admin_url('update')); ?>
                });
            }
        }
    }).catch(function () { /* 网络不通就安静收场，下次到点再试 */ });
})();
</script>
<?php endif; ?>
<?php
/* 有没跑完的静态生成任务时，在后台任意页面静默续跑 */
$bpPending = (isset($_GET['p']) && $_GET['p'] === 'build') ? null : build_pending();
if (!empty($bpPending)):
?>
<div id="bg-build" class="zb-float">
    <span id="bg-build-dot" class="zb-float-dot"></span>
    <span id="bg-build-text">正在生成静态页 <?php echo (int) $bpPending['pos']; ?>/<?php echo (int) $bpPending['total']; ?></span>
</div>
<script>
(function () {
    var url   = <?php echo json_encode(admin_url('build', array('action' => 'tick'))); ?>;
    var token = <?php echo json_encode(csrf_token()); ?>;
    var box   = document.getElementById('bg-build');
    var txt   = document.getElementById('bg-build-text');
    var dot   = document.getElementById('bg-build-dot');
    var stop  = false;

    function step() {
        if (stop) { return; }
        var fd = new FormData();
        fd.append('_token', token);
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.errors && d.errors.length) {
                    txt.textContent = '静态生成出错：' + d.errors[0];
                    txt.style.color = '#d60000';
                    dot.style.background = '#d60000';
                    stop = true;
                    return;
                }
                txt.textContent = '正在生成静态页 ' + (d.pos || 0) + '/' + (d.total || 0);
                if (d.finished) {
                    stop = true;
                    txt.textContent = '静态页已全部更新（' + (d.files || 0) + ' 个文件）';
                    txt.style.color = '#277723';
                    dot.style.background = '#277723';
                    setTimeout(function () { box.style.display = 'none'; }, 2500);
                    return;
                }
                setTimeout(step, 60);
            })
            .catch(function () { stop = true; box.style.display = 'none'; });
    }

    step();
})();
</script>
<?php endif; ?>
<?php cms_do_action('admin_foot'); ?>
</body>
</html>
