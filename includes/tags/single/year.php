<?php
/**
 * 标签：{cici:year}　当前年份
 *
 * 页脚版权常用，跟着服务器时间走。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/year.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => 'year',
    'desc'    => '当前年份',
    'compile' => 'cms_tag_single_year',
);

function cms_tag_single_year($ctx)
{
    return '<?php echo date("Y"); ?>';
}
