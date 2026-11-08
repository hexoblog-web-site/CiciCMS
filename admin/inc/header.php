<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/icons.php';

/* 后台外观按 Z-BlogPHP 官方后台复刻：
 *   body > header.header(.logo/.user/.menu#topmenu)
 *        > aside.left#leftmenu
 *        > section.main > #divMain > .divHeader + 页面内容
 * 官方 admin2.css / icon.css / admin.css(登录) 原样放在 assets/zblog 下，
 * 这里只负责输出与官方模板一致的 DOM，业务逻辑与路由一律不动。
 */
$user = auth_user();

/* CiciCMS 的 SVG 图标 id → Z-Blog 图标字体 class。
 * 插件仍按老约定传 '#i-xxx' 或内联 path；识别不到时回落到通用图标。 */
$ZICON = array(
    '#i-dashboard' => 'icon-house',
    '#i-category'  => 'icon-folder',
    '#i-page'      => 'icon-file-earmark-text',
    '#i-media'     => 'icon-image',
    '#i-flink'     => 'icon-link',
    '#i-build'     => 'icon-arrow-repeat',
    '#i-clean'     => 'icon-trash',
    '#i-data'      => 'icon-archive',
    '#i-theme'     => 'icon-brush',
    '#i-plugin'    => 'icon-plug',
    '#i-update'    => 'icon-cloud-download',
    '#i-setting'   => 'icon-gear',
    '#i-write'     => 'icon-pencil-square',
    '#i-article'   => 'icon-card-text',
    '#i-square'    => 'icon-app-indicator',
);
$zicon = function ($ref) use ($ZICON) {
    if (is_string($ref) && isset($ref[0]) && $ref[0] === '#' && isset($ZICON[$ref])) {
        return $ZICON[$ref];
    }
    return 'icon-app-indicator';
};

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
/* 插件可以增删菜单：cms_add_filter('admin_menu', function ($nav) { ... }) */
$NAV = (array) cms_apply_filters('admin_menu', $NAV);

/* 左侧菜单分组，与 Z-Blog 的 li.sub 分组标题一一对应 */
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

/* 有新版时顶部菜单打点；只读本地状态文件，不联网 */
$updateDot = function_exists('update_available_cached') ? update_available_cached() : false;
$updateVer = '';
if ($updateDot) {
    $updateState = update_state_read();
    $updateVer   = isset($updateState['remote']['version']) ? (string) $updateState['remote']['version'] : '';
}

$curAction = isset($_GET['action']) ? (string) $_GET['action'] : '';

$pageTitle = isset($NAV[$p][0]) ? (string) $NAV[$p][0] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['title'] : '管理后台');
$pageSub   = isset($SUBTITLE[$p]) ? $SUBTITLE[$p] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['sub'] : '');
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
$actionOn = '';
if ($p === 'article') {
    $actionOn = ($curAction === 'new') ? 'new' : 'list';
}

/* 页头动作槽：功能入口必须保留，样式由 Z-Blog 皮肤承接 */
$HEAD_ACTIONS = array(
    'article'  => array(array('添加文章', admin_url('article',  array('action' => 'new')), false)),
    'page'     => array(array('新建单页', admin_url('page',     array('action' => 'new')), true)),
    'category' => array(array('新建栏目', admin_url('category', array('action' => 'new')), true)),
    'flink'    => array(array('新增链接', admin_url('flink',    array('action' => 'new')), true)),
);
if ($curAction !== '' && $p !== 'dashboard') {
    $HEAD_ACTIONS[$p] = array(array('← 返回列表', admin_url($p), false));
}
$headActs = isset($HEAD_ACTIONS[$p]) ? $HEAD_ACTIONS[$p] : array();

