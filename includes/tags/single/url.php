<?php
/**
 * 标签：{cici:url}　站点根地址
 *
 * 输出 setting 里的 site_url，末尾不带斜杠。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/url.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => 'url',
    'desc'    => '站点根地址',
    'compile' => 'cms_tag_single_url',
);

function cms_tag_single_url($ctx)
{
    return '<?php echo e(site_url()); ?>';
}
