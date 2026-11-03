<?php
/**
 * HtmlCms 标签引擎 · 运行时
 * 栏目树读取与缓存。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/nav.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 栏目导航 / 子栏目 */
function cms_tag_nav($o)
{
    $o     = is_array($o) ? $o : array();
    $type  = strtolower(trim((string) (isset($o['type']) ? $o['type'] : 'top')));
    $limit = cms_tag_int(isset($o['limit']) ? $o['limit'] : 0, 0, 0, 500);
    $style = isset($o['currentstyle']) ? (string) $o['currentstyle'] : 'active';

    $scope    = cms_scope();
    $cur      = isset($scope['current_cat_id']) ? (int) $scope['current_cat_id'] : 0;
    $catidRaw = isset($o['catid']) ? trim((string) $o['catid']) : '';
    $catid    = (int) $catidRaw;
    // 没写 catid 才回退到当前栏目；显式写 0 表示顶级
    if ($catidRaw === '' && $cur > 0) {
        $catid = $cur;
    }

    $map      = fe_cat_map();
    $parentId = 0;
    if ($type === 'self' || $type === 'son') {
        $parentId = isset($map[$catid]) ? (int) $map[$catid]['parent_id'] : 0;
    }

    $catCounts = fe_cat_counts();

    $out = array();
    foreach (fe_cats() as $c) {
        $pid = (int) $c['parent_id'];
        $ok  = true;
        if ($type === 'top') {
            $ok = ($pid === 0);
        } elseif ($type === 'son') {
            // 有 catid 时取其子栏目；没有则视作顶级
            $ok = $catid > 0 ? ($pid === $catid) : ($pid === 0);
        } elseif ($type === 'self') {
            $ok = ($catid > 0) ? ($pid === $parentId) : ($pid === 0);
        }
        if (!$ok) {
            continue;
        }
        $row = cms_row_expand($c);
        $row['count']        = isset($catCounts[(int) $c['id']]) ? $catCounts[(int) $c['id']] : 0;
        $row['active']       = ((int) $c['id'] === $cur) ? 1 : 0;
        $row['current']      = $row['active'];
        $row['currentstyle'] = $row['active'] ? $style : '';
        $row['class']        = $row['active'] ? $style : '';
        $out[] = $row;
        if ($limit > 0 && count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
