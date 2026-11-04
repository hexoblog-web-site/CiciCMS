<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 按年月归档。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/archive.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 归档数据：优先用页面预置的 archive 变量，否则现算 */
function cms_tag_archive($o = array())
{
    $o     = is_array($o) ? $o : array();
    $limit = cms_tag_int(isset($o['limit']) ? $o['limit'] : 0, 0, 0, 5000);

    $scope = cms_scope();
    if (empty($scope['archive']) || !is_array($scope['archive'])) {
        return fe_archive_groups($limit);
    }
    if ($limit <= 0) {
        return $scope['archive'];
    }
    // 限制总条数：按月份逐条截断
    $out  = array();
    $left = $limit;
    foreach ($scope['archive'] as $y) {
        if ($left <= 0) {
            break;
        }
        $months = array();
        foreach ($y['months'] as $m) {
            if ($left <= 0) {
                break;
            }
            $posts = array_slice($m['posts'], 0, $left);
            $left -= count($posts);
            $m['posts'] = $posts;
            $m['count'] = count($posts);
            if (!empty($posts)) {
                $months[] = $m;
            }
        }
        if (!empty($months)) {
            $y['months'] = $months;
            $y['count']  = array_sum(array_column($months, 'count'));
            $out[] = $y;
        }
    }
    return $out;
}
