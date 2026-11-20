<?php
/**
 * CiciCms 后台外壳
 *
 * 唯一后台界面，数据层走 admin/api.php（对接 CiciCMS 原始表）。
 * 这里只负责：鉴权、注入运行时配置、输出静态骨架。所有业务逻辑都在 API 里。
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (is_post() && post_str('do') === 'logout') {
    csrf_verify();
    auth_logout();
    redirect(site_url('admin/login.php'));
}
require_login();

define('CMS_ADMIN', true);

$user   = auth_user();
$csrf   = csrf_token();
$site   = setting('site_name', 'CiciCMS');
$api    = site_url('admin/api.php');
$assets = site_url('admin/legacy/assets');
$ver    = defined('CMS_VERSION') ? CMS_VERSION : '';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-mode="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CiciCms · <?php echo e($site); ?></title>
<meta name="legacy-api" content="<?php echo e($api); ?>">
<meta name="legacy-token" content="<?php echo e($csrf); ?>">
<meta name="legacy-user" content="<?php echo e(isset($user['username']) ? $user['username'] : ''); ?>">
<meta name="legacy-version" content="<?php echo e($ver); ?>">
<link rel="stylesheet" href="<?php echo e($assets . '/app.css'); ?>?v=<?php echo (int) @filemtime(__DIR__ . '/assets/app.css'); ?>">
</head>
<body>
<div class="app" id="app">

  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark" id="brandMark">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17zM15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382zM4.447 8.342A1 1 0 003 9.236V15a2 2 0 00.553.894l4 2A1 1 0 009 17v-5.764a1 1 0 00-.553-.894l-4-2z"/></svg>
        <img id="brandLogo" alt="站点 Logo">
      </div>
      <div class="brand-text"><strong>CiciCms</strong><span>CONTENT MANAGEMENT</span></div>
    </div>

    <div class="nav-search">
      <svg class="ns-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input id="navSearch" placeholder="搜索文章、单页、媒体…" autocomplete="off">
      <span class="ns-key">⌘K</span>
    </div>

    <nav class="nav" id="nav">
      <a class="nav-item" data-view="dashboard" data-hash="#/dashboard">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        <span>仪表盘</span>
      </a>
      <a class="nav-item" data-view="new-post" data-hash="#/posts">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M12 12v6M9 15h6"/></svg>
        <span>添加文章</span>
      </a>
      <a class="nav-item" data-view="posts" data-hash="#/edit">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        <span>编辑文章</span>
      </a>
      <a class="nav-item" data-view="drafts" data-hash="#/drafts">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v13a2 2 0 01-2 2H7l-3 3V4z"/><path d="M8 9h8M8 13h5"/></svg>
        <span>草稿管理</span>
      </a>
      <a class="nav-item" data-view="cat-add" data-hash="#/category">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/><path d="M12 12v6M9 15h6"/></svg>
        <span>添加栏目</span>
      </a>
      <a class="nav-item" data-view="cat-edit" data-hash="#/compile">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
        <span>编辑栏目</span>
      </a>
      <a class="nav-item" data-view="tags" data-hash="#/tags">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
        <span>标签管理</span>
      </a>
      <a class="nav-item" data-view="media" data-hash="#/media">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <span>媒体管理</span>
      </a>
      <a class="nav-item" data-view="page-new" data-hash="#/page">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6a2 2 0 012 2v14a2 2 0 01-2 2H9a2 2 0 01-2-2V5a2 2 0 012-2z"/><path d="M17 7h1a2 2 0 012 2v10a2 2 0 01-2 2h-1"/><path d="M12 9v6M9 12h6"/></svg>
        <span>添加单页</span>
      </a>
      <a class="nav-item" data-view="page-edit" data-hash="#/pages">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6a2 2 0 012 2v14a2 2 0 01-2 2H9a2 2 0 01-2-2V5a2 2 0 012-2z"/><path d="M17 7h1a2 2 0 012 2v10a2 2 0 01-2 2h-1"/><path d="M10 8h4M10 12h4M10 16h2"/></svg>
        <span>编辑单页</span>
      </a>
      <a class="nav-item" data-view="deploy" data-hash="#/deploy">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0L8 8m4-4l4 4"/><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
        <span>一键生成</span>
      </a>
      <a class="nav-item" data-view="data" data-hash="#/data">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.66 3.58 3 8 3s8-1.34 8-3V5"/><path d="M4 11v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/></svg>
        <span>数据管理</span>
      </a>
      <a class="nav-item" data-view="templates" data-hash="#/templates">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
        <span>模板管理</span>
      </a>
      <a class="nav-item" data-view="trash" data-hash="#/trash">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        <span>回收管理</span>
      </a>
      <a class="nav-item" data-view="update" data-hash="#/update">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg>
        <span>系统更新</span>
      </a>
      <a class="nav-item" data-view="settings" data-hash="#/settings">
        <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
        <span>站点设置</span>
      </a>
    </nav>

    <button class="sidebar-foot" id="acctBtn" title="切换账号 / 退出登录">
      <div class="av" id="acctAv">A</div>
      <div class="user-meta"><b id="acctName">管理员</b><span class="sf-role" id="acctRole">站点管理员</span></div>
      <span class="sf-caret">⋯</span>
    </button>
  </aside>

  <main class="main"><div class="content" id="view"></div></main>
</div>
<div class="toast-wrap" id="toastWrap"></div>

<div id="legacy-boot" style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:var(--bg)">
  <div style="text-align:center;color:var(--muted)">
    <div style="font-size:28px;font-weight:700;color:var(--accent);letter-spacing:.04em">CiciCms</div>
    <div id="legacy-boot-msg" style="margin-top:10px">正在载入数据…</div>
  </div>
</div>

<script src="<?php echo e($assets . '/app.js'); ?>?v=<?php echo (int) @filemtime(__DIR__ . '/assets/app.js'); ?>"></script>
</body>
</html>
