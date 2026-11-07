<?php
/**
 * CiciCMS 后台图标 sprite
 *
 * 所有图标统一 24×24 视图框、线性描边；颜色与尺寸由外层 svg 的 CSS 决定。
 * 用法：页面里写 cms_icon_sprite() 输出一次隐藏 sprite，再用 cms_icon('#i-article') 引用。
 * 为了兼容旧插件，cms_icon() 也接受一整段内联 path，原样包进 svg。
 */
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

function cms_icon_map()
{
    return array(
        'square'    => '<rect x="4.5" y="4.5" width="15" height="15" rx="4.5"/>',
        'plus'      => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v8M8 12h8"/>',
        'dashboard' => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
        'article'   => '<path d="M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8z"/><path d="M14 3.5V8h4.5"/><path d="M9 12.5h6M9 16.5h4"/>',
        'write'     => '<path d="M4 20h4L18 10a2.5 2.5 0 0 0-3.5-3.5L4.5 16.5z"/><path d="M13.5 6.5l4 4"/>',
        'category'  => '<path d="M3.5 7.5a2 2 0 0 1 2-2h3.6l2 2h7.4a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5.5a2 2 0 0 1-2-2z"/>',
        'page'      => '<rect x="8.5" y="3.5" width="11" height="13" rx="2"/><path d="M16 20.5H6.5a2 2 0 0 1-2-2V7.5"/>',
        'media'     => '<rect x="3.5" y="4.5" width="17" height="15" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="M4.5 17l5-4.2 3.8 3 2.8-2 4.4 3.4"/>',
        'flink'     => '<path d="M10.5 13.5a3.6 3.6 0 0 0 5.1 0l2.6-2.6a3.6 3.6 0 0 0-5.1-5.1l-1.5 1.5"/><path d="M13.5 10.5a3.6 3.6 0 0 0-5.1 0l-2.6 2.6a3.6 3.6 0 0 0 5.1 5.1l1.5-1.5"/>',
        'build'     => '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4v4.8h-4.8"/>',
        'clean'     => '<path d="M4.5 7h15"/><path d="M9.5 7V5.5a1.5 1.5 0 0 1 1.5-1.5h2a1.5 1.5 0 0 1 1.5 1.5V7"/><path d="M6.5 7l.8 11.4a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9L17.5 7"/>',
        'data'      => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6"/><path d="M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/>',
        'theme'     => '<rect x="3.5" y="4.5" width="17" height="15" rx="3"/><path d="M3.5 9.8h17"/><path d="M9.8 9.8v9.7"/>',
        'plugin'    => '<path d="M9 3.5v4M15 3.5v4"/><path d="M6.5 7.5h11v4.2a5.5 5.5 0 0 1-11 0z"/><path d="M12 17v3.5"/>',
        'update'    => '<path d="M7.3 17.5a4 4 0 0 1-.4-7.98 5.5 5.5 0 0 1 10.66-.7A3.7 3.7 0 0 1 17.8 17.5"/><path d="M12 11.2v6.2"/><path d="M9.7 15 12 17.3 14.3 15"/>',
        'setting'   => '<path d="M4 7.5h7M15.5 7.5h4.5M4 16.5h4M12.5 16.5h7.5"/><circle cx="13" cy="7.5" r="2.2"/><circle cx="10" cy="16.5" r="2.2"/>',
        'eye'       => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'layers'    => '<path d="M12 3l8 4.5-8 4.5-8-4.5z"/><path d="M4 12l8 4.5 8-4.5"/><path d="M4 16.5L12 21l8-4.5"/>',
        'activity'  => '<path d="M3 12h4l2.5-6 4 12 2.5-6h5"/>',
        'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'      => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>',
        'density'   => '<path d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
    );
}

/** 输出一次隐藏的 sprite，放在 <body> 开头即可；同页重复调用不会重复输出 */
function cms_icon_sprite()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    $out = '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" '
         . 'style="position:absolute;width:0;height:0;overflow:hidden">';
    foreach (cms_icon_map() as $id => $path) {
        $out .= '<symbol id="i-' . $id . '" viewBox="0 0 24 24">' . $path . '</symbol>';
    }
    return $out . '</svg>';
}

/**
 * 引用一个图标。$ref 以 # 开头则用 <use> 引 sprite，否则当作内联 path 原样包进 svg
 * —— 后一条保证老插件的 admin_menu 自定义图标不会失效。
 */
function cms_icon($ref, $class = '')
{
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    $open = '<svg' . $cls . ' viewBox="0 0 24 24" aria-hidden="true" focusable="false" '
          . 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">';
    if (is_string($ref) && isset($ref[0]) && $ref[0] === '#') {
        return $open . '<use href="' . e($ref) . '"></use></svg>';
    }
    return $open . (string) $ref . '</svg>';
}