/* 顶部主菜单：与 Z-Blog 的 #topmenu 一致，白底 tab 靠右 */
$TOPMENU = array(
    array('首页',     'icon-house',            admin_url('dashboard'), 'dashboard'),
    array('新建文章', 'icon-pencil-square',   admin_url('article', array('action' => 'new')), 'article-new'),
    array('文章管理', 'icon-file-earmark-text', admin_url('article'), 'article'),
    array('分类管理', 'icon-folder',          admin_url('category'), 'category'),
    array('附件管理', 'icon-image',           admin_url('media'), 'media'),
    array('生成静态', 'icon-arrow-repeat',    admin_url('build'),  'build'),
    array('应用管理', 'icon-plug',            admin_url('plugin'), 'plugin'),
    array('网站设置', 'icon-gear',            admin_url('setting'), 'setting'),
);
$TOP_ALIAS = array(
    'dashboard' => 'dashboard', 'article' => 'article', 'category' => 'category',
    'page' => 'category', 'media' => 'media', 'flink' => 'media',
    'build' => 'build', 'clean' => 'build', 'theme' => 'plugin',
    'plugin' => 'plugin', 'data' => 'setting', 'update' => 'setting', 'setting' => 'setting',
);
$topOn = isset($TOP_ALIAS[$p]) ? $TOP_ALIAS[$p] : '';
if ($p === 'article' && $curAction === 'new') {
    $topOn = 'article-new';
}

/** 渲染一个 Z-Blog 原生左侧菜单 li */
$renderNavItem = function ($key) use ($NAV, $p, $PLUGIN_PAGE, $updateDot, $actionOn, $zicon) {
    if ($key === 'article-list') {
        $isOn = ($actionOn === 'list');
        return '<li' . ($isOn ? ' class="on"' : '') . '><a href="' . e(admin_url('article')) . '"'
             . ($isOn ? ' aria-current="page"' : '') . '>'
             . '<span><i class="icon-pencil-square"></i>编辑文章</span></a></li>';
    }
    if (!isset($NAV[$key])) {
        return '';
    }
    $item  = $NAV[$key];
    $label = isset($item[0]) ? (string) $item[0] : (string) $key;
    $icon  = isset($item[1]) && $item[1] !== '' ? (string) $item[1] : '#i-square';
    $on    = ($p === $key) || ($PLUGIN_PAGE !== null && $key === 'plugin' && !isset($NAV[$p]));
    return '<li' . ($on ? ' class="on"' : '') . '><a href="' . e(admin_url($key)) . '"'
         . ($on ? ' aria-current="page"' : '') . '>'
         . '<span><i class="' . e($zicon($icon)) . '"></i>' . e($label) . '</span>'
         . ($key === 'update' && $updateDot ? '<i class="nav-dot" title="有新版本"></i>' : '')
         . '</a></li>';
};

