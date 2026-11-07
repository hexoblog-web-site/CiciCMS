<?php
/**
 * CiciCMS 前台入口（仅站内搜索）
 *
 * 整站是真实静态文件：文章、栏目、标签、单页一律直接访问生成好的 .html，
 * 不存在任何动态页面 / 伪静态地址。本文件只处理一件事——站内搜索
 * （搜索必须查库，无法静态化），其余请求一律 404。
 *
 * 旧的 index.php?p= / ?cat= / ?tag= / ?single= 动态地址已移除：
 * 内容地址规则见 README「静态页面组织」一节。
 */

require __DIR__ . '/includes/bootstrap.php';

if (isset($_GET['s'])) {
    $kw   = (string) get('s');
    $page = max(1, get_int('page', 1));
    header('X-Robots-Tag: noindex');
    echo fe_render_search($kw, $page);
    exit;
}

http_response_code(404);
$notFound = CMS_ROOT . '/404.html';
if (is_file($notFound)) {
    readfile($notFound);
    exit;
}
echo "404 Not Found\n";
