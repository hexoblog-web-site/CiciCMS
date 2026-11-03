<?php
/**
 * 字段覆盖：title
 *
 * {cici:title} 默认就是「取当前文档的 title 字段」，本来不需要单独一个文件。
 * 但字段级定制是常见需求（比如列表页标题要统一加后缀、详情页要取副标题），
 * 所以留了这个扩展点：本目录下放 <字段名>.php，就能覆盖该字段的取值行为。
 *
 *   path  取值路径，相对当前作用域；'title' 等价于默认行为
 *   raw   true = 不转义直接输出，只有正文类字段（content / body）才需要
 *
 * 想加一个字段的定制，复制本文件改成目标字段名即可，不用动引擎。
 *
 * 文件：includes/tags/fields/title.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

return array(
    'name' => 'title',
    'desc' => '文档标题',
    'path' => 'title',
    'raw'  => false,
);
