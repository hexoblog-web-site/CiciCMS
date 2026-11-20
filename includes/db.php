<?php
/**
 * CiciCMS 数据库层（PDO）
 * 全部查询使用预处理，禁止拼接用户输入
 */

class CmsDb
{
    /** @var PDO|null */
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ));
        } catch (PDOException $e) {
            if (function_exists('log_msg')) {
                log_msg('DB connect failed: ' . $e->getMessage());
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<div style="font:14px/1.8 sans-serif;padding:40px;color:#b91c1c">'
               . '数据库连接失败，请检查站点根目录 config.php 中的数据库配置。<br>'
               . '错误信息：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            exit;
        }
        return self::$pdo;
    }

    public static function prefixSql($sql)
    {
        return str_replace('{p}', DB_PREFIX, $sql);
    }
}

/** 执行语句，返回 PDOStatement */
function q($sql, $params = array())
{
    $st = CmsDb::pdo()->prepare(CmsDb::prefixSql($sql));
    $st->execute($params);
    return $st;
}

/** 取多行 */
function all($sql, $params = array())
{
    return q($sql, $params)->fetchAll();
}

/** 取一行 */
function one($sql, $params = array())
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** 取单值 */
function val($sql, $params = array())
{
    $row = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

/** 白名单表名，防止表名注入 */
function CMS_table($table)
{
    $allow = array('config', 'user', 'category', 'article', 'page', 'attachment', 'flink');
    return in_array($table, $allow, true) ? $table : 'config';
}

function insert_row($table, $data)
{
    $cols = array_keys($data);
    foreach ($cols as $c) {
        CMS_col_check($c);
    }
    $sql  = 'INSERT INTO {p}' . CMS_table($table) . ' (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) CmsDb::pdo()->lastInsertId();
}

/**
 * 列名必须是纯标识符
 *
 * 列名进的是 SQL 标识符位置，占位符保护不到。现在所有调用都是代码里写死的，
 * 但这层校验能让「某天有人用变量拼列名」直接报错，而不是悄悄变成注入点。
 */
function CMS_col_check($col)
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string) $col)) {
        throw new InvalidArgumentException('非法的列名：' . (string) $col);
    }
    return true;
}

/**
 * WHERE 片段只允许「占位符 + 标识符 + 比较符」这类结构
 *
 * update_row / delete_row 的 $where 是直接拼进 SQL 的字符串，占位符只能保护值，
 * 保护不了片段本身。这里收窄到白名单字符，并要求至少有一个 ?：
 *   · 正常写法 'id = ?'、'id IN (?,?)'、'slug = ? AND status = 1' 都能通过
 *   · 注释符、语句分隔符、引号一律拒绝 —— 引号被拒绝，字符串字面量就只能走占位符
 *
 * 参数个数是否与 ? 个数相等，交给 PDO 报错（不在这里重复实现 SQL 词法分析）。
 */
function CMS_where_check($where)
{
    $w = trim((string) $where);
    if ($w === '') {
        throw new InvalidArgumentException('WHERE 片段为空');
    }
    if (strpos($w, '--') !== false || strpos($w, ';') !== false || strpos($w, '/*') !== false) {
        throw new InvalidArgumentException('WHERE 片段含注释或语句分隔符：' . $w);
    }
    // 允许：字母数字下划线、空格、点、反引号、比较符、逗号、括号、问号、减号（负数）
    // 不允许引号：所有字符串值都必须通过 ? 传入
    if (preg_match('#[^A-Za-z0-9_\s.`<>=!,?()\-]#', $w)) {
        throw new InvalidArgumentException('WHERE 片段含非法字符：' . $w);
    }
    return true;
}

function update_row($table, $data, $where, $whereParams = array())
{
    $set = array();
    foreach (array_keys($data) as $c) {
        CMS_col_check($c);
        $set[] = '`' . $c . '`=?';
    }
    CMS_where_check($where);
    $sql = 'UPDATE {p}' . CMS_table($table) . ' SET ' . implode(',', $set) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

function delete_row($table, $where, $params = array())
{
    CMS_where_check($where);
    return q('DELETE FROM {p}' . CMS_table($table) . ' WHERE ' . $where, $params)->rowCount();
}
