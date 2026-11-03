<?php
/**
 * 标签：{cici:include file="header"}　引入模板片段
 *
 * 引入主题目录下的同名模板，继承当前作用域变量。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/include.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => 'include',
    'desc'    => '引入模板片段，继承当前变量',
    'compile' => 'cms_tag_single_include',
);

function cms_tag_single_include($ctx)
{
    $a    = $ctx['attrs'];
    $file = isset($a['file']) ? $a['file'] : (isset($a['filename']) ? $a['filename'] : '');
    if ($file === '' && isset($a[''])) {
        $file = $a[''];
    }
    return '<?php echo cms_tag_include(' . var_export($file, true) . '); ?>';
}
