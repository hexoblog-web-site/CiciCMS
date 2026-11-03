<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:prev} / {cici:next} / {cici:prevnext}　上一篇下一篇
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/prevnext.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_prevnext($dir, $attrRaw, $body)
{
    $a = cms_tag_attrs($attrRaw);
    $o = array(
        'samecat' => isset($a['samecat']) ? $a['samecat'] : '1',
    );
    list($main, $empty) = cms_tag_split_block_empty($body);
    $uid = cms_tag_uid();
    $st  = '$__pn' . $uid;

    return '<?php ' . $st . ' = cms_tag_prevnext(' . var_export($dir, true) . ', ' . cms_tag_qcode($o) . '); ?>'
         . '<?php if (empty(' . $st . ')): ?>'
         . cms_tag_parse($empty)
         . '<?php else: ?>'
         . cms_tag_loop_code($st, $main)
         . '<?php endif; ?>';
}
/* ── 四个别名的入口 ───────────────────────────────────── */

function cms_tag_inline_prevnext($ctx)
{
    $a = $ctx['attrs'];
    $o = array('samecat' => isset($a['samecat']) ? $a['samecat'] : '0');

    if ($ctx['name'] === 'prev' || $ctx['name'] === 'next') {
        $dir = $ctx['name'];
    } else {
        // prenext / prevnext：看 get="next"，默认上一篇
        $dir = (isset($a['get']) && strtolower($a['get']) === 'next') ? 'next' : 'prev';
    }

    return '<?php echo cms_tag_prevnext_html(' . var_export($dir, true) . ', ' . cms_tag_qcode($o) . '); ?>';
}

function cms_tag_block_prev($attrRaw, $body)
{
    return cms_tag_block_prevnext('prev', $attrRaw, $body);
}

function cms_tag_block_next($attrRaw, $body)
{
    return cms_tag_block_prevnext('next', $attrRaw, $body);
}

function cms_tag_block_prenext($attrRaw, $body)
{
    $a   = cms_tag_attrs($attrRaw);
    $dir = (isset($a['get']) && strtolower($a['get']) === 'next') ? 'next' : 'prev';
    return cms_tag_block_prevnext($dir, $attrRaw, $body);
}

return array(
    'name'    => array('prenext', 'prevnext'),
    'desc'    => '上一篇 / 下一篇（块写法）；self-closing 也用这里',
    'block'   => true,
    'auto'    => true,
    'compile' => 'cms_tag_block_prenext',
    'inline'  => 'cms_tag_inline_prevnext',
);
