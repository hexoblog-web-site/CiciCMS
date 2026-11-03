<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:related} / {cici:likearticle}　相关文档
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/related.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_related($attrRaw, $body)
{
    $a = cms_tag_attrs($attrRaw);
    $o = array(
        'limit' => isset($a['limit']) ? $a['limit'] : (isset($a['row']) ? $a['row'] : '6'),
        'bytag' => isset($a['bytag']) ? $a['bytag'] : '1',
    );
    list($main, $empty) = cms_tag_split_block_empty($body);
    $uid = cms_tag_uid();
    $st  = '$__rel' . $uid;

    return '<?php ' . $st . ' = cms_tag_related(' . cms_tag_qcode($o) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . cms_tag_parse($empty)
         . '<?php else: ?>'
         . cms_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => array('related', 'likearticle'),
    'desc'    => '相关文档',
    'block'   => true,
    'compile' => 'cms_tag_block_related',
);
