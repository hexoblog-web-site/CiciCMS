<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 分页条。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/pager.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_pager($o = array())
{
    $o     = is_array($o) ? $o : array();
    $scope = cms_scope();
    if (empty($scope['pager']) || !is_array($scope['pager'])) {
        return '';
    }
    $pager = $scope['pager'];
    if (empty($pager['pages']) || (int) $pager['pages'] <= 1) {
        return '';
    }

    $prevText = isset($o['prev']) && $o['prev'] !== '' ? $o['prev'] : '上一页';
    $nextText = isset($o['next']) && $o['next'] !== '' ? $o['next'] : '下一页';
    $firstTxt = isset($o['first']) ? $o['first'] : '';
    $lastTxt  = isset($o['last']) ? $o['last'] : '';
    $span     = cms_tag_int(isset($o['size']) ? $o['size'] : 2, 2, 1, 6);
    $cls      = isset($o['class']) && $o['class'] !== '' ? $o['class'] : 'pager';
    $showInfo = !isset($o['info']) || (string) $o['info'] !== '0';

    $nums = pager_numbers($pager, $span);
    $h    = '<nav class="' . e($cls) . '" aria-label="分页">';

    if ($firstTxt !== '' && (int) $pager['page'] > 1) {
        $h .= '<a class="pg" href="' . e(call_user_func($pager['url'], 1)) . '">' . e($firstTxt) . '</a>';
    }

    $h .= !empty($pager['prev'])
        ? '<a class="pg" href="' . e($pager['prev']) . '">' . e($prevText) . '</a>'
        : '<span class="pg dis">' . e($prevText) . '</span>';

    foreach ($nums as $n => $url) {
        if ($url === null) {
            $h .= '<span class="pg dis">…</span>';
        } elseif ((int) $n === (int) $pager['page']) {
            $h .= '<span class="pg cur">' . (int) $n . '</span>';
        } else {
            $h .= '<a class="pg" href="' . e($url) . '">' . (int) $n . '</a>';
        }
    }

    $h .= !empty($pager['next'])
        ? '<a class="pg" href="' . e($pager['next']) . '">' . e($nextText) . '</a>'
        : '<span class="pg dis">' . e($nextText) . '</span>';

    if ($lastTxt !== '' && (int) $pager['page'] < (int) $pager['pages']) {
        $h .= '<a class="pg" href="' . e(call_user_func($pager['url'], (int) $pager['pages'])) . '">' . e($lastTxt) . '</a>';
    }

    if ($showInfo) {
        $h .= '<span class="pg-info">第 ' . (int) $pager['page'] . ' / ' . (int) $pager['pages'] . ' 页</span>';
    }
    return $h . '</nav>';
}