$pageIcon = isset($NAV[$p][1]) ? $zicon($NAV[$p][1]) : 'icon-house';
if ($p === 'article') { $pageIcon = 'icon-pencil-square'; }
$zb = site_url('admin/assets/zblog');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="cms-token" content="<?php echo e(csrf_token()); ?>">
<meta name="cms-upload" content="upload.php">
<title><?php echo e($pageTitle); ?> - <?php echo e(setting('site_name', 'CiciCMS')); ?> 管理后台</title>
<link rel="stylesheet" href="<?php echo e($zb . '/css/admin2.css'); ?>">
<link rel="stylesheet" href="<?php echo e($zb . '/image/icon/icon.css'); ?>">
<link rel="stylesheet" href="<?php echo e(site_url('admin/assets/admin.css')); ?>?v=<?php echo (int) @filemtime(CMS_ROOT . '/admin/assets/admin.css'); ?>">
<link rel="stylesheet" href="<?php echo e(site_url('admin/assets/zblog-skin.css')); ?>?v=<?php echo (int) @filemtime(CMS_ROOT . '/admin/assets/zblog-skin.css'); ?>">
<?php cms_do_action('admin_head'); ?>
</head>
<body class="body-admin admin-<?php echo e($p); ?>">
<?php echo cms_icon_sprite(); ?>
<header class="header">
    <div class="logo">
        <a href="<?php echo e(site_url()); ?>" title="<?php echo e(setting('site_name', 'CiciCMS')); ?>" target="_blank">
            <img src="<?php echo e($zb . '/image/admin/none.gif'); ?>" alt="Z-Blog" />
        </a>
    </div>
    <div class="user">
        <img src="<?php echo e($zb . '/image/admin/avatar.png'); ?>" width="40" height="40" id="avatar" alt="Avatar" />
        <div class="username"><span>管理员：<?php echo e($user['username']); ?></span></div>
        <div class="userbtn">
            <a class="profile" href="<?php echo e(site_url()); ?>" target="_blank"><i class="icon-globe2"></i> <span>返回网站</span></a>
            <a class="logout" href="#" onclick="document.getElementById('zb-logout').submit();return false;"><i class="icon-power"></i> <span>退出登录</span></a>
        </div>
    </div>
    <div class="menu">
        <ul id="topmenu">
            <?php foreach ($TOPMENU as $ti): ?>
                <li<?php echo $topOn === $ti[3] ? ' class="on"' : ''; ?>>
                    <a href="<?php echo e($ti[2]); ?>"><i class="<?php echo e($ti[1]); ?>"></i><span><?php echo e($ti[0]); ?></span></a>
                </li>
            <?php endforeach; ?>
            <?php if ($updateDot): ?>
                <li class="zb-update"><a href="<?php echo e(admin_url('update')); ?>"><i class="icon-cloud-download"></i><span>发现新版本<?php echo $updateVer !== '' ? ' ' . e($updateVer) : ''; ?></span></a></li>
            <?php endif; ?>
        </ul>
    </div>
</header>
<form method="post" action="index.php" id="zb-logout" style="display:none">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="do" value="logout">
</form>

<aside class="left">
    <ul id="leftmenu">
        <?php foreach ($NAV_GROUPS as $groupLabel => $groupKeys): ?>
            <?php
            $visible = array();
            foreach ($groupKeys as $gk) {
                if ($gk === 'article-list' || isset($NAV[$gk])) {
                    $visible[] = $gk;
                }
            }
            if (!$visible) { continue; }
            ?>
            <li class="sub"><span><?php echo e($groupLabel); ?></span></li>
            <?php foreach ($visible as $gk) { echo $renderNavItem($gk); } ?>
        <?php endforeach; ?>

        <?php
        $restKeys = array();
        foreach ($NAV as $nk => $nv) {
            if (!in_array($nk, $groupedKeys, true)) {
                $restKeys[] = $nk;
            }
        }
        if ($restKeys):
        ?>
            <li class="sub"><span>扩展</span></li>
            <?php foreach ($restKeys as $rk) { echo $renderNavItem($rk); } ?>
        <?php endif; ?>
    </ul>
</aside>

<section class="main">
<div id="divMain">
    <div class="divHeader"><i class="<?php echo e($pageIcon); ?>"></i><span><?php echo e($pageTitle); ?></span></div>

    <?php if ($pageSub !== '' || $headActs): ?>
        <div class="zb-pagebar">
            <?php if ($pageSub !== ''): ?><span class="zb-pagebar-sub"><?php echo e($pageSub); ?></span><?php endif; ?>
            <?php if ($headActs): ?>
                <span class="zb-pagebar-acts">
                    <?php foreach ($headActs as $act): ?>
                        <a class="btn<?php echo $act[2] ? ' btn-primary' : ''; ?>" href="<?php echo e($act[1]); ?>"><?php echo e($act[0]); ?></a>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php foreach (flashes() as $f): ?>
        <div class="hint"><p class="<?php echo $f['type'] === 'err' ? 'hint_bad' : 'hint_good'; ?>"><?php echo e($f['msg']); ?></p></div>
    <?php endforeach; ?>
