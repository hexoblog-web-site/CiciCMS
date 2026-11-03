<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:position} ... {/cici:position}　面包屑
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/position.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_position($attrRaw, $body)
{
    $a    = cms_tag_attrs($attrRaw);
    $o    = array('home' => isset($a['home']) ? $a['home'] : '首页');
    $sep  = isset($a['sep']) ? $a['sep'] : '/';
    $auto = trim($body) === '';

    if ($auto) {
        return '<?php echo cms_tag_position_html(cms_tag_position(' . cms_tag_qcode($o) . '), ' . var_export($sep, true) . '); ?>';
    }
    $uid = cms_tag_uid();
    $st  = '$__pos' . $uid;
    return '<?php ' . $st . ' = cms_tag_position(' . cms_tag_qcode($o) . '); ?>'
         . '<?php if (!empty(' . $st . ')): ?>'
         . cms_tag_loop_code($st, $body)
         . '<?php endif; ?>';
}
/* ── 自闭合写法 {cici:position sep="/"} ───────────────── */

function cms_tag_inline_position($ctx)
{
    $a   = $ctx['attrs'];
    $sep = isset($a['sep']) ? $a['sep'] : '/';
    $o   = array('home' => isset($a['home']) ? $a['home'] : '首页');

    return '<?php echo cms_tag_position_html(cms_tag_position(' . cms_tag_qcode($o) . '), '
         . cms_tag_attr_expr($sep) . '); ?>';
}

return array(
    'name'    => 'position',
    'desc'    => '面包屑导航，写不写闭合标签都能用',
    'block'   => true,
    'auto'    => true,
    'compile' => 'cms_tag_block_position',
    'inline'  => 'cms_tag_inline_position',
);
