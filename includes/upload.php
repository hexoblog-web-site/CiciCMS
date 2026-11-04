<?php
/**
 * 图片上传（系统能力的薄封装）
 *
 * 真正的校验、命名、落盘、登记都在 includes/uploader.php 的 Cms_Image_Uploader 里。
 * 这里保留函数式接口，是为了让媒体库、栏目横幅这些老调用点不必改；新代码可以直接用类。
 *
 * 不做界面，也不做权限判断，调用方必须先确认是管理员。
 */

/** 允许的图片后缀 */
function cms_upload_exts()
{
    return Cms_Image_Uploader::exts();
}

/** 单文件上限（字节）。与后台提示、php.ini 的 upload_max_filesize 保持同一口径。 */
function cms_upload_max_bytes()
{
    return Cms_Image_Uploader::maxBytes();
}

/**
 * 保存一张图片
 *
 * @param array  $file     $_FILES 里的单个元素
 * @param string $err      失败原因（出参）
 * @param bool   $register 是否登记到 attachment 表（媒体库里能看见）
 * @return array|false     成功返回 path/rel/url/name/mime/size/width/height
 */
function cms_upload_image($file, &$err = '', $register = true)
{
    $up = new Cms_Image_Uploader($register);
    $r  = $up->receive($file);
    $err = $r ? '' : $up->error();
    return $r;
}

/**
 * 把一张网络图片收进本站
 *
 * @param string $url
 * @param string $err 失败原因（出参）
 * @param bool   $register
 * @return array|false
 */
function cms_upload_remote($url, &$err = '', $register = true)
{
    $up = new Cms_Image_Uploader($register);
    $r  = $up->fromUrl($url);
    $err = $r ? '' : $up->error();
    return $r;
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
