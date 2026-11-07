<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 单页管理（关于我们、联系方式等固定页面） */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'save') {
        $id      = (int) post_str('id', '0');
        $title   = post_str('title');
        // 与文章一致：正文取原始值（不 trim），先渲染代码块围栏再净化
        $contentSrc = isset($_POST['content']) && is_string($_POST['content']) ? $_POST['content'] : '';
        $content = render_content_clean($contentSrc);
        $back    = admin_url('page', array('action' => $id > 0 ? 'edit' : 'new', 'id' => $id));

        if ($title === '') {
            flash('页面标题不能为空', 'err');
            redirect($back);
        }

        $data = array(
            'title'       => str_limit($title, 150),
            'content'     => $content,
            'content_src' => $contentSrc,
            'status'      => post_str('status', '0') === '1' ? 1 : 0,
            'sort'        => (int) post_str('sort', '0'),
            'updated_at'  => time(),
        );
        $slugRaw = make_slug(post_str('slug'));

        // 单页直接输出到站点根目录，必须避开系统占用的文件名，
        // 否则会把首页 /404.html / tags.html 等覆盖掉
        if ($slugRaw !== '' && in_array($slugRaw, build_reserved_slugs(), true)) {
            flash('“' . $slugRaw . '”是系统保留路径，请换一个，例如 about', 'err');
            redirect($back);
        }

        $oldRow = $id > 0 ? one('SELECT slug FROM {p}page WHERE id = ?', array($id)) : null;

        if ($id > 0) {
            if ($slugRaw !== '') {
                $data['slug'] = unique_slug('page', $slugRaw, $id);
            }
            update_row('page', $data, 'id = ?', array($id));
            $msg = '页面已更新';
        } else {
            $data['slug']       = $slugRaw !== '' ? unique_slug('page', $slugRaw, 0) : 'tmp' . substr(md5(uniqid('', true)), 0, 10);
            $data['created_at'] = time();
            $id = insert_row('page', $data);
            if ($slugRaw === '') {
                update_row('page', array('slug' => (string) $id), 'id = ?', array($id));
            }
            $msg = '页面已创建';
        }

        // 改了路径或转为草稿时，删掉旧的静态页
        $newRow = one('SELECT slug, status FROM {p}page WHERE id = ?', array($id));
        if ($oldRow && $oldRow['slug'] !== '' && (!$newRow || $oldRow['slug'] !== $newRow['slug'] || (int) $newRow['status'] !== 1)) {
            static_forget(array($oldRow['slug'] . '.html'));
        }

        if (setting('auto_build', '1') === '1') {
            $r = build_incremental(array('page_id' => $id, 'no_home' => true));
            if (!empty($r['partial'])) {
                $msg .= '，其余页面正在后台继续生成';
            } elseif ($r['ok']) {
                $msg .= '，已更新静态页';
            } else {
                $msg .= '，但生成静态页存在异常';
            }
        }
        flash($msg);
        redirect(admin_url('page'));
    }

    if ($do === 'delete') {
        $id = (int) post_str('id', '0');
        if ($id > 0) {
            $row = one('SELECT slug FROM {p}page WHERE id = ?', array($id));
            delete_row('page', 'id = ?', array($id));
            if ($row && $row['slug'] !== '') {
                static_forget(array($row['slug'] . '.html'));
            }
            if (setting('auto_build', '1') === '1') {
                build_incremental(array('no_home' => true));
            }
            flash('页面已删除');
        }
        redirect(admin_url('page'));
    }
}

$action = get_str('action', 'list');
$id     = (int) get('id', 0);

