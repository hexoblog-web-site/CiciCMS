<?php
/**
 * 数据库备份（系统能力）
 *
 * 后台「系统设置」和以后的命令行 / 定时任务都会用到，所以放在 includes 里，
 * 不放在 admin/pages/setting.php 里——那里只负责显示表单和接收提交。
 */

/** 备份文件目录 */
function db_backup_dir()
{
    return CMS_DATA . '/backup';
}

/**
 * 参与备份的表（不含前缀）
 * 必须与 install/index.php 的建表清单保持一致，漏一张表恢复后就会丢数据。
 */
function db_backup_tables()
{
    return array('config', 'user', 'category', 'article', 'page', 'flink', 'attachment');
}

/**
 * 生成一份 SQL 备份
 *
 * @return string|false 成功返回文件名（不含目录），失败返回 false
 */
function db_backup()
{
    $out = "-- CiciCMS 数据备份\n-- 生成时间: " . date('Y-m-d H:i:s') . "\n"
         . "-- 数据库: " . DB_NAME . "  前缀: " . DB_PREFIX . "\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    try {
        foreach (db_backup_tables() as $t) {
            $full = DB_PREFIX . $t;
            $rows = all('SELECT * FROM {p}' . $t);
            $out .= "TRUNCATE TABLE `{$full}`;\n";
            foreach ($rows as $row) {
                $vals = array();
                foreach ($row as $v) {
                    $vals[] = ($v === null) ? 'NULL' : CmsDb::pdo()->quote((string) $v);
                }
                $out .= "INSERT INTO `{$full}` (`" . implode('`,`', array_keys($row)) . "`) VALUES (" . implode(',', $vals) . ");\n";
            }
            $out .= "\n";
        }
    } catch (Exception $ex) {
        log_msg('backup failed: ' . $ex->getMessage());
        return false;
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    if (!ensure_dir(db_backup_dir())) {
        return false;
    }

    // 文件名带随机后缀：即使服务器没配好目录保护，也没法靠猜地址下载备份
    $rand = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    $name = 'cicicms-' . date('Ymd-His') . '-' . $rand . '.sql';

    return write_file_atomic(db_backup_dir() . '/' . $name, $out) ? $name : false;
}

/**
 * 列出已有备份，按文件名倒序（新的在前）
 *
 * @return array 文件名数组
 */
function db_backup_list()
{
    $list = array();
    foreach ((array) glob(db_backup_dir() . '/*.sql') as $f) {
        $list[] = basename($f);
    }
    rsort($list);
    return $list;
}

/**
 * 按文件名取备份的绝对路径，名字不合法或文件不存在返回 ''
 *
 * 文件名规则校验放在这里，下载接口就不必再拼一遍路径。
 */
function db_backup_find($name)
{
    $name = basename((string) $name);
    if (!preg_match('/^[A-Za-z0-9_\-]+\.sql$/', $name)) {
        return '';
    }
    $path = db_backup_dir() . '/' . $name;
    return is_file($path) ? $path : '';
}
