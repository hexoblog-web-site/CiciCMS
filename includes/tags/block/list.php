<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:list} ... {/cici:list}　文档列表
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/list.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_list($attrRaw, $body, $type = '')
{
    $a = cms_tag_attrs($attrRaw);
    $q = cms_tag_list_q($a, $type !== '' ? $type : 'article');

    list($main, $empty) = cms_tag_split_block_empty($body);
    $uid = cms_tag_uid();
    $st  = '$__s' . $uid;

    return '<?php ' . $st . ' = cms_tag_rows(' . cms_tag_qcode($q) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . cms_tag_parse($empty)
         . '<?php else: ?>'
         . cms_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'list',
    'desc'    => '文档列表',
    'block'   => true,
    'compile' => 'cms_tag_block_list',
);
