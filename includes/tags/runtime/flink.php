<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 友情链接。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/flink.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 友情链接 */
function cms_tag_flink($o = array())
{
    $o     = is_array($o) ? $o : array();
    $limit = cms_tag_int(isset($o['limit']) ? $o['limit'] : 24, 24, 1, 200);
    $type  = strtolower((string) (isset($o['type']) ? $o['type'] : 'all'));

    $where = 'status = 1';
    if ($type === 'text') {
        $where .= " AND (logo = '' OR logo IS NULL)";
    } elseif ($type === 'logo') {
        $where .= " AND logo <> ''";
    }

    try {
        $rows = all('SELECT * FROM {p}flink WHERE ' . $where . ' ORDER BY sort ASC, id ASC LIMIT ' . $limit);
    } catch (Exception $e) {
        return array();
    }
    if (empty($rows)) {
        return array();
    }
    $out = array();
    foreach ($rows as $r) {
        $r['url']   = isset($r['url']) ? $r['url'] : '';
        $r['title'] = isset($r['name']) ? $r['name'] : '';
        $r['index'] = count($out) + 1;
        $out[] = $r;
    }
    return $out;
}
