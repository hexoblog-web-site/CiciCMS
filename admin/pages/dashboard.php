<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 仪表盘 */
$stat = array(
    'published' => (int) val('SELECT COUNT(*) FROM {p}article WHERE status = 1'),
    'drafts'    => (int) val('SELECT COUNT(*) FROM {p}article WHERE status = 0'),
    'cats'      => (int) val('SELECT COUNT(*) FROM {p}category'),
    'pages'     => (int) val('SELECT COUNT(*) FROM {p}page'),
    'media'     => (int) val('SELECT COUNT(*) FROM {p}attachment'),
    'views'     => (int) val('SELECT COALESCE(SUM(views),0) FROM {p}article'),
);
$recent    = all('SELECT a.id, a.title, a.status, a.created_at, a.views, c.name AS cat_name
                  FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                  ORDER BY a.id DESC LIMIT 8');
$lastBuild = (int) setting('last_build', 0);
$staticNum = static_files_count();
?>

<div class="stats">
    <div class="stat accent">
        <div class="label">已发布文章</div>
        <div class="num"><?php echo $stat['published']; ?></div>
        <div class="sub">草稿 <?php echo $stat['drafts']; ?> 篇</div>
    </div>
    <div class="stat">
        <div class="label">累计浏览</div>
        <div class="num"><?php echo $stat['views']; ?></div>
        <div class="sub">静态页面上报</div>
    </div>
    <div class="stat">
        <div class="label">静态文件</div>
        <div class="num"><?php echo $staticNum; ?></div>
        <div class="sub"><?php echo $lastBuild ? '上次生成 ' . e(human_date($lastBuild)) : '尚未生成'; ?></div>
    </div>
    <div class="stat">
        <div class="label">栏目 / 单页</div>
        <div class="num"><?php echo $stat['cats']; ?> / <?php echo $stat['pages']; ?></div>
        <div class="sub">媒体 <?php echo $stat['media']; ?> 个</div>
    </div>
</div>

<div class="edit-cols">
    <div>
        <div class="card">
            <div class="card-head">
                <h2>最近文章</h2>
                <a class="btn btn-sm btn-primary" href="<?php echo e(admin_url('article', array('action' => 'new'))); ?>">写文章</a>
            </div>
            <div class="card-body tight">
                <?php if (empty($recent)): ?>
                    <div class="empty">还没有文章，<a href="<?php echo e(admin_url('article', array('action' => 'new'))); ?>">写第一篇</a></div>
                <?php else: ?>
                    <table class="table">
                        <tr><th>标题</th><th>分类</th><th>状态</th><th>浏览</th><th>时间</th></tr>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><a href="<?php echo e(admin_url('article', array('action' => 'edit', 'id' => $r['id']))); ?>"><?php echo e(str_limit($r['title'], 40)); ?></a></td>
                                <td><?php echo e($r['cat_name'] ? $r['cat_name'] : '未分类'); ?></td>
                                <td>
                                    <?php if ((int) $r['status'] === 1): ?>
                                        <span class="badge badge-green">已发布</span>
                                    <?php else: ?>
                                        <span class="badge badge-gray">草稿</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $r['views']; ?></td>
                                <td class="hint"><?php echo e(format_date($r['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>快捷操作</h2></div>
            <div class="card-body">
                <div class="form-actions">
                    <a class="btn btn-primary" href="<?php echo e(admin_url('build')); ?>">生成静态页面</a>
                    <a class="btn" href="<?php echo e(admin_url('category')); ?>">管理栏目</a>
                    <a class="btn" href="<?php echo e(admin_url('page')); ?>">管理单页</a>
                    <a class="btn" href="<?php echo e(admin_url('setting')); ?>">站点设置</a>
                </div>
            </div>
        </div>
    </div>

    <div class="edit-side">
        <div class="card">
            <div class="card-head"><h2>目录权限自检</h2></div>
            <div class="card-body tight">
                <table class="table">
                    <?php foreach (cms_permission_audit() as $row): ?>
                        <tr>
                            <td>
                                <?php echo e($row['label']); ?><br>
                                <span class="hint" style="font-size:11px"><?php echo e($row['note']); ?></span>
                            </td>
                            <td style="white-space:nowrap;font-size:12px"><?php echo e($row['want']); ?></td>
                            <td style="white-space:nowrap">
                                <?php if ($row['ok']): ?>
                                    <span class="badge badge-green"><?php echo e($row['actual']); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-amber"><?php echo e($row['actual']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <div class="card-foot hint" style="line-height:1.8">
                    原则：代码区（includes、admin）只执行不写入，内容区只写入不执行。<br>
                    黄色项不是故障，是部署建议；Linux 主机可执行
                    <code>chmod -R a-w includes admin</code>。<br>
                    只去掉写位即可，目录要保留 <code>r-x</code>：设成 <code>--x</code> 会让
                    <code>includes/tags</code> 扫描不到标签文件，前台模板整体失效。
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>系统信息</h2></div>
            <div class="card-body">
                <div class="kv"><span>程序版本</span><span>HtmlCms <?php echo e(CMS_VERSION); ?></span></div>
                <div class="kv"><span>PHP</span><span><?php echo e(PHP_VERSION); ?></span></div>
                <div class="kv"><span>MySQL</span><span><?php echo e((string) val('SELECT VERSION()')); ?></span></div>
                <div class="kv"><span>站点地址</span><span><?php echo e(site_url()); ?></span></div>
                <div class="kv"><span>当前模板</span><span><?php echo e(theme_name()); ?></span></div>
                <div class="kv"><span>自动生成</span><span><?php echo setting('auto_build', '1') === '1' ? '开启' : '关闭'; ?></span></div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>生成状态</h2></div>
            <div class="card-body">
                <div class="kv"><span>静态文件</span><span><?php echo $staticNum; ?> 个</span></div>
                <div class="kv"><span>上次耗时</span><span><?php echo (int) setting('last_build_ms', 0); ?> ms</span></div>
                <div class="kv"><span>数据目录</span><span><?php echo is_writable(CMS_DATA) ? '可写' : '不可写'; ?></span></div>
                <?php if (!is_writable(CMS_ROOT)): ?>
                    <div class="alert alert-err" style="margin:12px 0 0">站点根目录不可写，静态文件无法生成。</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
