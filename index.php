<?php
/**
 * HtmlCms 前台入口（动态预览）
 * 正式环境访问静态 HTML，本文件用于：未生成静态前的预览、站内搜索、404 兜底
 */

require __DIR__ . '/includes/bootstrap.php';

/* 请求路径：静态页由 Web 服务器直接发出，落到这里的都是"文件不存在"的请求 */
$reqPath = (string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
$reqPath = trim($reqPath, '/');
$isPretty = ($reqPath !== '' && stripos($reqPath, 'index.php') === false);

/* 老地址（改版前的 /post/、/cat/，或分类改过名）一律 301 到新地址 */
if ($isPretty) {
    $target = legacy_redirect_target($reqPath);
    if ($target !== '') {
        header('Location: ' . $target, true, 301);
        exit;
    }
}

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

/* 走到这里说明是不认识的路径：给出 404，而不是拿首页冒充（避免被搜索引擎判成软 404） */
if ($isPretty) {
    http_response_code(404);
    echo fe_render_404();
    exit;
}

echo fe_render_home($page);
