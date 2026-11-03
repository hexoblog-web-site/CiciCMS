<?php
/**
 * 标签：{cici:field name="arcurl"}　按字段名取值，同时是所有字段类标签的兜底
 *
 * 两条路：
 *   1. 显式写 field name="xxx"，字段名走 dedecms 别名映射；
 *   2. 直接写 {cici:title} 这类字段标签，由本文件末尾的 cms_tag_field_compile() 兜底。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/field.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'     => 'field',
    'desc'     => '按字段名取值，兼容 dedecms；字段类标签（title / content ...）也走这里',
    'compile'  => 'cms_tag_single_field',
    'fallback' => true,
);

function cms_tag_single_field($ctx)
{
    $a     = $ctx['attrs'];
    $fname = isset($a['name']) ? $a['name'] : (isset($a['']) ? $a[''] : '');

    // 老模板里 {cici:field name="position"} 当作面包屑
    if (strtolower(trim($fname)) === 'position') {
        return '<?php echo cms_tag_position_html(cms_tag_position(), " / "); ?>';
    }

    $pathCode = 'cms_tag_path(' . cms_tag_attr_expr($fname) . ')';
    return '<?php echo cms_tag_out(cms_raw(' . $pathCode . '), ' . $ctx['fcode'] . ', cms_tag_is_raw_path(' . $pathCode . ')); ?>';
}

/**
 * 字段类标签的统一编译器：{cici:title} / {cici:content} / {cici:created_at} ...
 *
 * 名字本身即字段名，默认取当前文档字段。若 includes/tags/fields/<名字>.php
 * 存在，则用那份定义里的 path / raw 覆盖——某个字段要特殊对待时只加一个文件，
 * 不用改引擎、也不用改这里。
 */
function cms_tag_field_compile($ctx)
{
    $name = $ctx['name'];
    $def  = cms_tag_field_def($name);

    $path = ($def && isset($def['path']) && $def['path'] !== '') ? $def['path'] : $name;
    $raw  = ($def && array_key_exists('raw', $def)) ? (bool) $def['raw'] : cms_tag_is_raw_path($path);

    return '<?php echo cms_tag_out(cms_raw(' . var_export($path, true) . '), '
         . $ctx['fcode'] . ', ' . ($raw ? 'true' : 'false') . '); ?>';
}
