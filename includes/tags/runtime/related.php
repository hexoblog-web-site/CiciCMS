<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 相关文档。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/related.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 相关文档：同分类优先，不足时用标签补 */
function cms_tag_related($o = array())
{
    $o      = is_array($o) ? $o : array();
    $limit  = cms_tag_int(isset($o['limit']) ? $o['limit'] : 6, 6, 1, 50);
    $byTag  = !isset($o['bytag']) || (string) $o['bytag'] !== '0';

    $scope = cms_scope();
    $post  = isset($scope['post']) ? $scope['post'] : null;
    if (!is_array($post) || empty($post['id'])) {
        return array();
    }
    $id  = (int) $post['id'];
    $cid = isset($post['cat_id']) ? (int) $post['cat_id'] : 0;

    $rows = array();
    if ($cid > 0) {
        $rows = all(
            'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
             FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
             WHERE a.status = 1 AND a.id <> ? AND a.cat_id = ?
             ORDER BY a.is_top DESC, a.created_at DESC LIMIT ' . $limit,
            array($id, $cid)
        );
    }
    $seen = array($id => 1);
    foreach ($rows as $r) {
        $seen[(int) $r['id']] = 1;
    }

    if ($byTag && count($rows) < $limit && !empty($post['tags'])) {
        foreach (explode(',', $post['tags']) as $t) {
            if (count($rows) >= $limit) {
                break;
            }
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            $more = all(
                'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
                 FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                 WHERE a.status = 1 AND FIND_IN_SET(?, a.tags) AND a.id <> ?
                 ORDER BY a.created_at DESC LIMIT ' . $limit,
                array($t, $id)
            );
            foreach ($more as $m) {
                if (isset($seen[(int) $m['id']])) {
                    continue;
                }
                $rows[] = $m;
                $seen[(int) $m['id']] = 1;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }
    }

    $out = array();
    foreach ($rows as $r) {
        $out[] = cms_row_expand($r);
    }
    return array_slice($out, 0, $limit);
}
