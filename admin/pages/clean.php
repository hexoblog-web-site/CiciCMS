<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 系统清理：列出没有被任何内容引用的图片与附件，确认后删除 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'delete') {
        $paths = isset($_POST['files']) ? (array) $_POST['files'] : array();
        $paths = array_values(array_filter(array_map('strval', $paths), 'strlen'));
        if (empty($paths)) {
            flash('没有选中任何文件', 'err');
            redirect(admin_url('clean'));
        }

        $r   = cms_clean_delete_many($paths);
        $msg = '已清理 ' . (int) $r['ok'] . ' 项';
        if (!empty($r['skip'])) {
            $msg .= '，' . count($r['skip']) . ' 项因正被引用已跳过';
        }
        if (!empty($r['fail'])) {
            $msg .= '，' . count($r['fail']) . ' 项未处理（路径非法或权限不足）';
        }
        flash($msg, empty($r['fail']) ? 'ok' : 'err');
        redirect(admin_url('clean'));
    }
}

$scan   = cms_clean_scan();
$unused = array();
foreach ($scan['files'] as $f) {
    if (!$f['used']) {
        $unused[] = $f;
    }
}
$limit = 300;
$shown = array_slice($unused, 0, $limit);
?>
<div class="stats">
    <div class="stat">
        <div class="label">附件总数</div>
        <div class="num"><?php echo (int) $scan['total']; ?></div>
        <div class="sub">含媒体库记录与磁盘文件</div>
    </div>
    <div class="stat">
        <div class="label">正在使用</div>
        <div class="num"><?php echo (int) $scan['used']; ?></div>
        <div class="sub">被文章、栏目或设置引用</div>
    </div>
    <div class="stat<?php echo $scan['unused'] > 0 ? ' accent' : ''; ?>">
        <div class="label">未被引用</div>
        <div class="num"><?php echo (int) $scan['unused']; ?></div>
        <div class="sub">可释放 <?php echo e(format_bytes($scan['bytes'])); ?></div>
    </div>
    <div class="stat">
        <div class="label">失效记录</div>
        <div class="num"><?php echo (int) $scan['missing']; ?></div>
        <div class="sub">媒体库有记录但文件已不在</div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>未被引用的文件</h2>
        <div class="top-actions">
            <a class="btn btn-sm" href="<?php echo e(admin_url('clean')); ?>">重新扫描</a>
        </div>
    </div>

    <?php if (empty($unused)): ?>
        <div class="card-body">
            <div class="empty">很干净，没有发现闲置的图片或附件</div>
        </div>
    <?php else: ?>
        <form method="post" action="<?php echo e(admin_url('clean')); ?>" id="cleanForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="delete">

            <div class="card-body tight">
                <table class="table">
                    <tr>
                        <th style="width:38px"><input class="check" type="checkbox" id="checkAll" title="全选"></th>
                        <th style="width:70px">预览</th>
                        <th>文件</th>
                        <th style="width:90px">大小</th>
                        <th style="width:150px">时间</th>
                        <th style="width:110px">状态</th>
                    </tr>
                    <?php foreach ($shown as $f): ?>
                        <tr>
                            <td><input class="check" type="checkbox" name="files[]" value="<?php echo e($f['rel']); ?>"></td>
                            <td>
                                <?php if ($f['is_image'] && !$f['missing']): ?>
                                    <a href="<?php echo e($f['url']); ?>" target="_blank">
                                        <img class="clean-thumb" src="<?php echo e($f['url']); ?>" alt="" loading="lazy">
                                    </a>
                                <?php else: ?>
                                    <span class="clean-thumb none"><?php echo $f['missing'] ? '缺失' : '文件'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo e(str_limit($f['name'] !== '' ? $f['name'] : basename($f['rel']), 40)); ?></strong>
                                <div class="hint"><?php echo e($f['rel']); ?><?php echo $f['id'] > 0 ? '' : ' · 未入库'; ?></div>
                            </td>
                            <td><?php echo e(format_bytes($f['size'])); ?></td>
                            <td class="hint"><?php echo $f['mtime'] > 0 ? e(format_date($f['mtime'], 'Y-m-d H:i')) : '—'; ?></td>
                            <td>
                                <?php if ($f['missing']): ?>
                                    <span class="badge badge-amber">文件缺失</span>
                                <?php else: ?>
                                    <span class="badge badge-gray">未引用</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="card-body clean-foot">
                <div class="hint">
                    判定方式：文件在文章正文、单页、栏目横幅、友链 Logo、系统设置或当前模板中被提到，就算"正在使用"。
                    删除前会再核对一次，期间新插入的图片会自动跳过。
                </div>
                <div class="form-actions">
                    <button class="btn btn-danger" type="submit" id="cleanSubmit">删除选中（<b id="pickCount">0</b>）</button>
                    <span class="hint">删除不可恢复，建议先备份 <code>content/uploads</code></span>
                </div>
            </div>
        </form>

        <?php if (count($unused) > $limit): ?>
            <div class="card-body">
                <div class="hint">共 <?php echo count($unused); ?> 项，为防卡顿只显示前 <?php echo $limit; ?> 项，清理后重新扫描即可看到剩余。</div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <div class="hint">
            只扫描 <code>content/uploads/</code> 目录。主题自带的图片（<code>content/themes/</code>）和模板资源不在此列，不会被删。
            如果某张图确实不再需要，但文章里还留着地址，请先在文章里删掉那张图，再来这里清理。
        </div>
    </div>
</div>

<script>
(function () {
    var all = document.getElementById('checkAll');
    var boxes = document.querySelectorAll('input[name="files[]"]');
    var count = document.getElementById('pickCount');
    var form = document.getElementById('cleanForm');
    if (!form) { return; }

    function refresh() {
        var n = 0;
        for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) { n++; } }
        if (count) { count.textContent = n; }
        if (all) { all.checked = n > 0 && n === boxes.length; }
    }
    if (all) {
        all.addEventListener('change', function () {
            for (var i = 0; i < boxes.length; i++) { boxes[i].checked = all.checked; }
            refresh();
        });
    }
    for (var i = 0; i < boxes.length; i++) { boxes[i].addEventListener('change', refresh); }

    form.addEventListener('submit', function (ev) {
        var n = 0;
        for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) { n++; } }
        if (n === 0) {
            ev.preventDefault();
            alert('请先勾选要删除的文件');
            return;
        }
        if (!confirm('确定删除选中的 ' + n + ' 项？文件会从服务器上移除，无法恢复。')) {
            ev.preventDefault();
        }
    });
    refresh();
})();
</script>
