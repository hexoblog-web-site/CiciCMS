<?php
/**
 * HtmlCms 标签引擎 · 运行时
 * 条件表达式求值：{cici:if} 编译出来的布尔判断。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/condition.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_cond($expr)
{
    $expr = trim((string) $expr);
    if ($expr === '') {
        return false;
    }
    $parts = cms_cond_split($expr, '||');
    if (count($parts) > 1) {
        foreach ($parts as $p) {
            if (cms_cond_and($p)) {
                return true;
            }
        }
        return false;
    }
    return cms_cond_and($expr);
}

function cms_cond_and($expr)
{
    $parts = cms_cond_split($expr, '&&');
    if (count($parts) > 1) {
        foreach ($parts as $p) {
            if (!cms_cond_atom($p)) {
                return false;
            }
        }
        return true;
    }
    return cms_cond_atom($expr);
}

/** 按顶层运算符切分，引号内的不算 */
function cms_cond_split($expr, $op)
{
    $parts = array();
    $buf   = '';
    $quote = '';
    $len   = strlen($expr);
    $opLen = strlen($op);
    for ($i = 0; $i < $len; $i++) {
        $c = $expr[$i];
        if ($quote !== '') {
            if ($c === $quote) {
                $quote = '';
            }
            $buf .= $c;
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
            $buf .= $c;
            continue;
        }
        if (substr($expr, $i, $opLen) === $op) {
            $parts[] = $buf;
            $buf = '';
            $i += $opLen - 1;
            continue;
        }
        $buf .= $c;
    }
    $parts[] = $buf;
    return $parts;
}

function cms_cond_atom($expr)
{
    $e = trim($expr);
    if ($e === '') {
        return false;
    }
    // 括号
    if ($e[0] === '(' && substr($e, -1) === ')') {
        return cms_cond(substr($e, 1, -1));
    }
    // 取反
    if ($e[0] === '!') {
        if (substr($e, 0, 2) === '!=') {
            // 落到下面比较
        } else {
            return !cms_cond_atom(substr($e, 1));
        }
    }
    // 比较运算，长的先试
    foreach (array('>=', '<=', '==', '!=', '>', '<') as $op) {
        $p = cms_cond_find_op($e, $op);
        if ($p !== false) {
            $l = cms_cond_value(substr($e, 0, $p));
            $r = cms_cond_value(substr($e, $p + strlen($op)));
            return cms_cond_cmp($l, $r, $op);
        }
    }
    return cms_cond_truthy(cms_cond_value($e));
}

function cms_cond_find_op($expr, $op)
{
    $quote = '';
    $len   = strlen($expr);
    $opLen = strlen($op);
    for ($i = 0; $i < $len; $i++) {
        $c = $expr[$i];
        if ($quote !== '') {
            if ($c === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
            continue;
        }
        if (substr($expr, $i, $opLen) !== $op) {
            continue;
        }
        if ($op === '>' && substr($expr, $i, 2) === '>=') {
            continue;
        }
        if ($op === '<' && substr($expr, $i, 2) === '<=') {
            continue;
        }
        if ($op === '!') {
            continue;
        }
        return $i;
    }
    return false;
}

function cms_cond_value($s)
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if ($s[0] === '$') {
        // 条件里必须保留原始类型，数组/布尔才能正确判真假
        return cms_raw(substr($s, 1));
    }
    if ($s[0] === '"' && substr($s, -1) === '"') {
        return substr($s, 1, -1);
    }
    if ($s[0] === "'" && substr($s, -1) === "'") {
        return substr($s, 1, -1);
    }
    if (is_numeric($s)) {
        return $s + 0;
    }
    $low = strtolower($s);
    if ($low === 'true') {
        return true;
    }
    if ($low === 'false') {
        return false;
    }
    if ($low === 'null') {
        return null;
    }
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $s)) {
        return cms_raw($s);
    }
    return $s;
}

function cms_cond_cmp($l, $r, $op)
{
    if ($op === '==') {
        return cms_cond_equal($l, $r);
    }
    if ($op === '!=') {
        return !cms_cond_equal($l, $r);
    }
    if (is_numeric($l) && is_numeric($r)) {
        $l = $l + 0;
        $r = $r + 0;
    } else {
        $l = (string) $l;
        $r = (string) $r;
    }
    switch ($op) {
        case '>':
            return $l > $r;
        case '<':
            return $l < $r;
        case '>=':
            return $l >= $r;
        case '<=':
            return $l <= $r;
    }
    return false;
}

function cms_cond_equal($l, $r)
{
    if (is_bool($l) || is_bool($r)) {
        return cms_cond_truthy($l) === cms_cond_truthy($r);
    }
    if (is_numeric($l) && is_numeric($r)) {
        return ($l + 0) == ($r + 0);
    }
    return (string) $l === (string) $r;
}

function cms_cond_truthy($v)
{
    if (is_bool($v)) {
        return $v;
    }
    if ($v === null) {
        return false;
    }
    if (is_string($v)) {
        return $v !== '' && $v !== '0';
    }
    return !empty($v);
}
