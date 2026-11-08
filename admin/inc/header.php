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
    'article'   => '从列表选一篇文章修改，也可检索与管理',
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
    'setting'   => '站点与安全选项',
);
/* 插件可以增删菜单：cms_add_filter('admin_menu', function ($nav) { ... })
 * 菜单项格式为 key => array(标题, SVG 内联图标)；icon 省略时用默认方块，避免布局被撑坏。 */
$NAV = (array) cms_apply_filters('admin_menu', $NAV);

/* 侧栏分组。$NAV 的 key 必须对应 admin/pages/{key}.php，插件的 admin_menu 钩子也按这个结构来，
 * 所以「文章列表」这种带 action 的地址不进 $NAV，只在这里用 article-list 这个虚拟 key 占位。
 * 分组只影响显示顺序，未出现在任何分组的 key（插件新增项）会自动落到「扩展」组，不会丢。 */
$NAV_GROUPS = array(
    '工作台' => array('dashboard'),
    '内容'   => array('article-list', 'category', 'page', 'media', 'flink'),
    '站点'   => array('build', 'theme', 'plugin'),
    '系统'   => array('data', 'clean', 'update', 'setting'),
);
$groupedKeys = array();
foreach ($NAV_GROUPS as $gk => $gv) {
    $groupedKeys = array_merge($groupedKeys, $gv);
}

$PLUGIN_PAGE = isset($PLUGIN_PAGE) ? $PLUGIN_PAGE : null;

/* 有新版时菜单上打个点；只读本地状态文件，不联网 */
$updateDot = function_exists('update_available_cached') ? update_available_cached() : false;
$updateVer = '';
if ($updateDot) {
    $updateState = update_state_read();
    $updateVer   = isset($updateState['remote']['version']) ? (string) $updateState['remote']['version'] : '';
}

$curAction = isset($_GET['action']) ? (string) $_GET['action'] : '';

$pageTitle = isset($NAV[$p][0]) ? (string) $NAV[$p][0] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['title'] : '管理后台');
$pageSub   = isset($SUBTITLE[$p]) ? $SUBTITLE[$p] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['sub'] : '');
/* article 已从 $NAV 移除，页面标题必须单独给，否则列表态会 fallback 成「管理后台」。
 * 新建 / 编辑 / 列表三态各给一个准确名字，与侧栏条目对得上。 */
if ($p === 'article') {
    if ($curAction === 'new') {
        $pageTitle = '添加文章';
        $pageSub   = '填标题、栏目和正文，保存后到编辑页补属性';
    } elseif ($curAction === 'edit') {
        $pageTitle = '编辑文章';
        $pageSub   = '内容与属性、封面、附件都在这里修改';
    } else {
        $pageTitle = '编辑文章';
        $pageSub   = '从列表选一篇文章修改，也可检索与管理';
    }
}
/* 文章页的侧栏高亮：新建态亮主按钮，其余亮「编辑文章」 */
$actionOn = '';
if ($p === 'article') {
    $actionOn = ($curAction === 'new') ? 'new' : 'list';
}

/* 页头动作槽：只放「本页专属」的主操作。
 * 仪表盘与文章列表不放「添加文章」——侧栏顶部已经有同一个主按钮，重复一遍只是噪音；
 * 编辑/新建态统一换成「返回列表」，页头不留空，也不和表单底部的保存按钮抢注意力。 */
$HEAD_ACTIONS = array(
    'page'      => array(array('新建单页', admin_url('page',     array('action' => 'new')), true)),
    'category'  => array(array('新建栏目', admin_url('category', array('action' => 'new')), true)),
    'flink'     => array(array('新增链接', admin_url('flink',    array('action' => 'new')), true)),
);
if ($curAction !== '' && $p !== 'dashboard') {
    $HEAD_ACTIONS[$p] = array(array('← 返回列表', admin_url($p), false));
}
$headActs = isset($HEAD_ACTIONS[$p]) ? $HEAD_ACTIONS[$p] : array();

/**
 * 渲染一个侧栏条目。
 * $key 为 article-list 时代理「编辑文章」列表页，其余按 $NAV 取标题与图标。
 */
