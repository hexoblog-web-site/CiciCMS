<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* 会话超时被踢回登录页时，登录后回到原来那一页（比如正在编辑的文章），
 * 而不是一律回仪表盘。只从 back 里取 query 参数再拼到 index.php，
 * 不接受任意 URL，避免被人拿登录页做开放重定向。 */
$back = isset($_POST['back']) ? (string) $_POST['back'] : (isset($_GET['back']) ? (string) $_GET['back'] : '');
$backQuery = '';
/* 只认指向后台入口 index.php 的相对地址；带协议、协议相对 URL 或其它脚本一律不采信 */
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 · CiciCms 管理后台</title>
<link rel="stylesheet" href="<?php echo e(site_url('admin/legacy/assets/app.css')); ?>?v=<?php echo (int) @filemtime(CMS_ROOT . '/admin/legacy/assets/app.css'); ?>">
</head>
<body>
<div class="login-gate">
  <div class="lg-card">

    <div class="lg-intro">
      <div class="lg-logo">
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17zM15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382zM4.447 8.342A1 1 0 003 9.236V15a2 2 0 00.553.894l4 2A1 1 0 009 17v-5.764a1 1 0 00-.553-.894l-4-2z"/></svg>
      </div>
      <h2>CiciCms</h2>
      <p>静态化内容管理系统。保存即生成 HTML，不依赖常驻进程，普通虚拟主机也能跑得很快。</p>

      <div class="lg-role">
        <span class="lg-dot" style="background:#8b8bf5"></span>
        <div><b>静态发布</b><span>访问快、抗压好，不用担心数据库被打满</span></div>
      </div>
      <div class="lg-role">
        <span class="lg-dot" style="background:#2dd4bf"></span>
        <div><b>模板与插件</b><span>主题、钩子、插件按需扩展，不绑死技术栈</span></div>
      </div>
      <div class="lg-role">
        <span class="lg-dot" style="background:#fbbf24"></span>
        <div><b>数据自有</b><span>一键备份与恢复，内容始终在自己手里</span></div>
      </div>
    </div>

    <form class="lg-form" method="post" action="login.php" autocomplete="off">
      <h3>登录后台</h3>
      <p class="lg-hint">请输入管理员账号与密码</p>

      <?php if ($err !== ''): ?><div class="lg-err"><?php echo e($err); ?></div><?php endif; ?>

      <?php echo csrf_field(); ?>
      <?php if ($back !== ''): ?><input type="hidden" name="back" value="<?php echo e($back); ?>"><?php endif; ?>

      <div class="lg-field">
        <label for="username">管理员账号</label>
        <input type="text" id="username" name="username" value="<?php echo e($username); ?>" autofocus required>
      </div>
      <div class="lg-field">
        <label for="password">密码</label>
        <input type="password" id="password" name="password" required>
      </div>

      <button class="btn primary" type="submit">登 录</button>

      <div class="lg-note">
        CiciCms v<?php echo e(CMS_VERSION); ?> ·
        <a href="<?php echo e(site_url()); ?>" target="_blank" rel="noopener">查看站点 ↗</a>
      </div>
    </form>

  </div>
</div>
</body>
</html>
