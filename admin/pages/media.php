<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 媒体信息：图片上传、替代文本、回收站 */

$bin = (int) get('bin', 0) === 1;

if (is_post()) {
    csrf_verify();
    $do  = post_str('do');
    $bin = post_str('bin', '0') === '1';
    $back = admin_url('media', $bin ? array('bin' => 1) : array());

    if ($do === 'upload') {
        $r = cms_upload_image(isset($_FILES['file']) ? $_FILES['file'] : null, $err);
        if (!$r) {
            flash($err, 'err');
            redirect($back);
        }
        flash('上传成功：' . $r['name']);
        redirect($back);
    }

    /* 替代文本：构建 <img alt> 时用得上，也方便图片搜索 */
    if ($do === 'alt') {
        $id  = (int) post_str('id', '0');
        $alt = str_limit(trim(preg_replace('/\s+/u', ' ', post_str('alt'))), 200, '');
        if ($id > 0) {
            update_row('attachment', array('alt' => $alt), 'id = ?', array($id));
            flash('替代文本已保存');
        }
        redirect($back);
    }

    /* 删除 = 移入回收站：只打时间戳，文件留在磁盘，随时能恢复 */
    if ($do === 'delete') {
        $id  = (int) post_str('id', '0');
        $row = one('SELECT id FROM {p}attachment WHERE id = ?', array($id));
        if ($row) {
            update_row('attachment', array('deleted_at' => time()), 'id = ?', array($id));
            flash('已移入回收站，可在回收站里恢复或彻底删除');
        }
        redirect($back);
    }

    if ($do === 'restore') {
        $id  = (int) post_str('id', '0');
        $row = one('SELECT id FROM {p}attachment WHERE id = ?', array($id));
        if ($row) {
            update_row('attachment', array('deleted_at' => 0), 'id = ?', array($id));
            flash('已恢复到媒体列表');
        }
        redirect($back);
    }

    /* 彻底删除：先删文件再删记录；文件不在也允许清掉这条记录 */
    if ($do === 'purge') {
        $id  = (int) post_str('id', '0');
        $row = one('SELECT * FROM {p}attachment WHERE id = ?', array($id));
        if ($row) {
            cms_upload_delete($row['path']);
            delete_row('attachment', 'id = ?', array($id));
            flash('已彻底删除');
        }
        redirect($back);
    }
}

$page  = max(1, (int) get('page', 1));
$per   = 24;
$where = $bin ? 'WHERE deleted_at > 0' : 'WHERE deleted_at = 0';
$total = (int) val('SELECT COUNT(*) FROM {p}attachment ' . $where);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows  = all(
    'SELECT * FROM {p}attachment ' . $where . ' ORDER BY id DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per)
);
$binCount = (int) val('SELECT COUNT(*) FROM {p}attachment WHERE deleted_at > 0');
?>
<?php if (!$bin): ?>
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
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?php echo $bin ? '回收站' : '已上传文件'; ?>（<?php echo $total; ?>）</h2>
        <span class="hint">
            <?php if ($bin): ?>
                <a href="<?php echo e(admin_url('media')); ?>">← 返回媒体列表</a>
            <?php elseif ($binCount > 0): ?>
                <a href="<?php echo e(admin_url('media', array('bin' => 1))); ?>">回收站（<?php echo $binCount; ?>）→</a>
            <?php else: ?>
                删除的文件会先进回收站，可恢复
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="empty"><?php echo $bin ? '回收站是空的' : '还没有上传任何图片'; ?></div>
        <?php else: ?>
            <div class="media-grid">
                <?php foreach ($rows as $r): ?>
                    <div class="media-item">
                        <div class="thumb">
                            <a href="<?php echo e(upload_url($r['path'])); ?>" target="_blank">
                                <img src="<?php echo e(upload_url($r['path'])); ?>" alt="<?php echo e($r['alt'] !== '' ? $r['alt'] : $r['name']); ?>" loading="lazy">
                            </a>
                        </div>
                        <div class="meta">
                            <?php echo e(str_limit($r['name'], 26)); ?><br>
                            <?php echo e(format_bytes($r['size'])); ?> · <?php echo e(format_date($r['created_at'], 'm-d')); ?>
                            <?php if ((int) $r['content_id'] > 0): ?>
                                · <a href="<?php echo e(admin_url('article', array('action' => 'edit', 'id' => (int) $r['content_id']))); ?>">文章 #<?php echo (int) $r['content_id']; ?></a>
                            <?php endif; ?>
                        </div>
                        <div class="ops">
                            <input class="input" type="text" readonly value="<?php echo e(upload_url($r['path'])); ?>" style="padding:3px 6px;font-size:11px;margin-bottom:6px" onclick="this.select()">
                            <?php if (!$bin): ?>
                                <form method="post" action="<?php echo e(admin_url('media')); ?>" style="display:block;margin-bottom:6px">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="do" value="alt">
                                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                    <input class="input" type="text" name="alt" value="<?php echo e($r['alt']); ?>" maxlength="200"
                                           placeholder="图片替代文本（alt）" title="读屏软件与图片搜索会用到，建议一句话描述画面"
                                           style="padding:3px 6px;font-size:11px;margin-bottom:4px">
                                    <button class="btn btn-sm" type="submit">保存说明</button>
                                </form>
                                <form method="post" action="<?php echo e(admin_url('media')); ?>" style="display:inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="do" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="btn btn-sm btn-danger" type="submit" data-confirm="移入回收站？文章里的引用会暂时失效，但可以恢复。">删除</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?php echo e(admin_url('media', array('bin' => 1))); ?>" style="display:inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="do" value="restore">
                                    <input type="hidden" name="bin" value="1">
                                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="btn btn-sm" type="submit">恢复</button>
                                </form>
                                <form method="post" action="<?php echo e(admin_url('media', array('bin' => 1))); ?>" style="display:inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="do" value="purge">
                                    <input type="hidden" name="bin" value="1">
                                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                    <button class="btn btn-sm btn-danger" type="submit" data-confirm="彻底删除？文件会从磁盘上消失，无法恢复。">彻底删除</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    echo admin_pager_html($page, $pages, function ($n) use ($bin) {
        return admin_url('media', $bin ? array('bin' => 1, 'page' => $n) : array('page' => $n));
    }, $total);
    ?>
</div>
