<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 栏目管理：栏目设置（标题 / 别名 / URL / 关键词 / 描述 / 横幅） */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'save') {
        $id   = (int) post_str('id', '0');
        $name = post_str('name');
        $dir  = make_slug(post_str('dirname'));
        $back = admin_url('category', array('action' => $id > 0 ? 'edit' : 'new', 'id' => $id));

        // 别名与 URL 是同一个值的两种写法：直接改 URL 时以 URL 为准
        $urlDir = make_slug(trim(post_str('url_path'), "/ \t"));
        if ($urlDir !== '' && $urlDir !== $dir) {
            $dir = $urlDir;
        }

        if ($name === '') {
            flash('栏目标题不能为空', 'err');
            redirect($back);
        }
        if ($dir === '') {
            $dir = 'cat' . substr(md5($name . microtime()), 0, 6);
        }
        // 目录名唯一
        $base = $dir;
        $i    = 2;
        while (val('SELECT id FROM {p}category WHERE dirname = ? AND id <> ?', array($dir, $id))) {
            $dir = $base . '-' . $i;
            $i++;
            if ($i > 99) {
                $dir = $base . '-' . substr(md5(uniqid('', true)), 0, 4);
                break;
            }
        }

        // 栏目目录直接落在站点根，不能和系统目录/固定页重名，否则会互相覆盖
        if (in_array(strtolower($dir), cms_reserved_dirs(), true)) {
            flash('别名「' . $dir . '」与系统目录或固定页面冲突，请换一个，例如 minjiangushi', 'err');
            redirect($back);
        }

        // 横幅：先处理"移除"，再处理"上传"（上传优先，勾了移除又选了文件时以文件为准）
        $banner = cms_banner_clean(post_str('banner_url'));
        if (post_str('banner_clear') === '1') {
            $banner = '';
        }
        if (!empty($_FILES['banner_file']) && (int) $_FILES['banner_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = cms_upload_image($_FILES['banner_file'], $upErr);
            if (!$up) {
                flash('横幅上传失败：' . $upErr, 'err');
                redirect($back);
            }
            $banner = $up['path'];
        }

        $oldDir = $id > 0 ? (string) val('SELECT dirname FROM {p}category WHERE id = ?', array($id)) : '';

        $data = array(
            'name'        => str_limit($name, 40),
            'dirname'     => $dir,
            'sort'        => (int) post_str('sort', '0'),
            'description' => str_limit(post_str('description'), 120),
            'keywords'    => str_limit(post_str('keywords'), 120),
            'banner'      => $banner,
        );

        if ($id > 0) {
            update_row('category', $data, 'id = ?', array($id));
            $msg = '栏目已更新';
        } else {
            $id = insert_row('category', $data);
            $msg = '栏目已创建';
        }

        // 目录改名：旧目录下的静态文件全部清掉，免得根目录里留着两份同样内容
        if ($oldDir !== '' && $oldDir !== $dir) {
            $stale = array($oldDir . '/index.html');
            $per   = fe_per_page();
            $total = (int) val('SELECT COUNT(*) FROM {p}article WHERE cat_id = ? AND status = 1', array($id));
            for ($p = 2; $p <= max(1, (int) ceil($total / $per)); $p++) {
                $stale[] = $oldDir . '/page/' . $p . '/index.html';
            }
            foreach (all('SELECT id FROM {p}article WHERE cat_id = ?', array($id)) as $a) {
                $stale[] = $oldDir . '/' . (int) $a['id'] . '.html';
            }
            static_forget($stale);

            // 旧目录清空后顺手删掉空文件夹，免得根目录里堆一串空壳
            for ($p = 2; $p <= max(1, (int) ceil($total / $per)); $p++) {
                @rmdir(CMS_ROOT . '/' . $oldDir . '/page/' . $p);
            }
            @rmdir(CMS_ROOT . '/' . $oldDir . '/page');
            @rmdir(CMS_ROOT . '/' . $oldDir);
        }

        if (setting('auto_build', '1') === '1') {
            $r = build_all(6.0);
            if (!empty($r['partial'])) {
                $msg .= '，其余页面正在后台继续生成';
            } elseif ($r['ok']) {
                $msg .= '，已生成静态页';
            } else {
                $msg .= '，但生成静态页存在异常';
            }
        }
        flash($msg);
        // 与文章、单页一致：保存后停在设置页，能立刻看到横幅预览和新地址
        $go = post_str('go', 'stay');
        if ($go === 'list') {
            redirect(admin_url('category'));
        }
        redirect(admin_url('category', array('action' => 'edit', 'id' => $id)));
    }

    if ($do === 'delete') {
        $id = (int) post_str('id', '0');
        $n  = (int) val('SELECT COUNT(*) FROM {p}article WHERE cat_id = ?', array($id));
        if ($n > 0) {
            flash('该栏目下还有 ' . $n . ' 篇文章，请先移动或删除这些文章', 'err');
            redirect(admin_url('category'));
        }
        delete_row('category', 'id = ?', array($id));
        if (setting('auto_build', '1') === '1') {
            build_all(6.0);
        }
        flash('栏目已删除');
        redirect(admin_url('category'));
    }
}

