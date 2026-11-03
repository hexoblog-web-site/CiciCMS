<?php
/**
 * 标签：{cici:else} / {cici:empty}　块标签的分支符号
 *
 * 这两个符号本该由块标签自己消化，若被当成单标签解析到，
 * 说明模板写法有误，直接输出空串，不让它变成一段奇怪的字段值。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/residual.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => array('else', 'empty'),
    'desc'    => '块标签的分支符号，被单独解析到时直接丢弃',
    'compile' => 'cms_tag_single_residual',
);

function cms_tag_single_residual($ctx)
{
    return '';
}
