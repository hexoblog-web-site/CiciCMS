<?php
/**
 * 代码高亮插件
 *
 * 不写死到主题里：主题只管代码面板的长相（深色底、圆角、横向滚动），
 * 本插件负责把 Prism 的样式与脚本挂上去。
 *
 * 只在页面里确实出现 language- 标记时才注入，
 * 没有代码的文章不会多出 44KB 请求。
 */

if (!defined('CMS_ROOT')) {
    exit;
}

cms_add_filter('html_final', function ($html, $context = 'static') {
    $html = (string) $html;

    // 没有代码块：一个字节都不加
    if (stripos($html, 'class="language-') === false && stripos($html, "class='language-") === false) {
        return $html;
    }

    $css = cms_plugin_url('highlight', 'assets/code.css');
    $js  = cms_plugin_url('highlight', 'assets/prism.bundle.js');

    if (stripos($html, '</head>') !== false) {
        $html = preg_replace(
            '/<\/head>/i',
            '<link rel="stylesheet" href="' . e($css) . '">' . "\n</head>",
            $html,
            1
        );
    }

    if (stripos($html, '</body>') !== false) {
        $html = preg_replace(
            '/<\/body>/i',
            '<script src="' . e($js) . '" defer></script>' . "\n</body>",
            $html,
            1
        );
    }

    return $html;
}, 20, 2);
