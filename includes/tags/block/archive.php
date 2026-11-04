<?php
/**
 * CiciCMS 标签引擎 · 块标签
 * {cici:archive} ... {/cici:archive}　归档
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/archive.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 归档块：按 年 → 月 → 文档 三层。
 * 外层 item 有 year/label/count/months；月份 item 有 month/label/count/posts。
 */
function cms_tag_block_archive($attrRaw, $body)
{
    $a = cms_tag_attrs($attrRaw);
    $o = array('limit' => isset($a['limit']) ? $a['limit'] : (isset($a['row']) ? $a['row'] : '0'));
    list($main, $empty) = cms_tag_split_block_empty($body);
    $uid = cms_tag_uid();
    $st  = '$__arc' . $uid;

    return '<?php ' . $st . ' = cms_tag_archive(' . cms_tag_qcode($o) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . cms_tag_parse($empty)
         . '<?php else: ?>'
         . cms_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'archive',
    'desc'    => '按年月归档',
    'block'   => true,
    'compile' => 'cms_tag_block_archive',
);
