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
$trendMax = max(1, max($trend));

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
    array('写文章', admin_url('article', array('action' => 'new')), '#i-write'),
    array('栏目管理', admin_url('category'), '#i-category'),
    array('单页管理', admin_url('page'), '#i-page'),
    array('媒体信息', admin_url('media'), '#i-media'),
    array('生成静态', admin_url('build'), '#i-build'),
    array('数据系统', admin_url('data'), '#i-data'),
    array('系统清理', admin_url('clean'), '#i-clean'),
    array('模板管理', admin_url('theme'), '#i-theme'),
    array('友情链接', admin_url('flink'), '#i-flink'),
    array('系统设置', admin_url('setting'), '#i-setting'),
);
?>

<?php if (update_available_cached()):
    $uState = update_state_read();
    $uVer   = isset($uState['remote']['version']) ? (string) $uState['remote']['version'] : '';
    $uMsg   = isset($uState['remote']['msg']) ? (string) $uState['remote']['msg'] : '';
?>
<div class="alert alert-warn update-banner">
    <div>
        <b>发现新版本<?php echo $uVer !== '' ? ' ' . e($uVer) : ''; ?></b>
        <span class="update-banner-sub">当前 <?php echo e(CMS_VERSION); ?><?php echo $uMsg !== '' ? ' · ' . e(str_limit($uMsg, 40)) : ''; ?></span>
    </div>
    <a class="btn btn-sm btn-primary" href="<?php echo e(admin_url('update')); ?>">查看并更新</a>
</div>
<?php endif; ?>

<div class="stats">
    <div class="stat accent">
        <div class="stat-top">
            <span class="label">已发布文章</span>
            <span class="stat-icon"><?php echo cms_icon('#i-article'); ?></span>
        </div>
        <div class="num"><?php echo $stat['published']; ?></div>
        <div class="sub">草稿 <?php echo $stat['drafts']; ?> 篇</div>
    </div>
    <div class="stat">
        <div class="stat-top">
            <span class="label">累计浏览</span>
            <span class="stat-icon"><?php echo cms_icon('#i-eye'); ?></span>
        </div>
        <div class="num"><?php echo $stat['views']; ?></div>
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

<div class="card">
    <div class="card-head">
        <h2>快捷入口</h2>
    </div>
    <div class="card-body">
        <div class="quick-grid">
            <?php foreach ($QUICK as $q): ?>
                <a class="quick-item" href="<?php echo e($q[1]); ?>">
                    <?php echo cms_icon($q[2]); ?>
                    <span><?php echo e($q[0]); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>近 7 日发布</h2>
        <span class="hint" style="margin:0">共 <?php echo array_sum($trend); ?> 篇 · 按创建时间统计</span>
    </div>
    <div class="card-body">
        <div class="trend-wrap" role="img" aria-label="最近 7 天每天新增文章数">
            <?php for ($i = 0; $i < 7; $i++):
                $n      = (int) $trend[$i];
                $height = $n > 0 ? max(8, (int) round($n / $trendMax * 56)) : 3;
                $day    = $i === 6 ? '今天' : date('n/j', $trendSince + $i * 86400);
            ?>
                <div class="trend-col<?php echo $i === 6 ? ' today' : ''; ?>">
                    <span class="trend-n"><?php echo $n; ?></span>
                    <div class="trend-bar" style="height:<?php echo $height; ?>px" title="<?php echo e($day . '：' . $n . ' 篇'); ?>"><i></i></div>
                    <span class="trend-day"><?php echo e($day); ?></span>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>最近文章</h2>
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
                        <td class="cell-sub"><?php echo e($r['cat_name'] ? $r['cat_name'] : '未分类'); ?></td>
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
