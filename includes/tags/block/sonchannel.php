<?php
/**
 * 标签：{cici:sonchannel}...{/cici:sonchannel}　子栏目
 *
 * {cici:nav} 的变体：只取当前栏目的直接子栏目。
 *
 * 编译器实现与 block/nav.php 共用，本文件只负责把参数转交过去。
 *
 * 文件：includes/tags/block/sonchannel.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

function cms_tag_block_sonchannel($attrRaw, $body)
{
    return cms_tag_block_nav($attrRaw, $body, 'son');
}

return array(
    'name'    => 'sonchannel',
    'desc'    => '子栏目导航',
    'block'   => true,
    'auto'    => true,
    'compile' => 'cms_tag_block_sonchannel',
    'inline'  => '',
);
