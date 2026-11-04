<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 输出与修饰器：转义、|limit |date |raw 等。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/output.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 输出一个值：先跑修饰器，再按需转义。
 * $rawDefault 为 true 时不做 HTML 转义（正文类字段）。
 */
function cms_tag_out($val, $filters = array(), $rawDefault = false)
{
    $raw = (bool) $rawDefault;
    foreach ((array) $filters as $f) {
        $name = isset($f[0]) ? $f[0] : '';
        $args = isset($f[1]) ? $f[1] : array();
        if ($name === 'raw' || $name === 'html' || $name === 'unescape') {
            $raw = true;
            continue;
        }
        if ($name === 'esc' || $name === 'e') {
            $raw = false;
            continue;
        }
        $val = cms_tag_filter($val, $name, $args);
    }
    $s = cms_val_str($val);
    return $raw ? $s : e($s);
}

function cms_tag_filter($val, $name, $args = array())
{
    $s = cms_val_str($val);
    switch ($name) {
        case 'date':
        case 'datetime':
            $fmt = !empty($args[0]) ? $args[0] : ($name === 'datetime' ? 'Y-m-d H:i' : 'Y-m-d');
            $ts  = cms_tag_to_ts($val);
            return $ts > 0 ? date($fmt, $ts) : '';
        case 'ago':
            return human_date(cms_tag_to_ts($val));
        case 'limit':
        case 'cut':
            $len    = isset($args[0]) && $args[0] !== '' ? (int) $args[0] : 100;
            $suffix = isset($args[1]) ? $args[1] : '…';
            return str_limit(strip_tags($s), $len > 0 ? $len : 100, $suffix);
        case 'strip':
            return strip_tags($s);
        case 'nl2br':
            return nl2br($s);
        case 'upper':
            return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
        case 'lower':
            return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        case 'default':
            return trim($s) === '' ? (isset($args[0]) ? $args[0] : '') : $s;
        case 'replace':
            $from = isset($args[0]) ? $args[0] : '';
            $to   = isset($args[1]) ? $args[1] : '';
            return $from === '' ? $s : str_replace($from, $to, $s);
        case 'int':
            return (string) (int) $s;
        case 'num':
            return number_format((float) $s);
        case 'urlencode':
            return rawurlencode($s);
        case 'trim':
            return trim($s);
    }
    // 未认识的修饰器：原样返回，不报错
    return $s;
}

/** 把各种时间表示统一成时间戳 */
function cms_tag_to_ts($v)
{
    if (is_numeric($v)) {
        $n = (int) $v;
        return $n > 0 ? $n : 0;
    }
    $s = trim((string) $v);
    if ($s === '') {
        return 0;
    }
    $t = strtotime($s);
    return $t === false ? 0 : $t;
}

function cms_tag_int($v, $default = 0, $min = null, $max = null)
{
    if (!is_numeric($v)) {
        $v = $default;
    }
    $v = (int) $v;
    if ($min !== null && $v < $min) {
        $v = $min;
    }
    if ($max !== null && $v > $max) {
        $v = $max;
    }
    return $v;
}

function cms_substr($s, $len)
{
    $s = (string) $s;
    $len = (int) $len;
    if ($len <= 0) {
        return $s;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $len, 'UTF-8');
    }
    return substr($s, 0, $len);
}
