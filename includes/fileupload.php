<?php
/**
 * 附件上传（文档、压缩包、小软件）
 *
 * 和图片上传分开：图片靠 getimagesize 校验真实类型，附件是任意二进制，
 * 只能按后缀白名单把关，所以规则定得死一点：
 *   · 只收白名单后缀；php / phtml / phar / cgi / js / html 这类一律拒绝
 *   · 落盘目录固定 content/uploads/files/年/月日/，目录里写一份禁止执行的 .htaccess
 *   · 文件名由系统生成，原文件名只作为「下载按钮上的名字」存进数据库
 *   · 单文件 ≤ 20MB，并且不超过 php.ini 的 upload_max_filesize / post_max_size
 *
 * 不登记 attachment 表：那张表是媒体信息的图片画廊，混进 zip 会破图。
 * 是否「还有人用」由 includes/clean.php 的引用索引判断（article.attachment 已入索引）。
 *
 * 不做界面、不做权限判断：调用方必须先确认是管理员。
 */

/** 允许的附件后缀 */
function cms_file_exts()
{
    return array(
        // 文档
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv', 'rtf',
        // 压缩包
        'zip', 'rar', '7z', 'gz', 'tar',
        // 小软件 / 安装包
        'exe', 'msi', 'apk', 'dmg', 'ipa', 'pkg',
    );
}

/** 明确拒绝的后缀：即使误加进白名单也不会收（服务端脚本、可被浏览器执行的东西） */
function cms_file_deny_exts()
{
    return array(
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx',
        'htaccess', 'htpasswd', 'ini', 'conf',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'xml', 'swf',
    );
}

/** php.ini 的体积写法（20M / 512K / 1G）转成字节 */
function cms_ini_bytes($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return 0;
    }
    $unit = strtolower(substr($v, -1));
    $num  = (float) $v;
    if ($unit === 'g') {
        $num *= 1024;
        $unit = 'm';
    }
    if ($unit === 'm') {
        $num *= 1024;
        $unit = 'k';
    }
    if ($unit === 'k') {
        $num *= 1024;
    }
    return (int) $num;
}

/** 单个附件的上限：20MB，被 php.ini 卡住时按更小的那个走 */
function cms_file_max_bytes()
{
    $max = 20 * 1024 * 1024;
    foreach (array('upload_max_filesize', 'post_max_size') as $k) {
        $n = cms_ini_bytes(ini_get($k));
        if ($n > 0 && $n < $max) {
            $max = $n;
        }
    }
    return $max;
}

/** 后缀是否放行 */
function cms_file_ext_ok($ext)
{
    $ext = strtolower(trim((string) $ext));
    if ($ext === '' || strpos($ext, '/') !== false) {
        return false;
    }
    if (in_array($ext, cms_file_deny_exts(), true)) {
        return false;
    }
    return in_array($ext, cms_file_exts(), true);
}

/**
 * 在附件目录里放一份禁止执行的 .htaccess（只写一次）
 *
 * 后缀已经限死，这一层是防呆：万一哪天白名单被改错，目录里也跑不了脚本。
 */
function cms_file_guard_dir($dir)
{
    $f = rtrim((string) $dir, '/\\') . '/.htaccess';
    if (is_file($f)) {
        return;
    }
    /*
     * 每条指令都放进 IfModule：共享主机常用 AllowOverride 关掉 Options / 授权模块，
     * 裸写 Options 会让整个附件目录 500，用户看到的就是"附件全部打不开"。
     * 真正拦住脚本的是后缀白名单，这里只是第二道保险。
     */
    $rules = "# 附件目录：只允许下载，禁止执行脚本（CiciCMS 自动生成）\n"
        . "<IfModule mod_autoindex.c>\n"
        . "Options -Indexes\n"
        . "</IfModule>\n"
        . "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n"
        . "<FilesMatch \"\\.(php|php3|php4|php5|php7|php8|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)$\">\n"
        . "  <IfModule mod_authz_core.c>\n  Require all denied\n  </IfModule>\n"
        . "  <IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n  </IfModule>\n"
        . "</FilesMatch>\n";
    @file_put_contents($f, $rules);
}

