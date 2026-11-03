<?php
/**
 * HtmlCms 标签引擎 · 运行时
 * 上一篇 / 下一篇。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/prevnext.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/** 上一篇 / 下一篇 */
function cms_tag_prevnext($dir, $o = array())
{
    $o       = is_array($o) ? $o : array();
    $samecat = !isset($o['samecat']) || (string) $o['samecat'] !== '0';

    $scope = cms_scope();
    $post  = isset($scope['post']) ? $scope['post'] : null;
    if (!is_array($post) || empty($post['id'])) {
        return array();
    }
    $id = (int) $post['id'];
    $ts = isset($post['created_at']) ? (int) $post['created_at'] : 0;
    $gt = ($dir === 'next') ? '>' : '<';

    $where  = 'a.status = 1 AND (a.created_at ' . $gt . ' ? OR (a.created_at = ? AND a.id ' . $gt . ' ?))';
    $params = array($ts, $ts, $id);
    if ($samecat && !empty($post['cat_id'])) {
        $where   .= ' AND a.cat_id = ?';
        $params[] = (int) $post['cat_id'];
    }
    $order = ($dir === 'next') ? 'ASC' : 'DESC';

    $row = one(
        'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
         FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
         WHERE ' . $where . '
         ORDER BY a.created_at ' . $order . ', a.id ' . $order . ' LIMIT 1',
        $params
    );
    return $row ? array(cms_row_expand($row)) : array();
}

/** 上一篇/下一篇的默认链接 HTML（空标签形态使用） */
function cms_tag_prevnext_html($dir, $o = array())
{
    $rows = cms_tag_prevnext($dir, is_array($o) ? $o : array());
    $text = $dir === 'next' ? '下一篇' : '上一篇';
    if (empty($rows)) {
        return '<span class="pn dis"><span>' . $text . '</span>已是' . ($dir === 'next' ? '最后一篇' : '第一篇') . '</span>';
    }
    $row = $rows[0];
    return '<a class="pn ' . e($dir) . '" href="' . e($row['url']) . '"><span>' . $text . '</span>' . e($row['title']) . '</a>';
}
