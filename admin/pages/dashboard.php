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
    'views'     => (int) val('SELECT COALESCE(SUM(views),0) FROM {p}article'),
);
$recent    = all('SELECT a.id, a.title, a.status, a.created_at, a.views, c.name AS cat_name
                  FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                  ORDER BY a.id DESC LIMIT 8');
$lastBuild = (int) setting('last_build', 0);
$staticNum = static_files_count();

/* 运行状态：只看两个必须可写的目录，避免把仪表盘做成运维面板 */
$rootOk  = is_writable(CMS_ROOT);
$dataOk  = is_writable(CMS_DATA);
$allOk   = $rootOk && $dataOk;
$autoOn  = setting('auto_build', '1') === '1';
$runSub  = $allOk
    ? '数据目录可写 · 自动生成' . ($autoOn ? '开启' : '关闭')
    : trim(($rootOk ? '' : '站点根不可写 ') . ($dataOk ? '' : '数据目录不可写'));

/* 快捷入口：纯线性图标 + 标签；10 项正好两行 */
$QUICK = array(
    array('写文章', admin_url('article', array('action' => 'new')), '<path d="M4 20h4L18 10a2.5 2.5 0 0 0-3.5-3.5L4.5 16.5z"/><path d="M13.5 6.5l4 4"/>'),
    array('栏目管理', admin_url('category'), '<path d="M3.5 7.5a2 2 0 0 1 2-2h3.6l2 2h7.4a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5.5a2 2 0 0 1-2-2z"/>'),
    array('单页管理', admin_url('page'), '<rect x="8.5" y="3.5" width="11" height="13" rx="2"/><path d="M16 20.5H6.5a2 2 0 0 1-2-2V7.5"/>'),
    array('媒体库', admin_url('media'), '<rect x="3" y="4.5" width="18" height="15" rx="3"/><circle cx="8.5" cy="10" r="1.5"/><path d="M4 16.5l4.5-3.6 3.4 2.6 2.6-2 4.5 3.4"/>'),
    array('生成静态', admin_url('build'), '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4v4.8h-4.8"/>'),
    array('数据系统', admin_url('data'), '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/>'),
    array('系统清理', admin_url('clean'), '<path d="M4.5 7h15"/><path d="M9.5 7V5.5a1.5 1.5 0 0 1 1.5-1.5h2a1.5 1.5 0 0 1 1.5 1.5V7"/><path d="M6.5 7l.8 11.4a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9L17.5 7"/>'),
    array('模板管理', admin_url('theme'), '<rect x="3" y="4.5" width="18" height="15" rx="3"/><path d="M3 10h18"/><path d="M10 10v9.5"/>'),
    array('友情链接', admin_url('flink'), '<path d="M10.5 13.5a3.6 3.6 0 0 0 5.1 0l2.6-2.6a3.6 3.6 0 0 0-5.1-5.1l-1.5 1.5"/><path d="M13.5 10.5a3.6 3.6 0 0 0-5.1 0l-2.6 2.6a3.6 3.6 0 0 0 5.1 5.1l1.5-1.5"/>'),
    array('系统设置', admin_url('setting'), '<path d="M4 7.5h7M15.5 7.5h4.5M4 16.5h4M12.5 16.5h7.5"/><circle cx="13" cy="7.5" r="2.2"/><circle cx="10" cy="16.5" r="2.2"/>'),
);
?>

<div class="stats">
    <div class="stat accent">
        <div class="stat-top">
            <span class="label">已发布文章</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8z"/><path d="M14 3.5V8h4.5"/><path d="M9 12.5h6M9 16.5h4"/></svg></span>
        </div>
        <div class="num"><?php echo $stat['published']; ?></div>
        <div class="sub">草稿 <?php echo $stat['drafts']; ?> 篇</div>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span class="label">累计浏览</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg></span>
        </div>
        <div class="num"><?php echo $stat['views']; ?></div>
        <div class="sub">静态页面上报</div>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span class="label">静态文件</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M12 3l8 4.5-8 4.5-8-4.5z"/><path d="M4 12l8 4.5 8-4.5"/><path d="M4 16.5L12 21l8-4.5"/></svg></span>
        </div>
        <div class="num"><?php echo $staticNum; ?></div>
        <div class="sub"><?php echo $lastBuild ? e(human_date($lastBuild)) . ' · ' . (int) setting('last_build_ms', 0) . ' ms' : '尚未生成'; ?></div>
    </div>
    <div class="stat<?php echo $allOk ? '' : ' warn'; ?>">
        <div class="stat-top">
            <span class="label">运行状态</span>
            <span class="stat-icon"><svg viewBox="0 0 24 24"><path d="M3 12h4l2.5-6 4 12 2.5-6h5"/></svg></span>
        </div>
        <div class="num"><?php echo $allOk ? '正常' : '异常'; ?></div>
        <div class="sub"><?php echo e($runSub); ?></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>快捷入口</h2>
    </div>
    <div class="card-body">
        <div class="quick-grid">
            <?php foreach ($QUICK as $q): ?>
                <a class="quick-item" href="<?php echo e($q[1]); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $q[2]; ?></svg>
                    <span><?php echo e($q[0]); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

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
                        <td style="color:#63636E"><?php echo e($r['cat_name'] ? $r['cat_name'] : '未分类'); ?></td>
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