if ($action === 'new' || $action === 'edit') {
    if ($action === 'edit') {
        $row = one('SELECT * FROM {p}page WHERE id = ?', array($id));
        if (!$row) {
            flash('页面不存在', 'err');
            redirect(admin_url('page'));
        }
    } else {
        $row = array('id' => 0, 'title' => '', 'slug' => '', 'content' => '', 'content_src' => '', 'status' => 1, 'sort' => 0);
    }
    ?>
    <div class="card">
        <div class="card-head">
            <h2><?php echo $row['id'] ? '编辑单页 #' . (int) $row['id'] : '新建单页'; ?></h2>
            <a class="btn btn-sm" href="<?php echo e(admin_url('page')); ?>">返回列表</a>
        </div>
        <div class="card-body">
            <form method="post" action="<?php echo e(admin_url('page')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                <div class="form-row">
                    <label for="title">页面标题</label>
                    <input class="input" type="text" id="title" name="title" value="<?php echo e($row['title']); ?>" required>
                </div>
                <div class="form-row">
                    <label for="content">内容（支持 HTML 与 ``` 代码块）</label>
                    <div class="editor-tools">
                        <button type="button" data-insert="p">段落</button>
                        <button type="button" data-insert="h2">标题</button>
                        <button type="button" data-insert="bold">加粗</button>
                        <button type="button" data-insert="ul">列表</button>
                        <button type="button" data-insert="link">链接</button>
                        <button type="button" data-insert="img">图片</button>
                        <button type="button" data-insert="codeblock">代码块</button>
                    </div>
                    <textarea class="input" id="content" name="content" style="min-height:380px"><?php echo e(content_source($row)); ?></textarea>
                    <div class="hint">图片：点工具栏的「图片」，可以上传本地文件（也能直接拖进去），或粘贴网址让程序抓回本站。</div>
                </div>

                <div class="card card-inset">
                    <div class="card-head"><h2>发布</h2></div>
                    <div class="card-body">
                        <div class="form-row">
                            <label for="status">状态</label>
                            <select class="input" id="status" name="status">
                                <option value="1" <?php echo (int) $row['status'] === 1 ? 'selected' : ''; ?>>已发布</option>
                                <option value="0" <?php echo (int) $row['status'] === 0 ? 'selected' : ''; ?>>草稿</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="slug">访问路径</label>
                            <input class="input" type="text" id="slug" name="slug" value="<?php echo e($row['slug']); ?>" placeholder="about">
                            <div class="hint">生成 /about.html；留空使用 ID</div>
                        </div>
                        <div class="form-row">
                            <label for="sort">排序</label>
                            <input class="input" type="number" id="sort" name="sort" value="<?php echo (int) $row['sort']; ?>">
                        </div>
                        <button class="btn btn-primary btn-block" type="submit">保存并生成</button>
                        <?php $previewUrl = $row['id'] ? u_page_preview($row) : ''; ?>
                        <button class="btn btn-block" style="margin-top:8px" type="button"
                                data-preview="page" data-static-url="<?php echo e($previewUrl); ?>"
                                title="在当前窗口弹窗预览，未保存的改动、草稿也能看">预览</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
    return;
}

$rows = all('SELECT * FROM {p}page ORDER BY sort ASC, id ASC');
?>
<div class="card">
    <div class="card-head">
        <h2>单页列表</h2>
    </div>
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <div class="empty">还没有单页，例如可以创建“关于我们”“联系方式”</div>
        <?php else: ?>
            <table class="table">
                <tr><th>标题</th><th>路径</th><th>状态</th><th>排序</th><th class="actions-cell">操作</th></tr>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?php echo e($r['title']); ?></strong></td>
                        <td class="hint">/<?php echo e($r['slug']); ?>.html</td>
                        <td><?php echo ((int) $r['status'] === 1) ? '<span class="badge badge-green">已发布</span>' : '<span class="badge badge-gray">草稿</span>'; ?></td>
                        <td><?php echo (int) $r['sort']; ?></td>
                        <td class="actions-cell">
                            <?php $pv = u_page_preview($r); ?>
                            <?php if ($pv !== ''): ?>
                                <a class="btn btn-sm" target="_blank" href="<?php echo e($pv); ?>">预览</a>
                            <?php else: ?>
                                <button class="btn btn-sm" type="button" disabled title="草稿不生成静态页">预览</button>
                            <?php endif; ?>
                            <a class="btn btn-sm" href="<?php echo e(admin_url('page', array('action' => 'edit', 'id' => $r['id']))); ?>">编辑</a>
                            <form method="post" action="<?php echo e(admin_url('page')); ?>" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit" data-confirm="确定删除页面「<?php echo e($r['title']); ?>」？">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>
