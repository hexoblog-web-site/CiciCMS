<?php
/**
 * 标签：{cici:hotwords}...{/cici:hotwords}　热门标签
 *
 * {cici:tags} 的变体：按使用次数取最热的标签。
 *
 * 编译器实现与 block/tags.php 共用，本文件只负责把参数转交过去。
 *
 * 文件：includes/tags/block/hotwords.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

function cms_tag_block_hotwords($attrRaw, $body)
{
    return cms_tag_block_tags($attrRaw, $body, true);
}

return array(
    'name'    => 'hotwords',
    'desc'    => '热门标签',
    'block'   => true,
    'auto'    => true,
    'compile' => 'cms_tag_block_hotwords',
    'inline'  => '',
);
