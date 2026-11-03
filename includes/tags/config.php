<?php
/**
 * HtmlCms 标签引擎 · 配置
 * 命名空间、推荐位定义、原样输出字段、dedecms 字段别名。
 * 想改标签前缀、加字段别名，只改这一个文件。
 *
 * 文件：includes/tags/config.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 推荐位（flag）定义，与后台勾选项一致 */
function cms_flag_map()
{
    return array(
        'c' => '推荐',
        'h' => '头条',
        'p' => '图片',
        'f' => '幻灯',
        'a' => '特荐',
        's' => '滚动',
        'b' => '加粗',
        'j' => '图文',
    );
}

/** 这些字段按 HTML 原样输出，不做转义 */
function cms_tag_raw_fields()
{
    return array('content', 'body', 'html', 'footer', 'code');
}

/** dedecms 字段别名 → 本站字段，便于老模板迁移 */
function cms_tag_field_alias()
{
    return array(
        'arcurl'     => 'url',
        'litpic'     => 'cover',
        'picname'    => 'cover',
        'pubdate'    => 'created_at',
        'senddate'   => 'created_at',
        'click'      => 'views',
        'description' => 'summary',
        'body'       => 'content',
        'typename'   => 'cat_name',
        'typeid'     => 'cat_id',
        'typeurl'    => 'cat_url',
        'typelink'   => 'cat_url',
        'shorttitle' => 'title',
        'fulltitle'  => 'title',
    );
}
