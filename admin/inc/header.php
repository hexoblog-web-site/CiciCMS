<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/icons.php';

$user = auth_user();

/* 图标统一 24 视图框、线性描边，由 CSS 控制尺寸与颜色 */
$NAV = array(
    'dashboard' => array('仪表盘', '#i-dashboard'),
    'article'   => array('文章管理', '#i-article'),
    'category'  => array('栏目管理', '#i-category'),
    'page'      => array('单页管理', '#i-page'),
    'media'     => array('媒体信息', '#i-media'),
    'flink'     => array('友情链接', '#i-flink'),
    'build'     => array('生成静态', '#i-build'),
    'clean'     => array('系统清理', '#i-clean'),
    'data'      => array('数据系统', '#i-data'),
    'theme'     => array('模板管理', '#i-theme'),
    'plugin'    => array('插件管理', '#i-plugin'),
    'update'    => array('系统更新', '#i-update'),
    'setting'   => array('系统设置', '#i-setting'),
);
$SUBTITLE = array(
    'dashboard' => '内容概览与系统状态',
    'article'   => '发布、编辑与检索全部文章',
    'category'  => '栏目结构与静态目录',
    'page'      => '关于、联系等独立页面',
    'media'     => '图片与附件的上传、地址与大小',
    'flink'     => '首页友情链接位',
    'build'     => '把内容渲染为静态 HTML',
    'clean'     => '清理未被引用的图片与附件',
    'data'      => '数据库备份、恢复与导入',
    'theme'     => '模板文件与代码块样式',
    'plugin'    => '启用、停用与插件自带的后台页面',
    'update'    => '把 GitHub 上的新版本装到本地',
    'setting'   => '站点、账号与安全选项',
);
/* 插件可以增删菜单：cms_add_filter('admin_menu', function ($nav) { ... })
 * 菜单项格式为 key => array(标题, SVG 内联图标)；icon 省略时用默认方块，避免布局被撑坏。 */
$NAV = (array) cms_apply_filters('admin_menu', $NAV);

/* 侧栏分组：按「写内容 → 发静态 / 备数据 → 换外观 → 管系统」的工作流排布。
 * $NAV 本身仍是扁平 key=>item，插件通过 admin_menu 插入的项不会失效：
 * 未知 key 跟随它在数组中前一个已知项的分组（例如插在「文章管理」后面就归到内容组）。 */
$NAV_GROUPS = array(
    'content' => array('内容', array('dashboard', 'article', 'category', 'page', 'media', 'flink')),
    'publish' => array('发布与维护', array('build', 'clean', 'data')),
    'extend'  => array('外观与扩展', array('theme', 'plugin')),
    'system'  => array('系统', array('update', 'setting')),
);
$groupOfKey = array();
foreach ($NAV_GROUPS as $gk => $gv) {
    foreach ($gv[1] as $nk) {
        $groupOfKey[$nk] = $gk;
    }
}
$groupedNav = array();
$lastGroup = 'content';
foreach ($NAV as $key => $item) {
    if (isset($groupOfKey[$key])) {
        $lastGroup = $groupOfKey[$key];
    }
    if (!isset($groupedNav[$lastGroup])) {
        $groupedNav[$lastGroup] = array();
    }
    $groupedNav[$lastGroup][$key] = $item;
}
/* 当前组：核心页直接查表，插件后台页按菜单项实际落在哪一组判断 */
$currentGroup = isset($groupOfKey[$p]) ? $groupOfKey[$p] : 'extend';
foreach ($groupedNav as $gk => $items) {
    if (isset($items[$p])) {
        $currentGroup = $gk;
        break;
    }
}

$PLUGIN_PAGE = isset($PLUGIN_PAGE) ? $PLUGIN_PAGE : null;

/* 有新版时菜单上打个点；只读本地状态文件，不联网 */
$updateDot = function_exists('update_available_cached') ? update_available_cached() : false;
$updateVer = '';
if ($updateDot) {
    $updateState = update_state_read();
    $updateVer   = isset($updateState['remote']['version']) ? (string) $updateState['remote']['version'] : '';
}

$pageTitle = isset($NAV[$p][0]) ? (string) $NAV[$p][0] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['title'] : '管理后台');
$pageSub   = isset($SUBTITLE[$p]) ? $SUBTITLE[$p] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['sub'] : '');

/* 页头动作槽：列表页放主操作；编辑/新建态换成「返回列表」，
 * 页头不留空，也不和表单底部的保存按钮抢注意力。 */
