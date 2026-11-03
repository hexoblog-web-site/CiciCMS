<?php
/**
 * HtmlCms 数据库层（PDO）
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
function cms_table($table)
{
    $allow = array('config', 'user', 'category', 'article', 'page', 'attachment', 'flink');
    return in_array($table, $allow, true) ? $table : 'config';
}

function insert_row($table, $data)
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO {p}' . cms_table($table) . ' (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) CmsDb::pdo()->lastInsertId();
}

function update_row($table, $data, $where, $whereParams = array())
{
    $set = array();
    foreach (array_keys($data) as $c) {
        $set[] = '`' . $c . '`=?';
    }
    $sql = 'UPDATE {p}' . cms_table($table) . ' SET ' . implode(',', $set) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

function delete_row($table, $where, $params = array())
{
    return q('DELETE FROM {p}' . cms_table($table) . ' WHERE ' . $where, $params)->rowCount();
}