$cats   = all('SELECT c.*, (SELECT COUNT(*) FROM {p}article a WHERE a.cat_id = c.id) AS total
               FROM {p}category c ORDER BY c.sort ASC, c.id ASC');
$action = get_str('action', 'list');
$id     = (int) get('id', 0);
$edit   = array(
    'id'          => 0,
    'name'        => '',
    'dirname'     => '',
    'sort'        => 0,
    'description' => '',
    'keywords'    => '',
    'banner'      => '',
);

if ($action === 'edit') {
    $found = one('SELECT * FROM {p}category WHERE id = ?', array($id));
    if ($found) {
        $edit = array_merge($edit, $found);
    }
}

$bannerUrl = $edit['banner'] !== '' ? upload_url($edit['banner']) : '';
$siteBase  = rtrim(site_url(), '/');
?>
<div class="card">
    <div class="card-head">
        <h2>栏目列表</h2>
    </div>
    <div class="card-body tight">
        <?php if (empty($cats)): ?>
            <div class="empty">还没有栏目，先新建一个吧</div>
        <?php else: ?>
            <table class="table">
                <tr><th>栏目标题</th><th>地址</th><th>文章</th><th>排序</th><th class="actions-cell">操作</th></tr>
                <?php foreach ($cats as $c): ?>
                    <tr>
                        <td>
                            <?php if (!empty($c['banner'])): ?>
                                <img class="cat-thumb" src="<?php echo e(upload_url($c['banner'])); ?>" alt="">
                            <?php endif; ?>
                            <strong><?php echo e($c['name']); ?></strong>
                        </td>
                        <td class="hint">/<?php echo e($c['dirname']); ?>/</td>
                        <td><?php echo (int) $c['total']; ?></td>
                        <td><?php echo (int) $c['sort']; ?></td>
                        <td class="actions-cell">
                            <a class="btn btn-sm" href="<?php echo e(admin_url('category', array('action' => 'edit', 'id' => $c['id']))); ?>">编辑</a>
                            <form method="post" action="<?php echo e(admin_url('category')); ?>" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit" data-confirm="确定删除栏目「<?php echo e($c['name']); ?>」？">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?php echo $edit['id'] ? '栏目设置' : '新建栏目'; ?></h2>
        <span class="hint">这六项决定栏目页的标题、地址和页头外观</span>
    </div>
    <div class="card-body">
        <form method="post" action="<?php echo e(admin_url('category')); ?>" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">

            <div class="grid2">
                <div class="form-row">
                    <label for="name">栏目标题</label>
                    <input class="input" type="text" id="name" name="name" value="<?php echo e($edit['name']); ?>" placeholder="民间故事" required>
                    <div class="hint">显示在栏目页顶部，也用作页面 title 和导航文字</div>
                </div>
                <div class="form-row">
                    <label for="dirname">别名</label>
                    <input class="input" type="text" id="dirname" name="dirname" value="<?php echo e($edit['dirname']); ?>" placeholder="minjiangushi">
                    <div class="hint">英文/数字/短横线，决定栏目地址，留空自动生成</div>
                </div>
            </div>

            <div class="form-row">
                <label for="url_path">URL</label>
                <div class="url-field">
                    <span class="url-base"><?php echo e($siteBase); ?>/</span>
                    <input class="input" type="text" id="url_path" name="url_path" value="<?php echo e($edit['dirname']); ?>" placeholder="minjiangushi" autocomplete="off">
                    <span class="url-base">/</span>
                </div>
                <div class="hint">
                    实际地址：<code id="url_preview"><?php echo e($siteBase . '/' . ($edit['dirname'] !== '' ? $edit['dirname'] : '...') . '/'); ?></code>
                    ，列表第 2 页为 <code>…/page/2/index.html</code>。改这里等于改别名。
                </div>
            </div>

            <div class="grid2">
                <div class="form-row">
                    <label for="keywords">关键词</label>
                    <input class="input" type="text" id="keywords" name="keywords" value="<?php echo e($edit['keywords']); ?>" placeholder="闽江,民间故事,传说">
                    <div class="hint">逗号分隔，写进页面 keywords；留空用站点默认</div>
                </div>
                <div class="form-row">
                    <label for="sort">排序</label>
                    <input class="input" type="number" id="sort" name="sort" value="<?php echo (int) $edit['sort']; ?>">
                    <div class="hint">数字越小越靠前</div>
                </div>
            </div>

            <div class="form-row">
                <label for="description">描述</label>
                <textarea class="input" id="description" name="description" style="min-height:72px" placeholder="一句话说明这个栏目讲什么，会显示在栏目页标题下方，也用作页面 description"><?php echo e($edit['description']); ?></textarea>
                <div class="hint">建议 60–120 字；留空则用站点描述</div>
            </div>

            <div class="form-row">
                <label>栏目横幅（Banner）</label>
                <?php if ($bannerUrl !== ''): ?>
                    <div class="banner-preview" id="banner_preview">
                        <img src="<?php echo e($bannerUrl); ?>" alt="栏目横幅预览">
                    </div>
                    <label class="banner-clear">
                        <input class="check" type="checkbox" name="banner_clear" value="1"> 移除当前横幅
                    </label>
                <?php endif; ?>
                <input class="input" type="file" name="banner_file" accept="image/jpeg,image/png,image/gif,image/webp">
                <div class="hint">上传新的会替换旧图；也可以在下面直接填图片地址</div>
                <input class="input" type="text" name="banner_url" value="<?php echo e($bannerUrl); ?>" placeholder="https://… 或从媒体信息复制地址" style="margin-top:8px">
                <div class="hint">作为栏目页页头背景，建议 1200×300 左右；jpg / png / webp，单张 ≤ 5MB</div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit" name="go" value="stay">保存栏目</button>
                <button class="btn" type="submit" name="go" value="list">保存并返回列表</button>
                <?php if ($edit['id']): ?>
                    <a class="btn" href="<?php echo e(u_cat($edit)); ?>" target="_blank">查看栏目页</a>
                    <a class="btn" href="<?php echo e(admin_url('category')); ?>">取消</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var alias = document.getElementById('dirname');
    var url   = document.getElementById('url_path');
    var prev  = document.getElementById('url_preview');
    if (!alias || !url || !prev) { return; }
    var base = <?php echo json_encode($siteBase); ?>;

    function clean(v) {
        return (v || '').replace(/[^0-9A-Za-z_-]/g, '');
    }
    function render() {
        prev.textContent = base + '/' + (clean(alias.value) || '...') + '/';
    }
    alias.addEventListener('input', function () {
        url.value = alias.value;
        render();
    });
    url.addEventListener('input', function () {
        var v = url.value.trim().replace(/^https?:\/\/[^\/]+/i, '');
        var seg = v.replace(/^\/+|\/+$/g, '').split('/').pop();
        alias.value = seg;
        render();
    });
    url.addEventListener('blur', function () {
        url.value = alias.value;
    });
    render();
})();
</script>
