<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 面包屑。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/position.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 面包屑数据 */
function cms_tag_position($o = array())
{
    $o     = is_array($o) ? $o : array();
    $home  = isset($o['home']) ? (string) $o['home'] : '首页';
    $scope = cms_scope();

    $items   = array();
    $items[] = array('name' => $home, 'title' => $home, 'url' => u_home(), 'current' => 0);

    $catId = 0;
    if (!empty($scope['cat']) && is_array($scope['cat']) && !empty($scope['cat']['id'])) {
        $catId = (int) $scope['cat']['id'];
    } elseif (!empty($scope['current_cat_id'])) {
        $catId = (int) $scope['current_cat_id'];
    }

    $map   = fe_cat_map();
    $chain = array();
    $guard = 0;
    while ($catId > 0 && isset($map[$catId]) && $guard++ < 12) {
        array_unshift($chain, $map[$catId]);
        $catId = (int) $map[$catId]['parent_id'];
    }
    foreach ($chain as $c) {
        $items[] = array(
            'name'    => $c['name'],
            'title'   => $c['name'],
            'url'     => u_cat($c, 1),
            'current' => 0,
        );
    }

    if (!empty($scope['post']) && is_array($scope['post']) && !empty($scope['post']['title'])) {
        $p       = $scope['post'];
        $items[] = array(
            'name'    => $p['title'],
            'title'   => $p['title'],
            'url'     => isset($p['cat_id']) ? u_post($p) : u_page($p),
            'current' => 1,
        );
    } elseif (!empty($scope['tag_name'])) {
        $items[] = array(
            'name'    => '标签：' . $scope['tag_name'],
            'title'   => $scope['tag_name'],
            'url'     => u_tag($scope['tag_name']),
            'current' => 1,
        );
    } else {
        $n = count($items) - 1;
        if ($n >= 0) {
            $items[$n]['current'] = 1;
        }
    }

    // 补 type/url 之类的同名字段，方便统一写法
    foreach ($items as $i => $it) {
        $items[$i]['typename'] = $it['name'];
        $items[$i]['link']     = $it['url'];
    }
    return $items;
}

/** 面包屑默认 HTML（{cici:position} 空标签时使用） */
function cms_tag_position_html($items, $sep = '/')
{
    if (empty($items)) {
        return '';
    }
    $parts = array();
    foreach ($items as $it) {
        if (!empty($it['current'])) {
            $parts[] = '<span class="crumb-cur">' . e($it['name']) . '</span>';
        } else {
            $parts[] = '<a href="' . e($it['url']) . '">' . e($it['name']) . '</a>';
        }
    }
    return implode(' <span class="crumb-sep">' . e($sep) . '</span> ', $parts);
}
