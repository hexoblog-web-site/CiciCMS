<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 友情链接管理：列表 / 新增 / 编辑 / 删除 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'save') {
        $id   = (int) post_str('id', '0');
        $name = post_str('name');
        $url  = post_str('url');
        $back = admin_url('flink', array('action' => $id > 0 ? 'edit' : 'new', 'id' => $id));

        if ($name === '') {
            flash('链接名称不能为空', 'err');
            redirect($back);
        }
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }

        $data = array(
            'name'   => str_limit($name, 60),
            'url'    => str_limit($url, 240),
            'logo'   => str_limit(post_str('logo'), 240),
            'desc'   => str_limit(post_str('desc'), 120),
            'sort'   => (int) post_str('sort', '0'),
            'status' => post_str('status', '1') === '1' ? 1 : 0,
            'target' => post_str('target', '') === '1' ? 1 : 0,
        );

        if ($id > 0) {
            update_row('flink', $data, 'id = ?', array($id));
            $msg = '链接已更新';
        } else {
            $data['created_at'] = time();
            insert_row('flink', $data);
            $msg = '链接已创建';
        }

        if (setting('auto_build', '1') === '1') {
            // 友链只影响 links.html 与 sitemap，不必全量重建
            $r = build_incremental(array('no_home' => true));
            if (!empty($r['partial'])) {
                $msg .= '，其余页面正在后台继续生成';
            } elseif ($r['ok']) {
                $msg .= '，已更新友链页';
            } else {
                $msg .= '，但生成静态页存在异常';
            }
        }
        flash($msg);
        redirect(admin_url('flink'));
    }

    if ($do === 'delete') {
        $id = (int) post_str('id', '0');
        if ($id > 0) {
            delete_row('flink', 'id = ?', array($id));
            if (setting('auto_build', '1') === '1') {
                build_incremental(array('no_home' => true));
            }
            flash('链接已删除');
        }
        redirect(admin_url('flink'));
    }
}

$rows   = all('SELECT * FROM {p}flink ORDER BY sort ASC, id ASC');
$action = get_str('action', 'list');
$id     = (int) get('id', 0);
$edit   = array('id' => 0, 'name' => '', 'url' => '', 'logo' => '', 'desc' => '', 'sort' => 0, 'status' => 1, 'target' => 0);

if ($action === 'edit') {
    $found = one('SELECT * FROM {p}flink WHERE id = ?', array($id));
    if ($found) {
        $edit = $found;
    }
}
?>
<div class="card">
    <div class="card-head">
        <h2>友情链接</h2>
        <div class="filter-bar">
            <a class="btn btn-sm" target="_blank" href="<?php echo e(site_url('links.html')); ?>">查看友链页</a>
        </div>
    </div>
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <div class="empty">还没有友情链接，在下面新建一条</div>
        <?php else: ?>
            <table class="table">
                <tr><th>名称</th><th>地址</th><th>形式</th><th>排序</th><th>状态</th><th class="actions-cell">操作</th></tr>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="min-width:120px"><strong><?php echo e($r['name']); ?></strong></td>
                        <td class="hint" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <a href="<?php echo e($r['url']); ?>" target="_blank" rel="nofollow noopener"><?php echo e($r['url']); ?></a>
                        </td>
                        <td><?php echo $r['logo'] !== '' ? '<span class="badge badge-blue">图片</span>' : '<span class="badge badge-gray">文字</span>'; ?></td>
                        <td><?php echo (int) $r['sort']; ?></td>
                        <td>
                            <?php if ((int) $r['status'] === 1): ?>
                                <span class="badge badge-green">显示</span>
                            <?php else: ?>
                                <span class="badge badge-gray">隐藏</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions-cell">
                            <a class="btn btn-sm" href="<?php echo e(admin_url('flink', array('action' => 'edit', 'id' => $r['id']))); ?>#flink-form">编辑</a>
                            <form method="post" action="<?php echo e(admin_url('flink')); ?>" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit" data-confirm="确定删除链接「<?php echo e($r['name']); ?>」？">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <?php if (!empty($rows)): ?>
        <div class="card-foot hint">共 <?php echo count($rows); ?> 条，按排序值从小到大输出到 /links.html。</div>
    <?php endif; ?>
</div>

<div class="card" id="flink-form" style="margin-top:var(--gap);scroll-margin-top:80px">
    <div class="card-head">
        <h2><?php echo $edit['id'] ? '编辑链接' : '新增链接'; ?></h2>
        <?php if ($edit['id']): ?>
            <a class="btn btn-sm" href="<?php echo e(admin_url('flink')); ?>">取消编辑</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="post" action="<?php echo e(admin_url('flink')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">

            <div class="grid2">
                <div class="form-row">
                    <label for="name">名称</label>
                    <input class="input" type="text" id="name" name="name" value="<?php echo e($edit['name']); ?>" required>
                </div>
                <div class="form-row">
                    <label for="url">网址</label>
                    <input class="input" type="text" id="url" name="url" value="<?php echo e($edit['url']); ?>" placeholder="https://example.com">
                </div>

                <div class="form-row">
                    <label for="logo">Logo 地址（可选）</label>
                    <input class="input" type="text" id="logo" name="logo" value="<?php echo e($edit['logo']); ?>" placeholder="留空则显示为文字链接">
                </div>
                <div class="form-row">
                    <label for="desc">说明（可选）</label>
                    <input class="input" type="text" id="desc" name="desc" value="<?php echo e($edit['desc']); ?>">
                </div>

                <div class="form-row">
                    <label for="sort">排序</label>
                    <input class="input" type="number" id="sort" name="sort" value="<?php echo (int) $edit['sort']; ?>">
                    <div class="hint">数字越小越靠前</div>
                </div>
                <div class="form-row">
                    <label for="status">状态</label>
                    <select class="input" id="status" name="status">
                        <option value="1" <?php echo (int) $edit['status'] === 1 ? 'selected' : ''; ?>>显示</option>
                        <option value="0" <?php echo (int) $edit['status'] === 0 ? 'selected' : ''; ?>>隐藏</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <label class="inline" style="font-weight:400;margin:0">
                    <input class="check" type="checkbox" name="target" value="1" <?php echo (int) $edit['target'] === 1 ? 'checked' : ''; ?>>
                    新窗口打开
                </label>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-primary" type="submit">保存</button>
                </div>
            </div>
        </form>
    </div>
    <div class="card-foot hint">
        模板里用 <code>{cici:flink type="text"}</code> 调取，生成在 <code>/links.html</code>。
        <code>type</code> 可选 text（只要文字链）/ logo（只要图片链）/ all。
    </div>
</div>
