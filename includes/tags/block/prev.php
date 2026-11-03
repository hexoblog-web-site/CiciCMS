<?php
/**
 * 标签：{cici:prev}...{/cici:prev}　上一篇
 *
 * 方向和自定义输出的写法都支持；不写闭合时直接输出链接。
 *
 * 编译器实现与 block/prevnext.php 共用，本文件只负责把参数转交过去。
 *
 * 文件：includes/tags/block/prev.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name'    => 'prev',
    'desc'    => '上一篇',
    'block'   => true,
    'auto'    => true,
    'compile' => 'cms_tag_block_prev',
    'inline'  => 'cms_tag_inline_prevnext',
);
