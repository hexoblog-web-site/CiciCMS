<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (auth_check()) {
    redirect('index.php');
}

$err      = '';
$username = '';

if (is_post()) {
    csrf_verify();
    $username = post_str('username');
    $password = (string) post_str('password');

    $guard = login_guard_check();
    if ($guard !== true) {
        $err = $guard;
    } elseif ($username === '' || $password === '') {
        $err = '请输入账号与密码';
    } else {
        $result = auth_login($username, $password);
        if ($result === true) {
            login_guard_clear();
            redirect('index.php');
        }
        login_guard_fail();
        $err = $result;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 - CiciCMS 管理后台</title>
<script>
(function () {
    try {
        var t = localStorage.getItem('cici-theme');
        if (t !== 'light' && t !== 'dark') {
            t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.setAttribute('data-density', 'comfortable');
    } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
})();
</script>
<link rel="stylesheet" href="<?php echo e(site_url('admin/assets/admin.css')); ?>">
</head>
<body class="login-body">
<button class="icon-btn i-theme login-theme" type="button" data-theme-toggle title="切换深浅主题" aria-label="切换深浅主题">
    <svg class="i-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/></svg>
    <svg class="i-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
<div class="login-wrap">
    <div class="login-brand">
        <div class="logo-mark">C</div>
        <div>
            <div class="login-title">CiciCMS</div>
            <div class="login-sub">静态化内容管理系统</div>
        </div>
    </div>
    <?php if ($err !== ''): ?><div class="alert alert-err"><?php echo e($err); ?></div><?php endif; ?>
    <form method="post" action="login.php" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-row">
            <label for="username">管理员账号</label>
            <input class="input" type="text" id="username" name="username" value="<?php echo e($username); ?>" autofocus required>
        </div>
        <div class="form-row">
            <label for="password">密码</label>
            <input class="input" type="password" id="password" name="password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">登 录</button>
    </form>
    <div class="login-foot">
        <a href="<?php echo e(site_url()); ?>" target="_blank">查看站点 &rarr;</a>
        <span>CiciCMS v<?php echo e(CMS_VERSION); ?></span>
    </div>
</div>
</body>
</html>
