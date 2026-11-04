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

/* ============ 恢复 ============ */

/**
 * 把 SQL 文本切成一条条语句
 *
 * 不能简单按分号 explode：正文里完全可能出现分号（教程、代码片段），
 * 它们被 quote() 转义后仍然带着分号躺在字符串里。这里做一个小状态机，
 * 识别单引号、双引号、反引号、行注释与块注释，只在语句之间的分号上切开。
 *
 * @return array 语句数组（已去空白）
 */
function db_sql_split($sql)
{
    $out   = array();
    $buf   = '';
    $len   = strlen($sql);
    $inS   = false; // 单引号字符串
    $inD   = false; // 双引号字符串
    $inB   = false; // 反引号标识符
    $lineC = false; // -- 或 # 行注释
    $blkC  = false; // /* */ 块注释

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($lineC) {
            $buf .= $c;
            if ($c === "\n") {
                $lineC = false;
            }
            continue;
        }
        if ($blkC) {
            $buf .= $c;
            if ($c === '*' && $n === '/') {
                $buf .= $n;
                $i++;
                $blkC = false;
            }
            continue;
        }
        if ($inS) {
            $buf .= $c;
            if ($c === '\\') {
                $buf .= $n;
                $i++;
            } elseif ($c === "'") {
                if ($n === "'") { // '' 是转义的单引号
                    $buf .= $n;
                    $i++;
                } else {
                    $inS = false;
                }
            }
            continue;
        }
        if ($inD) {
            $buf .= $c;
            if ($c === '\\') {
                $buf .= $n;
                $i++;
            } elseif ($c === '"') {
                $inD = false;
            }
            continue;
        }
        if ($inB) {
            $buf .= $c;
            if ($c === '`') {
                $inB = false;
            }
            continue;
        }

        // 不在任何引号内
        if ($c === '-' && $n === '-') {
            $lineC = true;
            $buf  .= $c;
            continue;
        }
        if ($c === '#') {
            $lineC = true;
            $buf  .= $c;
            continue;
        }
        if ($c === '/' && $n === '*') {
            $blkC = true;
            $buf .= $c . $n;
            $i++;
            continue;
        }
        if ($c === "'") {
            $inS = true;
            $buf .= $c;
            continue;
        }
        if ($c === '"') {
            $inD = true;
            $buf .= $c;
            continue;
        }
        if ($c === '`') {
            $inB = true;
            $buf .= $c;
            continue;
        }
        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') {
                $out[] = $t;
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }

    $t = trim($buf);
    if ($t !== '') {
        $out[] = $t;
    }

    return $out;
}

/** 去掉语句开头的注释，便于判断类型 */
function db_sql_strip_head($stmt)
{
    return ltrim(preg_replace('/^(?:\s|--[^\n]*\n|#[^\n]*\n|\/\*.*?\*\/)*/s', '', (string) $stmt));
}

/**
 * 判断一条语句是否允许在恢复时执行
 *
 * 只放行备份文件本来就会产生的三类语句，其余（DROP / ALTER / CREATE / UPDATE 任意表）
 * 一律拒绝。即使有人把恶意 SQL 传上来，也改不了程序预期之外的东西。
 */
function db_restore_statement_allowed($stmt)
{
    $s      = db_sql_strip_head($stmt);
    $tables = array();
    foreach (db_backup_tables() as $t) {
        $tables[] = DB_PREFIX . $t;
    }

    if (preg_match('/^SET\s+/i', $s)) {
        return true;
    }

    if (preg_match('/^(?:INSERT|REPLACE)\s+INTO\s+`?([A-Za-z0-9_]+)`?/i', $s, $m)) {
        return in_array($m[1], $tables, true);
    }
    if (preg_match('/^(?:TRUNCATE\s+TABLE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', $s, $m)) {
        return in_array($m[1], $tables, true);
    }

    return false;
}

/**
 * 从备份文件恢复数据库
 *
 * 流程：校验文件头 → 逐条过滤 → 事务内执行 → 失败整体回滚。
 * 恢复前会自动再做一次备份，万一选错文件还能退回去。
 *
 * @param string $path  .sql 绝对路径
 * @param array  $info  出参：statements / executed / skipped / backup
 * @return bool
 */
function db_restore_file($path, &$info = array())
{
    $info = array('statements' => 0, 'executed' => 0, 'skipped' => 0, 'backup' => '', 'error' => '');

    if (!is_file($path)) {
        $info['error'] = '备份文件不存在';
        return false;
    }
    if (filesize($path) > 64 * 1024 * 1024) {
        $info['error'] = '备份文件超过 64MB，请用命令行导入';
        return false;
    }

    $sql = (string) file_get_contents($path);
    if ($sql === '') {
        $info['error'] = '备份文件是空的';
        return false;
    }
    if (strpos(substr($sql, 0, 400), 'CiciCMS 数据备份') === false) {
        $info['error'] = '这不是 CiciCMS 的备份文件，已拒绝导入';
        return false;
    }

    $stmts = db_sql_split($sql);
    $info['statements'] = count($stmts);
    if (!$stmts) {
        $info['error'] = '备份文件里没有可执行语句';
        return false;
    }

    // 先留一份当前数据，选错文件可以立刻退回去
    $pre = db_backup();
    if ($pre) {
        $info['backup'] = $pre;
    }

    $pdo = CmsDb::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    $ok = false;
    try {
        $pdo->beginTransaction();
        foreach ($stmts as $s) {
            if (!db_restore_statement_allowed($s)) {
                $info['skipped']++;
                continue;
            }
            // TRUNCATE 会隐式提交，事务就失去意义了；换成等价的 DELETE，全程可回滚
            $run = preg_replace('/^(\s*)(?:TRUNCATE\s+TABLE)/i', '$1DELETE FROM', db_sql_strip_head($s));
            $pdo->exec($run);
            $info['executed']++;
        }
        $pdo->commit();
        $ok = true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $info['error'] = '恢复失败，已回滚：' . $ex->getMessage();
        log_msg('restore failed: ' . $ex->getMessage());
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ex) {
        // 连接已断时忽略
    }

    if ($ok) {
        // 设置缓存里还是旧值，立刻失效，页面才显示恢复后的站点信息
        settings_all(true);
        log_msg('database restored from ' . basename($path) . ' (' . $info['executed'] . ' statements)');
    }

    return $ok;
}

/** 删除一个备份文件 */
function db_backup_delete($name)
{
    $path = db_backup_find($name);
    if ($path === '') {
        return false;
    }
    return @unlink($path);
}

/** 备份文件大小，用于列表展示 */
function db_backup_size($name)
{
    $path = db_backup_find($name);
    if ($path === '') {
        return 0;
    }
    return (int) filesize($path);
}
