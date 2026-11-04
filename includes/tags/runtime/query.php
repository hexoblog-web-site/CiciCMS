<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 取数：列表、归档、相关文档共用的 SQL 组装与结果整理。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/query.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 统一列表查询。$q 支持的键：
 * type article|page|category|tag
 * catid(支持 "1,2") notcatid catdir current tag keyword flag noflag idlist notid
 * subday limit offset order orderby orderway
 * titlelen infolen col exclude
 */
function cms_tag_rows($q)
{
    $q      = is_array($q) ? $q : array();
    $type   = strtolower(trim((string) (isset($q['type']) ? $q['type'] : 'article')));
    $limit  = isset($q['limit']) ? $q['limit'] : 10;
    $offset = max(0, (int) (isset($q['offset']) ? $q['offset'] : 0));

    // limit="0,10" 写法（偏移,条数）
    if (is_string($limit) && strpos($limit, ',') !== false) {
        $seg = explode(',', $limit, 2);
        $offset = max(0, (int) $seg[0]);
        $limit  = (int) $seg[1];
    }
    $limit = cms_tag_int($limit, 10, 1, 200);

    if ($type === 'category' || $type === 'cat') {
        return cms_tag_query_cats($q, $limit, $offset);
    }
    if ($type === 'tag' || $type === 'tags') {
        return cms_tag_taglist(array('limit' => $limit));
    }
    if ($type === 'page' || $type === 'single') {
        $rows = all('SELECT * FROM {p}page WHERE status = 1 ORDER BY sort ASC, id ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
        $rows = empty($rows) ? array() : $rows;
        return cms_tag_rows_post($rows, $q);
    }

    // ---------- 文章 ----------
    $where  = array('a.status = 1');
    $params = array();

    if (!empty($q['current'])) {
        $scope = cms_scope();
        $cid   = isset($scope['current_cat_id']) ? (int) $scope['current_cat_id'] : 0;
        $where[]  = 'a.cat_id = ?';
        $params[] = $cid > 0 ? $cid : -1;
    }
    if (!empty($q['catid'])) {
        $ids = cms_tag_id_list($q['catid']);
        if (!empty($ids)) {
            $where[] = 'a.cat_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }
    }
    if (!empty($q['notcatid'])) {
        $ids = cms_tag_id_list($q['notcatid']);
        if (!empty($ids)) {
            $where[] = 'a.cat_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }
    }
    if (!empty($q['catdir'])) {
        $c = fe_cat_by_dir($q['catdir']);
        $where[]  = 'a.cat_id = ?';
        $params[] = $c ? (int) $c['id'] : -1;
    }
    if (!empty($q['tag'])) {
        $where[]  = 'FIND_IN_SET(?, a.tags)';
        $params[] = (string) $q['tag'];
    }
    if (!empty($q['keyword'])) {
        $where[]  = '(a.title LIKE ? OR a.excerpt LIKE ?)';
        $like     = '%' . str_replace(array('%', '_'), array('\%', '\_'), (string) $q['keyword']) . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($q['exclude'])) {
        $where[]  = 'a.id <> ?';
        $params[] = (int) $q['exclude'];
    }
    if (!empty($q['notid'])) {
        $ids = cms_tag_id_list($q['notid']);
        if (!empty($ids)) {
            $where[] = 'a.id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }
    }
    if (!empty($q['subday'])) {
        $where[]  = 'a.created_at >= ?';
        $params[] = time() - max(1, (int) $q['subday']) * 86400;
    }

    // 推荐位：flag="c" 或 "c,h"（任一命中）
    if (!empty($q['flag'])) {
        $flags = cms_tag_flag_list($q['flag']);
        if (!empty($flags)) {
            $or = array();
            foreach ($flags as $f) {
                $or[] = 'FIND_IN_SET(?, a.flag)';
                $params[] = $f;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
    }
    if (!empty($q['noflag'])) {
        $flags = cms_tag_flag_list($q['noflag']);
        foreach ($flags as $f) {
            $where[]  = 'NOT FIND_IN_SET(?, a.flag)';
            $params[] = $f;
        }
    }

    // idlist：指定 ID，并按给定顺序输出
    $idlist = array();
    if (!empty($q['idlist'])) {
        $idlist = cms_tag_id_list($q['idlist']);
        if (!empty($idlist)) {
            $where[] = 'a.id IN (' . implode(',', array_fill(0, count($idlist), '?')) . ')';
            foreach ($idlist as $id) {
                $params[] = $id;
            }
        }
    }

    $order = cms_tag_order_sql($q);

    $sql = 'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
            FROM {p}article a
            LEFT JOIN {p}category c ON c.id = a.cat_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $order . '
            LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

    $rows = all($sql, $params);
    $rows = empty($rows) ? array() : $rows;

    if (!empty($idlist)) {
        $rows = cms_tag_sort_by_ids($rows, $idlist);
    }
    return cms_tag_rows_post($rows, $q);
}

/** 排序 SQL：兼容 order 与 orderby/orderway 两套写法 */
function cms_tag_order_sql($q)
{
    $way = isset($q['orderway']) ? strtolower((string) $q['orderway']) : '';
    $way = $way === 'asc' ? 'ASC' : 'DESC';

    $order = isset($q['order']) ? strtolower(trim((string) $q['order'])) : '';
    $by    = isset($q['orderby']) ? strtolower(trim((string) $q['orderby'])) : '';

    if ($order === '' && $by !== '') {
        switch ($by) {
            case 'pubdate':
            case 'date':
            case 'created':
                $order = 'new';
                break;
            case 'hot':
            case 'click':
            case 'views':
                $order = 'hot';
                break;
            case 'id':
                $order = 'id';
                break;
            case 'sortrank':
            case 'sort':
                $order = 'top';
                break;
            case 'rand':
            case 'random':
                $order = 'rand';
                break;
            case 'update':
            case 'updated':
                $order = 'updated';
                break;
            default:
                $order = 'new';
        }
    }

    switch ($order) {
        case 'hot':
        case 'views':
            return 'a.views ' . $way . ', a.id DESC';
        case 'top':
            return 'a.is_top DESC, a.id ' . $way;
        case 'id':
            return 'a.id ' . $way;
        case 'rand':
        case 'random':
            return 'RAND()';
        case 'old':
            return 'a.created_at ASC, a.id ASC';
        case 'updated':
            return 'a.updated_at ' . $way . ', a.id DESC';
        case 'new':
        case 'pubdate':
            return 'a.created_at ' . $way . ', a.id ' . $way;
        default:
            return 'a.is_top DESC, a.created_at DESC, a.id DESC';
    }
}

/** 逗号串 → 正整数数组 */
function cms_tag_id_list($v)
{
    $out = array();
    foreach (explode(',', (string) $v) as $s) {
        $s = trim($s);
        if ($s !== '' && ctype_digit($s)) {
            $out[] = (int) $s;
        }
    }
    return array_values(array_unique($out));
}

/** 推荐位串 → 合法 flag 数组 */
function cms_tag_flag_list($v)
{
    $map = cms_flag_map();
    $out = array();
    foreach (explode(',', (string) $v) as $s) {
        $s = strtolower(trim($s));
        if ($s !== '' && isset($map[$s])) {
            $out[] = $s;
        }
    }
    return array_values(array_unique($out));
}

/** 按给定 ID 顺序重排 */
function cms_tag_sort_by_ids($rows, $ids)
{
    $map = array();
    foreach ($rows as $r) {
        $map[(int) $r['id']] = $r;
    }
    $out = array();
    foreach ($ids as $id) {
        if (isset($map[$id])) {
            $out[] = $map[$id];
        }
    }
    return $out;
}

/** 分类列表查询 */
function cms_tag_query_cats($q, $limit, $offset)
{
    $cats = fe_cats();
    $cur  = 0;
    if (!empty($q['current'])) {
        $scope = cms_scope();
        $cur   = isset($scope['current_cat_id']) ? (int) $scope['current_cat_id'] : 0;
    }
    $ids = array();
    if (!empty($q['catid'])) {
        $ids = cms_tag_id_list($q['catid']);
    }
    if (!empty($q['current']) && empty($ids)) {
        $ids = array($cur);
    }
    $dir = !empty($q['catdir']) ? (string) $q['catdir'] : '';

    $out = array();
    foreach ($cats as $c) {
        if (!empty($ids) && !in_array((int) $c['id'], $ids, true)) {
            continue;
        }
        if ($dir !== '' && $c['dirname'] !== $dir) {
            continue;
        }
        $row = cms_row_expand($c);
        $counts = fe_cat_counts();
        $row['count'] = isset($counts[(int) $c['id']]) ? $counts[(int) $c['id']] : 0;
        $row['active'] = ((int) $c['id'] === $cur) ? 1 : 0;
        $row['current'] = $row['active'];
        $out[] = $row;
    }
    return array_slice($out, $offset, $limit);
}

/** 查询结果后处理：标题/摘要截断、多列序号、虚拟字段 */
function cms_tag_rows_post($rows, $q)
{
    if (empty($rows)) {
        return array();
    }
    $titlelen = isset($q['titlelen']) ? (int) $q['titlelen'] : 0;
    $infolen  = isset($q['infolen']) ? (int) $q['infolen'] : 0;
    $col      = isset($q['col']) ? (int) $q['col'] : 0;

    $out = array();
    $i   = 0;
    foreach ($rows as $r) {
        $r = cms_row_expand($r);

        if ($titlelen > 0 && isset($r['title'])) {
            if (!isset($r['fulltitle'])) {
                $r['fulltitle'] = $r['title'];
            }
            $r['shorttitle'] = $r['title'];
            $r['title']      = cms_substr($r['title'], $titlelen);
        }
        if ($infolen > 0 && isset($r['summary'])) {
            if (!isset($r['fullsummary'])) {
                $r['fullsummary'] = $r['summary'];
            }
            $r['summary']     = cms_substr($r['summary'], $infolen);
            $r['description'] = $r['summary'];
        }
        if ($col > 0) {
            $r['col']   = $i % $col + 1;
            $r['rowno'] = (int) floor($i / $col) + 1;
        }
        $r['index'] = $i + 1;
        $out[] = $r;
        $i++;
    }
    return $out;
}
