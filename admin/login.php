<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* 会话超时被踢回登录页时，登录后回到原来那一页（比如正在编辑的文章），
 * 而不是一律回仪表盘。只从 back 里取 query 参数再拼到 index.php，
 * 不接受任意 URL，避免被人拿登录页做开放重定向。 */
$back = isset($_POST['back']) ? (string) $_POST['back'] : (isset($_GET['back']) ? (string) $_GET['back'] : '');
$backQuery = '';
if ($back !== '' && strpos($back, '//') !== 0 && !preg_match('/^[a-z][a-z0-9+.\-]*:/i', $back)) {
    $path = @parse_url($back, PHP_URL_PATH);
    $q    = @parse_url($back, PHP_URL_QUERY);
    if ((!is_string($path) || $path === '' || substr($path, -9) === 'index.php') && is_string($q) && $q !== '') {
        $parts = array();
        parse_str($q, $parts);
        $keep = array();
        foreach (array('p', 'action', 'id', 'page', 'kw', 'cat', 'status', 'file', 'tab') as $k) {
            if (isset($parts[$k]) && is_scalar($parts[$k])) {
                $keep[$k] = (string) $parts[$k];
            }
        }
        if ($keep) {
            $backQuery = http_build_query($keep);
        }
    }
}
$afterLogin = 'index.php' . ($backQuery !== '' ? '?' . $backQuery : '');

if (auth_check()) {
    redirect($afterLogin);
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
            redirect($afterLogin);
        }
        login_guard_fail();
        $err = $result;
    }
}
$zb = site_url('admin/assets/zblog');
?>
<!DOCTYPE HTML>
<html lang="zh-CN">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<meta name="robots" content="none" />
<meta name="viewport" content="width=device-width,viewport-fit=cover" />
<meta name="renderer" content="webkit" />
<title><?php echo e(setting('site_name', 'CiciCMS')); ?> - 登录</title>
<link rel="stylesheet" href="<?php echo e($zb . '/css/admin.css'); ?>" type="text/css" media="screen" />
</head>
<body class="body-login">
<div class="bg">
<div id="wrapper">
  <div class="logo"><img src="<?php echo e($zb . '/image/admin/none.gif'); ?>" title="<?php echo e(setting('site_name', 'CiciCMS')); ?>" alt="Z-Blog" /></div>
  <div class="login">
    <?php if ($err !== ''): ?>
      <div style="width:600px;margin:0 auto 10px;padding:6px 10px;background:#fce0e0;border:1px solid #e1a9a9;color:#b52b1c;font-size:13px;line-height:1.6"><?php echo e($err); ?></div>
    <?php endif; ?>
    <form method="post" action="login.php" autocomplete="off">
      <?php echo csrf_field(); ?>
      <?php if ($back !== ''): ?><input type="hidden" name="back" value="<?php echo e($back); ?>"><?php endif; ?>
      <dl>
        <dt></dt>
        <dd class="username"><label for="edtUserName">管理员账号</label><input type="text" id="edtUserName" name="username" size="20" value="<?php echo e($username); ?>" tabindex="1" autofocus required /></dd>
      </dl>
      <dl>
        <dt></dt>
        <dd class="password"><label for="edtPassWord">密码</label><input type="password" id="edtPassWord" name="password" size="20" tabindex="2" required /></dd>
      </dl>
      <dl>
        <dt></dt>
        <dd class="checkbox"><label style="width:auto;float:none;text-align:left;line-height:32px;color:#666;font-size:13px"><a href="<?php echo e(site_url()); ?>" target="_blank">查看站点 →</a></label></dd>
        <dd class="submit"><input id="btnPost" name="btnPost" type="submit" value="登 录" class="button" tabindex="99" /></dd>
      </dl>
    </form>
  </div>
  <div style="width:600px;margin:20px auto 0;text-align:center;color:#888;font-size:12px;line-height:1.8">
    <?php echo e(setting('site_name', 'CiciCMS')); ?> v<?php echo e(CMS_VERSION); ?>
  </div>
</div>
</div>
</body>
</html>