/**
 * 收一个浏览器上传的附件
 *
 * @param array  $file $_FILES 里的单个元素
 * @param string $err  失败原因（出参）
 * @return array|false 成功返回 path/rel/url/name/ext/size
 */
function cms_file_upload($file, &$err = '')
{
    $err = '';

    if (empty($file) || !is_array($file) || !isset($file['error'])) {
        $err = '没有收到文件';
        return false;
    }

    $code = (int) $file['error'];
    if ($code !== UPLOAD_ERR_OK) {
        $map = array(
            UPLOAD_ERR_INI_SIZE   => '超过服务器 upload_max_filesize（' . ini_get('upload_max_filesize') . '）',
            UPLOAD_ERR_FORM_SIZE  => '超过表单允许的大小',
            UPLOAD_ERR_PARTIAL    => '文件只传了一半，请重试',
            UPLOAD_ERR_NO_FILE    => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写临时文件失败',
        );
        $err = isset($map[$code]) ? $map[$code] : '上传失败（错误码 ' . $code . '）';
        return false;
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $err = '文件来源不合法';
        return false;
    }

    $size = (int) $file['size'];
    if ($size <= 0) {
        $err = '文件是空的';
        return false;
    }
    $max = cms_file_max_bytes();
    if ($size > $max) {
        $err = '单个附件不能超过 ' . format_bytes($max);
        return false;
    }

    $orig = (string) $file['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!cms_file_ext_ok($ext)) {
        $err = $ext === ''
            ? '这个文件没有后缀，无法判断类型'
            : '不收 .' . $ext . ' 这类文件；允许 ' . implode(' / ', cms_file_exts());
        return false;
    }

    $sub    = 'files/' . date('Y') . '/' . date('md');
    $dirRel = CMS_CONTENT_REL . '/uploads/' . $sub;
    $dirAbs = CMS_ROOT . '/' . $dirRel;
    if (!ensure_dir($dirAbs)) {
        $err = '创建上传目录失败，请检查 ' . CMS_CONTENT_REL . '/uploads 目录权限';
        return false;
    }
    cms_file_guard_dir($dirAbs);

    $name = '';
    $abs  = '';
    for ($i = 0; $i < 5; $i++) {
        $name = date('Ymd') . substr(md5(uniqid('', true)), 0, 12) . '.' . $ext;
        $abs  = $dirAbs . '/' . $name;
        if (!file_exists($abs)) {
            break;
        }
        usleep(2);
    }

    if (!@move_uploaded_file($file['tmp_name'], $abs)) {
        $err = '保存文件失败，请检查目录权限';
        return false;
    }
    @chmod($abs, 0644);

    return array(
        'path' => '/' . $dirRel . '/' . $name,
        'rel'  => $sub . '/' . $name,
        'url'  => site_url(CMS_CONTENT_REL . '/uploads/' . $sub . '/' . $name),
        'name' => str_limit(basename($orig), 100),
        'ext'  => $ext,
        'size' => $size,
    );
}

/**
 * 规范化表单里提交的附件地址
 *
 * 只留三种：本站上传目录里的文件（统一成 /content/uploads/files/... 根相对地址）、
 * http(s) 外链、站内以 / 开头的路径。其余一律丢弃，避免 javascript: 之类混进来。
 *
 * @param string $v
 * @return string
 */
function cms_file_clean($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }

    $rel = upload_rel($v);
    if ($rel !== '' && strpos($rel, 'files/') === 0) {
        // 统一成「根相对」，文章页在 /cat/5.html 这种子目录下也能对上
        return str_limit('/' . CMS_CONTENT_REL . '/uploads/' . $rel, 255);
    }

    if (preg_match('#^https?://#i', $v)) {
        return str_limit($v, 255);
    }
    if (preg_match('#^/[^\s]*$#', $v)) {
        return str_limit($v, 255);
    }
    return '';
}

/**
 * 删除一个本站上传的附件（只动 uploads/files 目录）
 *
 * @param string $path 库里的路径，兼容 /content/uploads/files/... 等写法
 * @return bool
 */
function cms_file_delete($path)
{
    $rel = upload_rel($path);
    if ($rel === '' || strpos($rel, 'files/') !== 0) {
        return false;
    }
    $abs = upload_path($rel);
    return is_file($abs) ? @unlink($abs) : false;
}
