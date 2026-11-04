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
<link rel="stylesheet" href="<?php echo e(site_url('admin/assets/admin.css')); ?>">
</head>
<body class="login-body">
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
