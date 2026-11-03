<?php
/**
 * 标签：{cici:pager prev="上一页" next="下一页" size="2"}　分页条
 *
 * 属性原样透传给运行时的 cms_tag_pager()，这里只负责生成调用代码。
 *
 * 只在编译期（生成静态页 / 模板重编）加载。编译器收到统一的 ctx：
 *   $ctx['name']    标签名
 *   $ctx['attrs']   属性数组（已解析引号）
 *   $ctx['filters'] 修饰器链
 *   $ctx['fcode']   修饰器的 PHP 代码
 *
 * 文件：includes/tags/single/pager.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => array('pager', 'pagelist'),
    'desc'    => '分页条，属性透传给运行时的 cms_tag_pager()',
    'compile' => 'cms_tag_single_pager',
);

function cms_tag_single_pager($ctx)
{
    return '<?php echo cms_tag_pager(' . cms_tag_qcode($ctx['attrs']) . '); ?>';
}
