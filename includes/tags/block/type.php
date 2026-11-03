<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:type} / {cici:autochannel}　单个栏目
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/type.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_type($attrRaw, $body)
{
    $a = cms_tag_attrs($attrRaw);
    $q = array('type' => 'category', 'limit' => 1);
    if (!empty($a['catid'])) {
        $q['catid'] = $a['catid'];
    } elseif (!empty($a['typeid'])) {
        $q['catid'] = $a['typeid'];
    } elseif (!empty($a['catdir'])) {
        $q['catdir'] = $a['catdir'];
    } elseif (!empty($a['dirname'])) {
        $q['catdir'] = $a['dirname'];
    } else {
        $q['current'] = '1';
    }

    list($main, $empty) = cms_tag_split_block_empty($body);
    $uid = cms_tag_uid();
    $st  = '$__ty' . $uid;

    return '<?php ' . $st . ' = cms_tag_rows(' . cms_tag_qcode($q) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . cms_tag_parse($empty)
         . '<?php else: ?>'
         . cms_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => array('type', 'autochannel'),
    'desc'    => '单个栏目',
    'block'   => true,
    'compile' => 'cms_tag_block_type',
);
