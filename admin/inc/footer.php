<?php if (!defined('CMS_ADMIN')) { http_response_code(404); exit; } ?>
        </div>
    </div>
</div>
<script src="<?php echo e(site_url('admin/assets/admin.js')); ?>"></script>
<?php
/* 有没跑完的静态生成任务时，在后台任意页面静默续跑，
   这样保存内容后不用盯着进度条，也不用担心任务半途停下。 */
$bpPending = (isset($_GET['p']) && $_GET['p'] === 'build') ? null : build_pending();
if (!empty($bpPending)):
?>
<div id="bg-build" style="position:fixed;right:20px;bottom:20px;z-index:9999;background:#fff;color:#17171C;padding:12px 18px;border-radius:999px;font-size:12.5px;box-shadow:0 10px 30px rgba(17,24,39,.14);display:flex;align-items:center;gap:9px">
    <span id="bg-build-dot" style="width:7px;height:7px;border-radius:50%;background:#2563EB;flex:0 0 7px"></span>
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
                    txt.style.color = '#C0392B';
                    dot.style.background = '#C0392B';
                    stop = true;
                    return;
                }
                txt.textContent = '正在生成静态页 ' + (d.pos || 0) + '/' + (d.total || 0);
                if (d.finished) {
                    stop = true;
                    txt.textContent = '静态页已全部更新（' + (d.files || 0) + ' 个文件）';
                    txt.style.color = '#0B8A4B';
                    dot.style.background = '#0B8A4B';
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
