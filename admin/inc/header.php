<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

$user = auth_user();
$NAV  = array(
    'dashboard' => array('仪表盘', '◫'),
    'article'   => array('文章管理', '▤'),
    'category'  => array('栏目管理', '▦'),
    'page'      => array('单页管理', '▭'),
    'media'     => array('媒体库', '▣'),
    'flink'     => array('友情链接', '⇄'),
    'build'     => array('生成静态', '↻'),
    'clean'     => array('系统清理', '♻'),
    'theme'     => array('模板管理', '✎'),
    'setting'   => array('系统设置', '⚙'),
);
$pageTitle = isset($NAV[$p]) ? $NAV[$p][0] : '管理后台';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($pageTitle); ?> - HtmlCms 管理后台</title>
<link rel="stylesheet" href="<?php echo e(site_url('admin/assets/admin.css')); ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <span class="logo-mark">H</span>
            <div>
                <div class="brand-name">HtmlCms</div>
                <div class="brand-sub">静态化 CMS v<?php echo e(CMS_VERSION); ?></div>
            </div>
        </div>
        <nav class="nav">
            <?php foreach ($NAV as $key => $item): ?>
                <a class="<?php echo $p === $key ? 'on' : ''; ?>" href="<?php echo e(admin_url($key)); ?>">
                    <span class="ico"><?php echo $item[1]; ?></span><?php echo e($item[0]); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="build-hint">
                已生成 <b><?php echo (int) static_files_count(); ?></b> 个静态文件
                <a href="<?php echo e(admin_url('build')); ?>">去生成</a>
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
            <h1><?php echo e($pageTitle); ?></h1>
            <div class="top-actions">
                <a class="link" href="<?php echo e(site_url()); ?>" target="_blank">访问站点 ↗</a>
                <span class="user-chip"><?php echo e($user['username']); ?></span>
            </div>
        </header>
        <div class="content">
            <?php foreach (flashes() as $f): ?>
                <div class="alert <?php echo $f['type'] === 'err' ? 'alert-err' : 'alert-ok'; ?>"><?php echo e($f['msg']); ?></div>
            <?php endforeach; ?>
