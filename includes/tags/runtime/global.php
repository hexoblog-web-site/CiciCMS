<?php
/**
 * HtmlCms 标签引擎 · 运行时
 * 系统变量（cfg_webname 等）。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/global.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 系统变量：兼容 dedecms 的 cfg_xxx 命名 */
function cms_tag_global($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    $map = array(
        'cfg_webname'     => 'site_name',
        'cfg_keywords'    => 'site_keywords',
        'cfg_description' => 'site_desc',
        'cfg_beian'       => 'site_icp',
        'cfg_powerby'     => 'site_footer',
        'cfg_basehost'    => 'site_url',
        'cfg_softname'    => 'site_name',
    );
    if (isset($map[$name])) {
        $name = $map[$name];
    }
    if (strpos($name, 'site_') === 0) {
        return cms_val('site.' . substr($name, 5));
    }
    return (string) setting($name, '');
}
