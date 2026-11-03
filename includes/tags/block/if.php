<?php
/**
 * HtmlCms 标签引擎 · 块标签
 * {cici:if 条件} ... {cici:else} ... {/cici:if}
 *
 * 只在编译期（生成静态页 / 模板重编）加载，运行期不参与。
 *
 * 文件：includes/tags/block/if.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_block_if($attrRaw, $body)
{
    $raw  = trim((string) $attrRaw);
    $cond = $raw;
    if (preg_match('/^cond\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/u', $raw, $m)) {
        $cond = isset($m[1]) && $m[1] !== '' ? $m[1] : (isset($m[2]) ? $m[2] : '');
    }
    list($yes, $no) = cms_tag_split_else($body);
    return '<?php if (cms_cond(' . var_export($cond, true) . ')): ?>'
         . cms_tag_parse($yes)
         . '<?php else: ?>'
         . cms_tag_parse($no)
         . '<?php endif; ?>';
}
/* ── 标签注册（引擎按这份声明找到本文件）───────────────── */

return array(
    'name'    => 'if',
    'desc'    => '条件判断 {cici:if 条件}...{/cici:if}',
    'block'   => true,
    'compile' => 'cms_tag_block_if',
);
