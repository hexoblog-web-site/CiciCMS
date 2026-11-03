<?php
/**
 * 标签：{cici:theme}　当前主题目录地址
 *
 * 用于引入 css / js / 图片，换主题后路径自动跟着变。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/theme.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => 'theme',
    'desc'    => '当前主题目录地址',
    'compile' => 'cms_tag_single_theme',
);

function cms_tag_single_theme($ctx)
{
    return '<?php echo e(theme_url()); ?>';
}