$renderNavItem = function ($key) use ($NAV, $p, $PLUGIN_PAGE, $updateDot, $actionOn) {
    if ($key === 'article-list') {
        $isOn = ($actionOn === 'list');
        return '<a class="' . ($isOn ? 'on' : '') . '" href="' . e(admin_url('article')) . '"'
             . ($isOn ? ' aria-current="page"' : '') . '>'
             . cms_icon('#i-write') . '<span>编辑文章</span></a>';
    }
    if (!isset($NAV[$key])) {
        return '';
    }
    $item  = $NAV[$key];
    $label = isset($item[0]) ? (string) $item[0] : (string) $key;
    $icon  = isset($item[1]) && $item[1] !== '' ? (string) $item[1] : '#i-square';
    $on    = ($p === $key) || ($PLUGIN_PAGE !== null && $key === 'plugin' && !isset($NAV[$p]));
    return '<a class="' . ($on ? 'on' : '') . '" href="' . e(admin_url($key)) . '"'
         . ($on ? ' aria-current="page"' : '') . '>'
         . cms_icon($icon) . '<span>' . e($label) . '</span>'
         . ($key === 'update' && $updateDot ? '<i class="nav-dot" title="有新版本"></i>' : '')
         . '</a>';
};
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
        var a = localStorage.getItem('cici-accent');
        if (['blue', 'teal', 'green', 'purple', 'amber'].indexOf(a) < 0) { a = 'blue'; }
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.setAttribute('data-density', d);
        document.documentElement.setAttribute('data-accent', a);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
        document.documentElement.setAttribute('data-accent', 'blue');
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

        <?php /* 全站唯一的主操作位：写文章。其余入口都归到下面的分组菜单里。 */ ?>
        <a class="side-cta" href="<?php echo e(admin_url('article', array('action' => 'new'))); ?>">
            <?php echo cms_icon('#i-plus'); ?><span>添加文章</span>
        </a>

        <nav class="nav" aria-label="后台主导航">
            <?php foreach ($NAV_GROUPS as $groupLabel => $groupKeys): ?>
                <?php
                $visible = array();
                foreach ($groupKeys as $gk) {
                    if ($gk === 'article-list' || isset($NAV[$gk])) {
                        $visible[] = $gk;
                    }
                }
                if (!$visible) {
                    continue;   /* 插件把整组菜单都删了，就不输出空分组标题 */
                }
                ?>
                <div class="nav-group"><?php echo e($groupLabel); ?></div>
                <?php foreach ($visible as $gk) { echo $renderNavItem($gk); } ?>
            <?php endforeach; ?>

            <?php
            /* 插件新增但没归到任何分组的菜单，兜底放在「扩展」里，保证入口不丢 */
            $restKeys = array();
            foreach ($NAV as $nk => $nv) {
                if (!in_array($nk, $groupedKeys, true)) {
                    $restKeys[] = $nk;
                }
            }
            if ($restKeys):
            ?>
                <div class="nav-group">扩展</div>
                <?php foreach ($restKeys as $rk) { echo $renderNavItem($rk); } ?>
            <?php endif; ?>
        </nav>

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
    <div class="sidebar-mask" id="sidebar-mask"
         onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('on')"></div>

    <div class="main">
        <header class="topbar">
            <button class="menu-btn" type="button" aria-label="展开导航"
                    onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebar-mask').classList.toggle('on')">☰</button>
            <div>
                <h1><?php echo e($pageTitle); ?></h1>
                <div class="page-sub">
                    <?php if ($pageSub !== ''): ?><span class="page-sub-text"><?php echo e($pageSub); ?></span><?php endif; ?>
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
                <?php /* 配色切换：只换品牌色令牌，和深浅主题、密度互不影响 */ ?>
                <div class="top-pop">
                    <button class="icon-btn i-accent" type="button" data-accent-toggle aria-haspopup="true" aria-expanded="false" title="切换配色方案" aria-label="切换配色方案">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 3a9 9 0 1 0 0 18c1.2 0 1.9-.8 1.9-1.8 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4c0-3.9-4-7-9-7z"/>
                            <circle cx="8.2" cy="10.2" r="1" fill="currentColor" stroke="none"/>
                            <circle cx="12" cy="7.8" r="1" fill="currentColor" stroke="none"/>
                            <circle cx="15.8" cy="10.2" r="1" fill="currentColor" stroke="none"/>
                        </svg>
                    </button>
                    <div class="accent-menu" data-accent-menu hidden>
                        <div class="menu-cap">配色方案</div>
                        <?php foreach (array('blue' => '经典蓝', 'teal' => '青碧', 'green' => '松绿', 'purple' => '雅紫', 'amber' => '琥珀') as $ak => $an): ?>
                            <button class="accent-item" type="button" data-accent-set="<?php echo e($ak); ?>" aria-pressed="false">
                                <span class="accent-dot" aria-hidden="true"></span>
                                <span><?php echo e($an); ?></span>
                                <svg class="accent-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <span class="user-chip"><i class="user-dot" aria-hidden="true"></i><span class="user-name"><?php echo e($user['username']); ?></span></span>
            </div>
        </header>
        <div class="content">
            <?php foreach (flashes() as $f): ?>
                <div class="alert <?php echo $f['type'] === 'err' ? 'alert-err' : 'alert-ok'; ?>"><?php echo e($f['msg']); ?></div>
            <?php endforeach; ?>
