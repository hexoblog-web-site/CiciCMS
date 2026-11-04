<?php
/**
 * CiciCMS 标签引擎 · 块标签
 * {cici:channelartlist} ... {/cici:channelartlist}　栏目+文档两重循环
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/channelartlist.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_channelartlist($attrRaw, $body)
{
    $a = cms_tag_attrs($attrRaw);
    $o = array(
        'type'         => isset($a['type']) ? $a['type'] : 'top',
        'catid'        => isset($a['catid']) ? $a['catid'] : (isset($a['typeid']) ? $a['typeid'] : ''),
        'limit'        => isset($a['row']) ? $a['row'] : (isset($a['limit']) ? $a['limit'] : '0'),
        'currentstyle' => isset($a['currentstyle']) ? $a['currentstyle'] : 'active',
    );
    $uid = cms_tag_uid();
    $st  = '$__cal' . $uid;

    return '<?php ' . $st . ' = cms_tag_nav(' . cms_tag_qcode($o) . '); ?>'
         . '<?php if (!empty(' . $st . ')): ?>'
         . cms_tag_loop_code($st, $body)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'channelartlist',
    'desc'    => '栏目 + 文档两重循环',
    'block'   => true,
    'compile' => 'cms_tag_block_channelartlist',
);
