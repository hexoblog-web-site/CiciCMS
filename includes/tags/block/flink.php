<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:flink} ... {/cici:flink}　友情链接
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/flink.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_flink($attrRaw, $body)
{
    $a = cms_tag_attrs($attrRaw);
    $o = array(
        'limit' => isset($a['limit']) ? $a['limit'] : (isset($a['row']) ? $a['row'] : '24'),
        'type'  => isset($a['type']) ? $a['type'] : 'all',
    );
    list($main, $empty) = cms_tag_split_block_empty($body);
    $uid = cms_tag_uid();
    $st  = '$__fl' . $uid;

    return '<?php ' . $st . ' = cms_tag_flink(' . cms_tag_qcode($o) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . cms_tag_parse($empty)
         . '<?php else: ?>'
         . cms_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'flink',
    'desc'    => '友情链接',
    'block'   => true,
    'compile' => 'cms_tag_block_flink',
);
