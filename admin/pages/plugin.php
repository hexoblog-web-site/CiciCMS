<?php
/**
 * 后台：插件管理
 *
 * 插件放在 content/plugins/<id>/，本页负责启用、停用，以及进入插件自己的后台页面。
 * 插件被停用时不会被 include，文件继续留在原处，随时可以恢复。
 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'toggle') {
        $id     = post_str('id');
        $active = cms_plugin_active_ids();
        $all    = cms_plugin_scan();

        if (!isset($all[$id])) {
            flash('插件不存在：' . $id, 'err');
            redirect(admin_url('plugin'));
        }

        if (in_array($id, $active, true)) {
            $active = array_values(array_diff($active, array($id)));
            $msg    = '已停用「' . $all[$id]['name'] . '」';
        } else {
            $active[] = $id;
            $msg      = '已启用「' . $all[$id]['name'] . '」';
        }
        cms_plugin_save_active($active);
        log_msg('plugin toggle: ' . $id . ' -> ' . (in_array($id, $active, true) ? 'on' : 'off'));

        // 静态页里已经写死了插件资源，开关一变就必须重新生成才对前台生效
        flash($msg . '。如果插件影响前台，请重新生成静态页使其生效。');
        redirect(admin_url('plugin'));
    }
}

/* 进入某个插件自己的后台页面 */
$openId    = get_str('id');
$adminPage = cms_plugin_admin_pages();
if ($openId !== '' && isset($adminPage[$openId])) {
    $GLOBALS['cms_plugin_current'] = $openId;
    require $adminPage[$openId]['file'];
    return;
}

$plugins = cms_plugin_scan();
$active  = cms_plugin_active_ids();
$dir     = cms_plugins_dir();
?>

<div class="card">
    <div class="card-head">
        <h2>已安装插件</h2>
        <span class="badge badge-blue"><?php echo count($active); ?> / <?php echo count($plugins); ?> 已启用</span>
    </div>
    <div class="card-body tight">
        <?php if (empty($plugins)): ?>
            <div class="empty">
                <p>还没有插件。</p>
                <p class="hint">把插件目录放进 <code><?php echo e(str_replace(CMS_ROOT . '/', '', $dir)); ?>/</code> 即可自动出现在这里，每个插件是一个文件夹，至少包含 <code>plugin.json</code> 与 <code>plugin.php</code>。</p>
            </div>
        <?php else: ?>
            <table class="table">
                <tr>
                    <th>插件</th>
                    <th style="width:110px">版本</th>
                    <th style="width:100px">状态</th>
                    <th style="width:200px;text-align:right">操作</th>
                </tr>
                <?php foreach ($plugins as $id => $p): $on = in_array($id, $active, true); ?>
                    <tr>
                        <td>
                            <strong><?php echo e($p['name']); ?></strong>
                            <span class="hint" style="font-size:11.5px"> · <?php echo e($id); ?></span>
                            <?php if ($p['description'] !== ''): ?>
                                <div class="hint" style="font-size:12px;line-height:1.7;margin-top:2px"><?php echo e($p['description']); ?></div>
                            <?php endif; ?>
                            <?php if ($p['author'] !== ''): ?>
                                <div class="hint" style="font-size:11px">作者：<?php echo e($p['author']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="hint" style="font-size:12px"><?php echo e($p['version']); ?></td>
                        <td>
                            <?php if ($on): ?>
                                <span class="badge badge-green">已启用</span>
                            <?php else: ?>
                                <span class="badge badge-gray">已停用</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if ($on && isset($adminPage[$id])): ?>
                                <a class="btn btn-sm" href="<?php echo e(admin_url('plugin', array('id' => $id))); ?>">打开</a>
                            <?php endif; ?>
                            <form method="post" action="<?php echo e(admin_url('plugin')); ?>" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="toggle">
                                <input type="hidden" name="id" value="<?php echo e($id); ?>">
                                <button class="btn btn-sm <?php echo $on ? '' : 'btn-primary'; ?>" type="submit">
                                    <?php echo $on ? '停用' : '启用'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <div class="card-foot hint" style="line-height:1.9">
        插件代码以 PHP 运行，拥有与程序相同的权限，<strong>只安装你信任的插件</strong>。<br>
        停用插件只是不再加载它，文件不会被删除；删除插件直接删掉对应文件夹即可。
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>钩子一览</h2></div>
    <div class="card-body tight">
        <table class="table">
            <tr><th style="width:150px">钩子</th><th style="width:70px">类型</th><th>触发时机</th></tr>
            <?php foreach (cms_hook_docs() as $hook => $info): ?>
                <tr>
                    <td><code><?php echo e($hook); ?></code></td>
                    <td>
                        <span class="badge <?php echo $info[0] === 'filter' ? 'badge-blue' : 'badge-amber'; ?>">
                            <?php echo $info[0] === 'filter' ? 'filter' : 'action'; ?>
                        </span>
                    </td>
                    <td class="hint" style="font-size:12px;line-height:1.7"><?php echo $info[1]; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="card-foot hint" style="line-height:1.9">
        在 <code>plugin.php</code> 里用 <code>cms_add_filter('钩子名', 回调)</code> 或
        <code>cms_add_action('钩子名', 回调)</code> 注册；过滤器需要 <code>return</code> 新值。<br>
        完整开发说明见项目文档「插件开发」一节。
    </div>
</div>
