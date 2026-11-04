<?php
/**
 * CiciCMS 前台入口（动态预览）
 * 正式环境访问静态 HTML，本文件只在两种情况下被访问：
 * 还没有生成静态页时的预览，以及站内搜索。
 */

require __DIR__ . '/includes/bootstrap.php';

$articleSlug = (string) get('p');
$catDir      = (string) get('cat');
$tagName     = (string) get('tag');
$pageSlug    = (string) get('single');
$kw          = (string) get('s');
$page        = max(1, get_int('page', 1));

if ($articleSlug !== '') {
    $post = fe_find_post($articleSlug);
    if (!$post) {
        http_response_code(404);
        echo fe_render_404();
        exit;
    }
    echo fe_render_article($post);
    exit;
}

if ($catDir !== '') {
    $cat = fe_cat_by_dir($catDir);
    if (!$cat) {
        http_response_code(404);
        echo fe_render_404();
        exit;
    }
    echo fe_render_category($cat, $page);
    exit;
}

if ($tagName !== '') {
    echo fe_render_tag($tagName, $page);
    exit;
}

if ($pageSlug !== '') {
    $single = fe_find_page($pageSlug);
    if (!$single) {
        http_response_code(404);
        echo fe_render_404();
        exit;
    }
    echo fe_render_page($single);
    exit;
}

if (isset($_GET['s'])) {
    echo fe_render_search($kw, $page);
    exit;
}

/* 没有带任何参数：首页（未生成静态页时的预览） */
echo fe_render_home($page);
