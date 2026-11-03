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
<div id="bg-build" style="position:fixed;right:18px;bottom:18px;z-index:9999;background:#1f2733;color:#fff;padding:10px 14px;border-radius:8px;font-size:13px;box-shadow:0 6px 20px rgba(0,0,0,.25)">
    <span id="bg-build-text">正在生成静态页 <?php echo (int) $bpPending['pos']; ?>/<?php echo (int) $bpPending['total']; ?></span>
</div>
<script>
(function () {
    var url   = <?php echo json_encode(admin_url('build', array('action' => 'tick'))); ?>;
    var token = <?php echo json_encode(csrf_token()); ?>;
    var box   = document.getElementById('bg-build');
    var txt   = document.getElementById('bg-build-text');
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
                    box.style.background = '#b52b27';
                    stop = true;
                    return;
                }
                txt.textContent = '正在生成静态页 ' + (d.pos || 0) + '/' + (d.total || 0);
                if (d.finished) {
                    stop = true;
                    box.style.background = '#1f7a3d';
                    txt.textContent = '静态页已全部更新（' + (d.files || 0) + ' 个文件）';
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
</body>
</html>
