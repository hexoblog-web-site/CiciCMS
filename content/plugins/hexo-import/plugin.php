<?php
/**
 * Hexo 导入插件
 *
 * 转换器与后台页面都在本目录内，核心程序不需要为它改任何一行代码。
 * 导入流程：上传 md / zip → 解析预览 → 确认入库。
 */

if (!defined('CMS_ROOT')) {
    exit;
}

require_once __DIR__ . '/markdown.php';

/*
 * 在左侧菜单里挂一个入口，放在「文章管理」后面——导入完就要去发文章，顺序顺手。
 * 菜单项的 key 就是后台路由 ?p=<key>，与本插件 ID 一致。
 */
cms_add_filter('admin_menu', function ($nav) {
    if (!is_array($nav)) {
        return $nav;
    }
    $item = array(
        'Hexo 导入',
        '<path d="M12 3.5v10"/><path d="M8.2 9.8L12 13.6l3.8-3.8"/><path d="M4.5 15.5v2.5a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-2.5"/>',
    );

    $out = array();
    foreach ($nav as $key => $value) {
        $out[$key] = $value;
        if ($key === 'article') {
            $out['hexo-import'] = $item;
        }
    }
    if (!isset($out['hexo-import'])) {
        $out['hexo-import'] = $item;
    }
    return $out;
}, 10, 1);
