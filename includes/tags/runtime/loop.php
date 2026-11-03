<?php
/**
 * HtmlCms 标签引擎 · 运行时
 * 自定义表白名单循环。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/loop.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 白名单表自由循环：{cici:loop table="article" where="status=1" order="id desc"}
 * where 只接受「字段 运算符 值」用逗号分隔，字段名强校验，值全部参数化。
 */
function cms_tag_loop_rows($o = array())
{
    $o     = is_array($o) ? $o : array();
    $allow = array('article', 'category', 'page', 'flink', 'attachment');
    $table = strtolower(trim((string) (isset($o['table']) ? $o['table'] : 'article')));
    if (!in_array($table, $allow, true)) {
        return array();
    }
    $limit  = cms_tag_int(isset($o['limit']) ? $o['limit'] : 10, 10, 1, 200);
    $offset = max(0, (int) (isset($o['offset']) ? $o['offset'] : 0));

    $w      = array();
    $params = array();
    $raw    = (string) (isset($o['where']) ? $o['where'] : '');
    foreach (explode(',', $raw) as $pair) {
        $pair = trim($pair);
        if ($pair === '') {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(>=|<=|!=|=|>|<)\s*(.+)$/', $pair, $m)) {
            continue;
        }
        $w[]      = '`' . $m[1] . '` ' . $m[2] . ' ?';
        $params[] = trim($m[3], " \t\"'");
    }

    $sql = 'SELECT * FROM {p}' . $table;
    if (!empty($w)) {
        $sql .= ' WHERE ' . implode(' AND ', $w);
    }

    $order = trim((string) (isset($o['order']) ? $o['order'] : 'id desc'));
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(asc|desc))?$/i', $order, $m)) {
        $sql .= ' ORDER BY `' . $m[1] . '` ' . (isset($m[2]) && strtolower($m[2]) === 'asc' ? 'ASC' : 'DESC');
    }
    $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;

    try {
        $rows = all($sql, $params);
    } catch (Exception $e) {
        return array();
    }
    $out = array();
    foreach ($rows as $r) {
        $r['index'] = count($out) + 1;
        $out[] = $r;
    }
    return $out;
}
