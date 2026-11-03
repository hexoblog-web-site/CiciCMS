<?php
/**
 * 标签：{cici:global name="cfg_webname"}　系统变量
 *
 * 兼容 dedecms 的 cfg_webname / cfg_beian 等写法，映射关系在 runtime/global.php。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/global.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => 'global',
    'desc'    => '系统变量，兼容 dedecms 的 cfg_xxx 写法',
    'compile' => 'cms_tag_single_global',
);

function cms_tag_single_global($ctx)
{
    $a     = $ctx['attrs'];
    $gname = isset($a['name']) ? $a['name'] : (isset($a['']) ? $a[''] : '');
    return '<?php echo cms_tag_out(cms_tag_global(' . cms_tag_attr_expr($gname) . '), ' . $ctx['fcode'] . ', false); ?>';
}
