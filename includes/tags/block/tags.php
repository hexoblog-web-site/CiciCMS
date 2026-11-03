<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:tags} / {cici:hotwords}　标签云
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/tags.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_tags($attrRaw, $body, $hot = false)
{
    $a = cms_tag_attrs($attrRaw);
    $o = array(
        'limit'   => isset($a['limit']) ? $a['limit'] : (isset($a['row']) ? $a['row'] : ($hot ? 12 : 50)),
        'sort'    => isset($a['sort']) ? $a['sort'] : 'count',
        'current' => (isset($a['type']) && strtolower($a['type']) === 'current') ? '1' : '0',
    );
    list($main, $empty) = cms_tag_split_block_empty($body);
    $uid = cms_tag_uid();
    $st  = '$__tg' . $uid;

    return '<?php ' . $st . ' = cms_tag_taglist(' . cms_tag_qcode($o) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . cms_tag_parse($empty)
         . '<?php else: ?>'
         . cms_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'tags',
    'desc'    => '标签云',
    'block'   => true,
    'compile' => 'cms_tag_block_tags',
);
