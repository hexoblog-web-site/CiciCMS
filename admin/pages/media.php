<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 媒体库：图片上传与管理 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'upload') {
        $r = cms_upload_image(isset($_FILES['file']) ? $_FILES['file'] : null, $err);
        if (!$r) {
            flash($err, 'err');
            redirect(admin_url('media'));
        }
        flash('上传成功：' . $r['name']);
        redirect(admin_url('media'));
    }

    if ($do === 'delete') {
        $id  = (int) post_str('id', '0');
        $row = one('SELECT * FROM {p}attachment WHERE id = ?', array($id));
        if ($row) {
            cms_upload_delete($row['path']);
            delete_row('attachment', 'id = ?', array($id));
            flash('文件已删除');
        }
        redirect(admin_url('media'));
    }
}

$page  = max(1, (int) get('page', 1));
$per   = 24;
$total = (int) val('SELECT COUNT(*) FROM {p}attachment');
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows  = all('SELECT * FROM {p}attachment ORDER BY id DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per));
?>
<div class="card">
    <div class="card-head">
        <h2>上传图片</h2>
        <span class="hint">支持 jpg / png / gif / webp，单文件 ≤ 5MB</span>
    </div>
    <div class="card-body">
        <form method="post" action="<?php echo e(admin_url('media')); ?>" enctype="multipart/form-data" class="filter-bar">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="upload">
            <input class="input" type="file" name="file" accept="image/*" required>
            <button class="btn btn-primary" type="submit">上传</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>已上传文件（<?php echo $total; ?>）</h2>
    </div>
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="empty">还没有上传任何图片</div>
        <?php else: ?>
            <div class="media-grid">
                <?php foreach ($rows as $r): ?>
                    <div class="media-item">
                        <div class="thumb">
                            <a href="<?php echo e(upload_url($r['path'])); ?>" target="_blank">
                                <img src="<?php echo e(upload_url($r['path'])); ?>" alt="<?php echo e($r['name']); ?>" loading="lazy">
                            </a>
                        </div>
                        <div class="meta">
                            <?php echo e(str_limit($r['name'], 26)); ?><br>
                            <?php echo e(format_bytes($r['size'])); ?> · <?php echo e(format_date($r['created_at'], 'm-d')); ?>
                        </div>
                        <div class="ops">
                            <input class="input" type="text" readonly value="<?php echo e(upload_url($r['path'])); ?>" style="padding:3px 6px;font-size:11px;margin-bottom:6px" onclick="this.select()">
                            <form method="post" action="<?php echo e(admin_url('media')); ?>" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit" data-confirm="确定删除该图片？文章中的引用会失效。">删除</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    echo admin_pager_html($page, $pages, function ($n) {
        return admin_url('media', array('page' => $n));
    }, $total);
    ?>
</div>
