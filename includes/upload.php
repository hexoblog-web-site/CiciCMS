<?php
/**
 * 图片上传（系统能力）
 *
 * 媒体库、栏目横幅都要「收一张图」，校验规则必须一致，所以放在这里。
 * 只负责：校验 → 存到 content/uploads/年/月/ → 登记 attachment 表。
 * 不做界面，也不做权限判断，调用方必须先确认是管理员。
 */

/** 允许的图片后缀 */
function cms_upload_exts()
{
    return array('jpg', 'jpeg', 'png', 'gif', 'webp');
}

/** 单文件上限（字节）。与后台提示、php.ini 的 upload_max_filesize 保持同一口径。 */
function cms_upload_max_bytes()
{
    return 5 * 1024 * 1024;
}

/**
 * 保存一张图片
 *
 * @param array  $file     $_FILES 里的单个元素
 * @param string $err      失败原因（出参）
 * @param bool   $register 是否登记到 attachment 表（媒体库里能看见）
 * @return array|false     成功返回 path/rel/url/name/mime/size
 */
function cms_upload_image($file, &$err = '', $register = true)
{
    $err = '';

    if (empty($file) || !is_array($file) || !isset($file['error'])) {
        $err = '没有收到文件';
        return false;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $err = '文件超过服务器允许的上传大小（当前上限 ' . format_bytes(cms_upload_max_bytes()) . '）';
        } elseif ($file['error'] === UPLOAD_ERR_NO_FILE) {
            $err = '没有选择文件';
        } else {
            $err = '上传失败（错误码 ' . (int) $file['error'] . '）';
        }
        return false;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $err = '文件来源不合法';
        return false;
    }

    if ((int) $file['size'] > cms_upload_max_bytes()) {
        $err = '单个文件不能超过 ' . format_bytes(cms_upload_max_bytes());
        return false;
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, cms_upload_exts(), true)) {
        $err = '只允许上传 jpg / png / gif / webp 图片';
        return false;
    }

    // 真实图片校验，防止把脚本改个后缀传上来
    $info = @getimagesize($file['tmp_name']);
    if (!$info || empty($info['mime']) || strpos($info['mime'], 'image/') !== 0) {
        $err = '文件不是有效图片';
        return false;
    }

    $dirRel = CMS_CONTENT_REL . '/uploads/' . date('Y') . '/' . date('m');
    if (!ensure_dir(CMS_ROOT . '/' . $dirRel)) {
        $err = '创建上传目录失败，请检查 ' . CMS_CONTENT_REL . '/uploads 目录权限';
        return false;
    }

    $name = date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $abs  = CMS_ROOT . '/' . $dirRel . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        $err = '保存文件失败';
        return false;
    }
    @chmod($abs, 0644);

    $rel  = $dirRel . '/' . $name;
    $orig = str_limit(basename((string) $file['name']), 80);

    if ($register) {
        try {
            insert_row('attachment', array(
                'path'       => $rel,
                'name'       => $orig,
                'mime'       => $info['mime'],
                'size'       => (int) $file['size'],
                'created_at' => time(),
            ));
        } catch (Throwable $ex) {
            // 登记失败不该让上传白做，记日志即可
            log_msg('attachment register failed: ' . $ex->getMessage());
        }
    }

    return array(
        'path' => $rel,
        'rel'  => upload_rel($rel),
        'url'  => upload_url($rel),
        'name' => $orig,
        'mime' => $info['mime'],
        'size' => (int) $file['size'],
    );
}

/**
 * 规范化横幅地址：站内图片存相对路径，外链原样保留，其余一律丢弃
 *
 * 这样换域名、换协议时站内图片不会失效；同时挡住 javascript: 之类的值。
 *
 * @param string $v 用户输入：上传返回的路径 / 媒体库复制的完整地址 / 外链
 * @return string
 */
function cms_banner_clean($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }

    // 媒体库里复制出来的完整地址 → 反查成相对路径
    $pos = strpos($v, '/' . CMS_CONTENT_REL . '/uploads/');
    if ($pos !== false) {
        $rel = upload_rel(substr($v, $pos + 1));
        if ($rel !== '') {
            return CMS_CONTENT_REL . '/uploads/' . $rel;
        }
    }

    $rel = upload_rel($v);
    if ($rel !== '') {
        return CMS_CONTENT_REL . '/uploads/' . $rel;
    }

    if (preg_match('#^https?://#i', $v)) {
        return str_limit($v, 255);
    }
    // 站内绝对路径，如 /assets/banner.jpg
    if (preg_match('#^/[^\s]*$#', $v)) {
        return str_limit($v, 255);
    }
    return '';
}

/**
 * 删除一张由本系统上传的图片（只删 uploads 目录内的文件）
 *
 * @param string $path 库里的路径，兼容 content/uploads/... 与 uploads/...
 * @return bool
 */
function cms_upload_delete($path)
{
    $rel = upload_rel($path);
    if ($rel === '') {
        return false;
    }
    $abs = upload_path($rel);
    if (is_file($abs)) {
        return @unlink($abs);
    }
    return false;
}
