<?php
/**
 * 标签：{cici:page}...{/cici:page}　单页列表
 *
 * {cici:list} 的变体：把数据源换成 page 表，属性和写法完全一致。
 *
 * 编译器实现与 block/list.php 共用，本文件只负责把参数转交过去。
 *
 * 文件：includes/tags/block/page.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

function CMS_tag_block_page($attrRaw, $body)
{
    return CMS_tag_block_list($attrRaw, $body, 'page');
}

return array(
    'name'    => 'page',
    'desc'    => '单页列表',
    'block'   => true,
    'auto'    => true,
    'compile' => 'CMS_tag_block_page',
    'inline'  => '',
);
