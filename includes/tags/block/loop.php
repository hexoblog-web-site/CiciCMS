<?php
/**
 * CiciCMS 标签引擎 · 块标签
 * {cici:loop table="..."} ... {/cici:loop}　自定义表循环
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/loop.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function CMS_tag_block_loop($attrRaw, $body)
{
    $a = CMS_tag_attrs($attrRaw);
    $o = array(
        'table'  => isset($a['table']) ? $a['table'] : 'article',
        'where'  => isset($a['where']) ? $a['where'] : '',
        'order'  => isset($a['order']) ? $a['order'] : (isset($a['orderby']) ? $a['orderby'] : 'id desc'),
        'limit'  => isset($a['limit']) ? $a['limit'] : (isset($a['row']) ? $a['row'] : '10'),
        'offset' => isset($a['offset']) ? $a['offset'] : '0',
    );
    list($main, $empty) = CMS_tag_split_block_empty($body);
    $uid = CMS_tag_uid();
    $st  = '$__lp' . $uid;

    return '<?php ' . $st . ' = CMS_tag_loop_rows(' . CMS_tag_qcode($o) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . CMS_tag_parse($empty)
         . '<?php else: ?>'
         . CMS_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'loop',
    'desc'    => '白名单表自定义循环',
    'block'   => true,
    'compile' => 'CMS_tag_block_loop',
);
