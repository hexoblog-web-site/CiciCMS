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
                  ORDER BY a.id DESC LIMIT 6');
$lastBuild = (int) setting('last_build', 0);
$staticNum = static_files_count();

/* 近 7 日发布趋势：只取 created_at，在 PHP 里按天分桶，避开各版本 MySQL 日期函数差异 */
$trendSince = strtotime('today') - 6 * 86400;
$trendRows  = (array) all('SELECT created_at FROM {p}article WHERE created_at >= ?', array($trendSince));
$trend      = array_fill(0, 7, 0);
foreach ($trendRows as $tr) {
    $d = (int) floor(((int) $tr['created_at'] - $trendSince) / 86400);
    if ($d >= 0 && $d < 7) {
        $trend[$d]++;
    }
}
$weekTotal = array_sum($trend);

/* 运行状态：只看两个必须可写的目录，避免把仪表盘做成运维面板 */
$rootOk  = is_writable(CMS_ROOT);
$dataOk  = is_writable(CMS_DATA);
$allOk   = $rootOk && $dataOk;
$autoOn  = setting('auto_build', '1') === '1';
$runSub  = $allOk
    ? '数据目录可写'
    : trim(($rootOk ? '' : '站点根不可写 ') . ($dataOk ? '' : '数据目录不可写'));

/* 待生成队列：后台任何页面都会续跑，这里只显示还剩多少 */
$pending    = function_exists('build_pending') ? build_pending() : null;
$pendingNum = !empty($pending) && isset($pending['total']) ? (int) $pending['total'] : 0;

/* 版本状态 */
$hasUpdate = update_available_cached();
$upVer     = '';
if ($hasUpdate) {
    $upState = update_state_read();
    $upVer   = isset($upState['remote']['version']) ? (string) $upState['remote']['version'] : '';
}
?>

<?php if ($hasUpdate):
    $uState = update_state_read();
    $uMsg   = isset($uState['remote']['msg']) ? (string) $uState['remote']['msg'] : '';
?>
<div class="alert alert-warn update-banner">
    <div>
        <b>发现新版本<?php echo $upVer !== '' ? ' ' . e($upVer) : ''; ?></b>
        <span class="update-banner-sub">当前 <?php echo e(CMS_VERSION); ?><?php echo $uMsg !== '' ? ' · ' . e(str_limit($uMsg, 40)) : ''; ?></span>
    </div>
    <a class="btn btn-sm btn-primary" href="<?php echo e(admin_url('update')); ?>">查看并更新</a>
</div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <div class="stat-top">
            <span class="label">已发布文章</span>
            <span class="stat-icon"><?php echo cms_icon('#i-article'); ?></span>
        </div>
        <div class="num"><?php echo $stat['published']; ?></div>
        <div class="sub">草稿 <?php echo $stat['drafts']; ?> 篇 · 近 7 日新增 <?php echo (int) $weekTotal; ?> 篇</div>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span class="label">累计浏览</span>
            <span class="stat-icon"><?php echo cms_icon('#i-eye'); ?></span>
        </div>
        <div class="num"><?php echo number_format((int) $stat['views']); ?></div>
        <div class="sub">静态页面上报</div>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span class="label">静态文件</span>
            <span class="stat-icon"><?php echo cms_icon('#i-layers'); ?></span>
        </div>
        <div class="num"><?php echo $staticNum; ?></div>
        <div class="sub"><?php echo $lastBuild ? e(human_date($lastBuild)) . ' · ' . (int) setting('last_build_ms', 0) . ' ms' : '尚未生成'; ?></div>
    </div>
    <div class="stat<?php echo $allOk ? '' : ' warn'; ?>">
        <div class="stat-top">
            <span class="label">运行状态</span>
            <span class="stat-icon"><?php echo cms_icon('#i-activity'); ?></span>
        </div>
        <div class="num"><?php echo $allOk ? '正常' : '异常'; ?></div>
        <div class="sub"><?php echo e($runSub); ?></div>
    </div>
</div>

<div class="dash-grid">
    <div class="dash-main">
        <div class="card">
            <div class="card-head">
                <h2>最近文章</h2>
                <a class="btn btn-sm" href="<?php echo e(admin_url('article')); ?>">全部文章</a>
            </div>
            <div class="card-body tight">
                <?php if (empty($recent)): ?>
                    <div class="empty">
                        还没有文章，<a href="<?php echo e(admin_url('article', array('action' => 'new'))); ?>">写第一篇</a>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                        <tr><th>标题</th><th>分类</th><th>状态</th><th>浏览</th><th>时间</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><a href="<?php echo e(admin_url('article', array('action' => 'edit', 'id' => $r['id']))); ?>"><?php echo e(str_limit($r['title'], 40)); ?></a></td>
                                <td class="cell-sub"><?php echo e($r['cat_name'] ? $r['cat_name'] : '未分类'); ?></td>
                                <td>
                                    <?php if ((int) $r['status'] === 1): ?>
                                        <span class="badge badge-green">已发布</span>
                                    <?php else: ?>
                                        <span class="badge badge-gray">草稿</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-sub"><?php echo (int) $r['views']; ?></td>
                                <td class="cell-sub"><?php echo e(format_date($r['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dash-side">
        <div class="card">
            <div class="card-head"><h2>站点状态</h2></div>
            <div class="card-body">
                <div class="kv"><span>静态文件</span><span><?php echo $staticNum; ?> 个</span></div>
                <div class="kv"><span>上次生成</span><span><?php echo $lastBuild ? e(human_date($lastBuild)) : '尚未生成'; ?></span></div>
                <div class="kv"><span>自动生成</span><span><?php echo $autoOn ? '已开启' : '已关闭'; ?></span></div>
                <div class="kv"><span>待生成队列</span><span><?php echo $pendingNum; ?> 项</span></div>
                <div class="kv"><span>目录权限</span><span><?php echo $allOk ? '正常' : '<b style="color:var(--state-err)">不可写</b>'; ?></span></div>
            </div>
            <div class="card-foot">
                <a class="btn btn-primary btn-block" href="<?php echo e(admin_url('build')); ?>">重新生成全站</a>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>待处理</h2></div>
            <div class="card-body">
                <div class="kv">
                    <span>草稿文章</span>
                    <span><a href="<?php echo e(admin_url('article', array('status' => '0'))); ?>"><?php echo $stat['drafts']; ?> 篇</a></span>
                </div>
                <div class="kv">
                    <span>系统更新</span>
                    <span>
                        <?php if ($hasUpdate): ?>
                            <a href="<?php echo e(admin_url('update')); ?>" style="color:var(--state-warn)">有新版<?php echo $upVer !== '' ? ' ' . e($upVer) : ''; ?></a>
                        <?php else: ?>
                            已是最新
                        <?php endif; ?>
                    </span>
                </div>
                <div class="kv">
                    <span>未引用附件</span>
                    <span><a href="<?php echo e(admin_url('clean')); ?>">去检查</a></span>
                </div>
            </div>
        </div>
    </div>
</div>