$curAction = isset($_GET['action']) ? (string) $_GET['action'] : '';
$HEAD_ACTIONS = array(
    'dashboard' => array(array('写文章',   admin_url('article',  array('action' => 'new')), true)),
    'article'   => array(array('写文章',   admin_url('article',  array('action' => 'new')), true)),
    'page'      => array(array('新建单页', admin_url('page',     array('action' => 'new')), true)),
    'category'  => array(array('新建栏目', admin_url('category', array('action' => 'new')), true)),
    'flink'     => array(array('新增链接', admin_url('flink',    array('action' => 'new')), true)),
);
if ($curAction !== '' && $p !== 'dashboard') {
    $HEAD_ACTIONS[$p] = array(array('← 返回列表', admin_url($p), false));
}
$headActs = isset($HEAD_ACTIONS[$p]) ? $HEAD_ACTIONS[$p] : array();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="cms-token" content="<?php echo e(csrf_token()); ?>">
<meta name="cms-upload" content="upload.php">
<title><?php echo e($pageTitle); ?> - CiciCMS 管理后台</title>
<script>
/* 主题与密度在样式表加载前定好，避免刷新时闪一下白底 */
(function () {
    try {
        var t = localStorage.getItem('cici-theme');
        if (t !== 'light' && t !== 'dark') {
            t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        var d = localStorage.getItem('cici-density') === 'compact' ? 'compact' : 'comfortable';
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.setAttribute('data-density', d);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>
<link rel="stylesheet" href="<?php echo e(site_url('admin/assets/admin.css')); ?>?v=<?php echo (int) @filemtime(CMS_ROOT . '/admin/assets/admin.css'); ?>">
<?php cms_do_action('admin_head'); ?>
</head>
<body>
<?php echo cms_icon_sprite(); ?>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="logo-mark">C</span>
            <div>
                <div class="brand-name">CiciCMS</div>
                <div class="brand-sub">静态化 CMS v<?php echo e(CMS_VERSION); ?></div>
            </div>
        </div>
        <nav class="nav" aria-label="后台主导航">
            <?php foreach ($NAV_GROUPS as $gk => $gv):
                if (empty($groupedNav[$gk])) { continue; }
                $isCur = ($gk === $currentGroup);
            ?>
                <div class="nav-group<?php echo $isCur ? ' is-current' : ''; ?>" data-nav-group="<?php echo e($gk); ?>">
                    <button class="nav-group-title" type="button" data-nav-toggle="<?php echo e($gk); ?>"
                            aria-expanded="true" aria-controls="nav-g-<?php echo e($gk); ?>"
                            <?php echo $isCur ? 'disabled title="当前所在分组"' : 'title="折叠 / 展开这一组"'; ?>>
                        <span><?php echo e($gv[0]); ?></span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                    </button>
                    <div class="nav-group-items" id="nav-g-<?php echo e($gk); ?>">
                        <?php foreach ($groupedNav[$gk] as $key => $item):
                            $label = isset($item[0]) ? (string) $item[0] : (string) $key;
                            $icon  = isset($item[1]) && $item[1] !== '' ? (string) $item[1] : '#i-square';
                            $on    = ($p === $key) || ($PLUGIN_PAGE !== null && $key === 'plugin' && !isset($NAV[$p]));
                        ?>
                            <a class="<?php echo $on ? 'on' : ''; ?>" href="<?php echo e(admin_url($key)); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>>
                                <?php echo cms_icon($icon); ?>
                                <span><?php echo e($label); ?></span>
                                <?php if ($key === 'update' && $updateDot): ?><i class="nav-dot" title="有新版本"></i><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>
        <script>
        /* 折叠状态在导航渲染完立刻恢复，避免刷新时菜单闪一下再收起 */
        (function () {
            try {
                var list = (localStorage.getItem('cici-nav-collapsed') || '').split(',');
                Array.prototype.forEach.call(document.querySelectorAll('.nav-group:not(.is-current)'), function (g) {
                    if (list.indexOf(g.getAttribute('data-nav-group')) < 0) { return; }
                    var t = g.querySelector('[data-nav-toggle]'), items = g.querySelector('.nav-group-items');
                    if (items) { items.hidden = true; }
                    if (t) { t.setAttribute('aria-expanded', 'false'); }
                });
            } catch (e) {}
        })();
        </script>
        <div class="sidebar-foot">
            <div class="build-hint">
                已生成 <b><?php echo (int) static_files_count(); ?></b> 个静态文件
                <a href="<?php echo e(admin_url('build')); ?>">去生成 →</a>
            </div>
            <form method="post" action="index.php" onsubmit="return true;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="logout">
                <button class="logout" type="submit">退出登录</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="menu-btn" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
            <div>
                <h1><?php echo e($pageTitle); ?></h1>
                <div class="page-sub">
                    <span class="crumb-group"><?php echo e($NAV_GROUPS[$currentGroup][0]); ?></span>
                    <?php if ($pageSub !== ''): ?><?php echo e($pageSub); ?><?php endif; ?>
                </div>
            </div>
            <div class="top-actions">
                <?php if ($updateDot): ?>
                    <a class="top-ver" href="<?php echo e(admin_url('update')); ?>" title="有新版本，去系统更新查看">
                        发现新版本<?php echo $updateVer !== '' ? ' ' . e($updateVer) : ''; ?>
                    </a>
                <?php endif; ?>
                <a class="link" href="<?php echo e(site_url()); ?>" target="_blank">访问站点 ↗</a>
                <?php if ($headActs): ?>
                    <div class="page-actions">
                        <?php foreach ($headActs as $act): ?>
                            <a class="btn btn-sm<?php echo $act[2] ? ' btn-primary' : ''; ?>" href="<?php echo e($act[1]); ?>"><?php echo e($act[0]); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <button class="icon-btn i-theme" type="button" data-theme-toggle title="切换深浅主题" aria-label="切换深浅主题">
                    <svg class="i-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/></svg>
                    <svg class="i-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>
                <button class="icon-btn i-density" type="button" data-density-toggle title="切换紧凑 / 舒适密度" aria-label="切换紧凑或舒适密度" aria-pressed="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                </button>
                <span class="user-chip"><?php echo e($user['username']); ?></span>
            </div>
        </header>
        <div class="content">
            <?php foreach (flashes() as $f): ?>
                <div class="alert <?php echo $f['type'] === 'err' ? 'alert-err' : 'alert-ok'; ?>"><?php echo e($f['msg']); ?></div>
            <?php endforeach; ?>
