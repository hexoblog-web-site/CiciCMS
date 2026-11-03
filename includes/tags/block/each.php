<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:each in="..."} ... {/cici:each}　数组遍历
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/each.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 通用数组遍历：{cici:each in="post.tag_list"}...{/cici:each}
 * 元素可以是数组，也可以是标量（自动包装成 name/value）
 */
function cms_tag_block_each($attrRaw, $body)
{
    $a  = cms_tag_attrs($attrRaw);
    $in = '';
    foreach (array('in', 'from', 'list', 'name') as $k) {
        if (!empty($a[$k])) {
            $in = $a[$k];
            break;
        }
    }
    $uid = cms_tag_uid();
    $st  = '$__each' . $uid;

    return '<?php ' . $st . ' = cms_tag_array(' . var_export(cms_tag_attrv($in), true) . '); ?>'
         . '<?php if (!empty(' . $st . ')): ?>'
         . cms_tag_loop_code($st, $body)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'each',
    'desc'    => '任意数组遍历 {cici:each in="..."}',
    'block'   => true,
    'compile' => 'cms_tag_block_each',
);
