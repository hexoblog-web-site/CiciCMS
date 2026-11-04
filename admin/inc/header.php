<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

$user = auth_user();

/* 图标统一 24 视图框、线性描边，由 CSS 控制尺寸与颜色 */
$NAV = array(
    'dashboard' => array('仪表盘', '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>'),
    'article'   => array('文章管理', '<path d="M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8z"/><path d="M14 3.5V8h4.5"/><path d="M9 12.5h6M9 16.5h4"/>'),
    'category'  => array('栏目管理', '<path d="M3.5 7.5a2 2 0 0 1 2-2h3.6l2 2h7.4a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5.5a2 2 0 0 1-2-2z"/>'),
    'page'      => array('单页管理', '<rect x="8.5" y="3.5" width="11" height="13" rx="2"/><path d="M16 20.5H6.5a2 2 0 0 1-2-2V7.5"/>'),
    'media'     => array('媒体库', '<rect x="3.5" y="4.5" width="17" height="15" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="M4.5 17l5-4.2 3.8 3 2.8-2 4.4 3.4"/>'),
    'flink'     => array('友情链接', '<path d="M10.5 13.5a3.6 3.6 0 0 0 5.1 0l2.6-2.6a3.6 3.6 0 0 0-5.1-5.1l-1.5 1.5"/><path d="M13.5 10.5a3.6 3.6 0 0 0-5.1 0l-2.6 2.6a3.6 3.6 0 0 0 5.1 5.1l1.5-1.5"/>'),
    'build'     => array('生成静态', '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4v4.8h-4.8"/>'),
    'clean'     => array('系统清理', '<path d="M4.5 7h15"/><path d="M9.5 7V5.5a1.5 1.5 0 0 1 1.5-1.5h2a1.5 1.5 0 0 1 1.5 1.5V7"/><path d="M6.5 7l.8 11.4a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9L17.5 7"/>'),
    'data'      => array('数据系统', '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/>'),
    'theme'     => array('模板管理', '<rect x="3.5" y="4.5" width="17" height="15" rx="3"/><path d="M3.5 9.8h17"/><path d="M9.8 9.8v9.7"/>'),
    'plugin'    => array('插件管理', '<path d="M9 3.5v4M15 3.5v4"/><path d="M6.5 7.5h11v4.2a5.5 5.5 0 0 1-11 0z"/><path d="M12 17v3.5"/>'),
    'update'    => array('系统更新', '<path d="M7.3 17.5a4 4 0 0 1-.4-7.98 5.5 5.5 0 0 1 10.66-.7A3.7 3.7 0 0 1 17.8 17.5"/><path d="M12 11.2v6.2"/><path d="M9.7 15 12 17.3 14.3 15"/>'),
    'setting'   => array('系统设置', '<path d="M4 7.5h7M15.5 7.5h4.5M4 16.5h4M12.5 16.5h7.5"/><circle cx="13" cy="7.5" r="2.2"/><circle cx="10" cy="16.5" r="2.2"/>'),
);
$SUBTITLE = array(
    'dashboard' => '内容概览与系统状态',
    'article'   => '发布、编辑与检索全部文章',
    'category'  => '栏目结构与静态目录',
    'page'      => '关于、联系等独立页面',
    'media'     => '已上传的图片与附件',
    'flink'     => '首页友情链接位',
    'build'     => '把内容渲染为静态 HTML',
    'clean'     => '清理无用附件与缓存',
    'data'      => '数据库备份、恢复与导入',
    'theme'     => '模板文件与代码块样式',
    'plugin'    => '启用、停用与插件自带的后台页面',
    'update'    => '把 GitHub 上的新版本装到本地',
    'setting'   => '站点、账号与安全选项',
);
/* 插件可以增删菜单：cms_add_filter('admin_menu', function ($nav) { ... })
 * 菜单项格式为 key => array(标题, SVG 内联图标)；icon 省略时用默认方块，避免布局被撑坏。 */
$NAV = (array) cms_apply_filters('admin_menu', $NAV);

$PLUGIN_PAGE = isset($PLUGIN_PAGE) ? $PLUGIN_PAGE : null;

/* 有新版时菜单上打个点；只读本地状态文件，不联网 */
$updateDot = function_exists('update_available_cached') ? update_available_cached() : false;

$pageTitle = isset($NAV[$p][0]) ? (string) $NAV[$p][0] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['title'] : '管理后台');
$pageSub   = isset($SUBTITLE[$p]) ? $SUBTITLE[$p] : ($PLUGIN_PAGE !== null ? $PLUGIN_PAGE['sub'] : '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="cms-token" content="<?php echo e(csrf_token()); ?>">
<meta name="cms-upload" content="upload.php">
<title><?php echo e($pageTitle); ?> - CiciCMS 管理后台</title>
<link rel="stylesheet" href="<?php echo e(site_url('admin/assets/admin.css')); ?>?v=<?php echo (int) @filemtime(CMS_ROOT . '/admin/assets/admin.css'); ?>">
<?php cms_do_action('admin_head'); ?>
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="logo-mark">C</span>
            <div>
                <div class="brand-name">CiciCMS</div>
                <div class="brand-sub">静态化 CMS v<?php echo e(CMS_VERSION); ?></div>
            </div>
        </div>
        <nav class="nav">
            <?php foreach ($NAV as $key => $item):
                $label = isset($item[0]) ? (string) $item[0] : (string) $key;
                $icon  = isset($item[1]) && $item[1] !== '' ? (string) $item[1] : '<rect x="4.5" y="4.5" width="15" height="15" rx="4.5"/>';
                $on    = ($p === $key) || ($PLUGIN_PAGE !== null && $key === 'plugin' && !isset($NAV[$p]));
            ?>
                <a class="<?php echo $on ? 'on' : ''; ?>" href="<?php echo e(admin_url($key)); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $icon; ?></svg>
                    <span><?php echo e($label); ?></span>
                    <?php if ($key === 'update' && $updateDot): ?><i class="nav-dot" title="有新版本"></i><?php endif; ?>
                </a>
            <?php endforeach; ?>
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

    <div class="main">
        <header class="topbar">
            <button class="menu-btn" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
            <div>
                <h1><?php echo e($pageTitle); ?></h1>
                <?php if ($pageSub !== ''): ?><div class="page-sub"><?php echo e($pageSub); ?></div><?php endif; ?>
            </div>
            <div class="top-actions">
                <a class="link" href="<?php echo e(site_url()); ?>" target="_blank">访问站点 ↗</a>
                <span class="user-chip"><?php echo e($user['username']); ?></span>
            </div>
        </header>
        <div class="content">
            <?php foreach (flashes() as $f): ?>
                <div class="alert <?php echo $f['type'] === 'err' ? 'alert-err' : 'alert-ok'; ?>"><?php echo e($f['msg']); ?></div>
            <?php endforeach; ?>
