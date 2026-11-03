<?php
/**
 * HtmlCms 标签引擎 · 核心引擎
 * 扫描器与编译缓存：把 .html 模板里的 {cici:xxx} 编译成可 include 的 PHP。
 * 这一层只管"怎么扫、怎么缓存、怎么分发"，具体标签在 single/ 与 block/ 下。
 *
 * 文件：includes/tags/engine.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_tag_cache_dir()
{
    return CMS_DATA . '/cache/tpl';
}

function cms_tag_uid()
{
    static $n = 0;
    return ++$n;
}

/** 定位主题内的模板文件，返回绝对路径或空串 */
function cms_tag_locate($name)
{
    $name = trim((string) $name, '/');
    if ($name === '' || !preg_match('#^[A-Za-z0-9_\-/]+$#', $name)) {
        return '';
    }
    $f = theme_path($name . '.html');
    return is_file($f) ? $f : '';
}

/**
 * 编译模板文件，返回可 include 的 PHP 缓存路径。失败返回空串。
 */
function cms_tag_compile($file)
{
    if (!is_file($file)) {
        return '';
    }
    $mtime = (int) @filemtime($file);
    $size  = (int) @filesize($file);
    // 引擎版本进 key，升级后旧缓存自动失效
    $key   = md5($file . '|' . $mtime . '|' . $size . '|' . CMS_VERSION);

    $dir = cms_tag_cache_dir();
    if (!ensure_dir($dir)) {
        return '';
    }
    $cache = $dir . '/' . $key . '.php';
    if (is_file($cache) && (int) @filesize($cache) > 0) {
        return $cache;
    }

    $src = (string) @file_get_contents($file);
    if ($src === '') {
        return '';
    }
    $php = "<?php /* HtmlCms tag cache: " . basename($file) . " */ ?>\n" . cms_tag_parse($src);

    if (!write_file_atomic($cache, $php)) {
        log_msg('模板编译写入失败：' . $cache);
        return '';
    }
    cms_tag_gc($dir);
    return $cache;
}

/** 缓存目录只增不减会占用空间，按 1/20 概率顺手清理过期文件 */
function cms_tag_gc($dir)
{
    if (mt_rand(1, 20) !== 1) {
        return;
    }
    $files = @glob($dir . '/*.php');
    if (!is_array($files) || count($files) < 60) {
        return;
    }
    $now = time();
    foreach ($files as $f) {
        if ($now - (int) @filemtime($f) > 86400 * 7) {
            @unlink($f);
        }
    }
}

/**
 * 把标签源码编译为 PHP。核心扫描器，遇到 { 就看是不是我们的标签。
 */
function cms_tag_parse($src)
{
    if (!is_string($src) || $src === '') {
        return '';
    }
    $ns  = CMS_TAG_NS;
    $re  = '/\G\{' . $ns . ':([a-zA-Z_][a-zA-Z0-9_.]*)([^}]*)\}/';
    $out = '';
    $i   = 0;
    $len = strlen($src);

    while ($i < $len) {
        $p = strpos($src, '{', $i);
        if ($p === false) {
            $out .= substr($src, $i);
            break;
        }
        $out .= substr($src, $i, $p - $i);

        if (!preg_match($re, $src, $m, 0, $p)) {
            // 不是我们的标签，保留原文并跳过这个 {
            $out .= '{';
            $i = $p + 1;
            continue;
        }

        $name  = strtolower($m[1]);
        $tagEnd = $p + strlen($m[0]);
        list($filters, $attrRaw) = cms_tag_split_filters(isset($m[2]) ? $m[2] : '');

        if (cms_tag_is_block($name)) {
            $closeLen = strlen('{/' . $ns . ':' . $name . '}');
            $end      = cms_tag_find_close($src, $name, $tagEnd);
            if ($end < 0) {
                // 有默认输出的块标签可写成空标签，如 {cici:position sep="/"}
                if (cms_tag_auto_ok($name)) {
                    $out .= cms_tag_compile_single($name, $filters, rtrim(trim($attrRaw), '/'));
                    $i = $tagEnd;
                    continue;
                }
                // 未闭合：原样输出，避免整页崩掉
                log_msg('模板标签未闭合：{' . $ns . ':' . $name . '} @ ' . cms_tag_where());
                $out .= $m[0];
                $i = $tagEnd;
                continue;
            }
            $body = substr($src, $tagEnd, $end - $tagEnd);
            $out .= cms_tag_compile_block($name, $attrRaw, $body);
            $i = $end + $closeLen;
            continue;
        }

        $out .= cms_tag_compile_single($name, $filters, $attrRaw);
        $i = $tagEnd;
    }
    return $out;
}

function cms_tag_where()
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
    foreach ($trace as $t) {
        if (empty($t['file'])) {
            continue;
        }
        $f = str_replace('\\', '/', $t['file']);
        // 主题可能放在 content/themes/，也可能是旧版的 templates/
        if (strpos($f, '/content/themes/') !== false || strpos($f, '/templates/') !== false) {
            return basename($t['file']) . ':' . (isset($t['line']) ? $t['line'] : 0);
        }
    }
    return 'unknown';
}

/**
 * 从 $from 起找配对闭合标签，支持同名嵌套。返回闭合标签起始位置，找不到返回 -1。
 */
function cms_tag_find_close($src, $name, $from)
{
    $ns       = CMS_TAG_NS;
    $openTok  = '{' . $ns . ':' . $name;
    $closeTok = '{/' . $ns . ':' . $name . '}';
    $depth    = 1;
    $pos      = $from;

    while (true) {
        $c = strpos($src, $closeTok, $pos);
        if ($c === false) {
            return -1;
        }
        // 找下一个开标签，且必须在闭合标签之前
        $o = false;
        while (true) {
            $o = strpos($src, $openTok, $pos);
            if ($o === false || $o > $c) {
                $o = false;
                break;
            }
            $next = substr($src, $o + strlen($openTok), 1);
            if ($next !== '}' && $next !== ' ' && $next !== "\t" && $next !== "\n" && $next !== "\r" && $next !== '|') {
                $pos = $o + strlen($openTok); // 形如 {cici:listx 不算
                continue;
            }
            break;
        }
        if ($o !== false) {
            $depth++;
            $pos = $o + strlen($openTok);
            continue;
        }
        $depth--;
        if ($depth <= 0) {
            return $c;
        }
        $pos = $c + strlen($closeTok);
    }
}

/* ==================== 编译：分发 ==================== */

/**
 * 编译单标签（含块标签的自闭合写法）。
 *
 * 分发顺序：专属编译器 → 块标签残留符号（丢弃）→ 字段取值兜底。
 * ctx 由引擎统一准备，标签文件只管拼 PHP 代码。
 */
function cms_tag_compile_single($name, $filters = array(), $attrRaw = '')
{
    list($attrRaw, $moreFilters) = cms_tag_extract_filters($attrRaw);
    if (!empty($moreFilters)) {
        $filters = array_merge((array) $filters, $moreFilters);
    }

    $ctx = array(
        'name'    => $name,
        'raw'     => $attrRaw,
        'attrs'   => cms_tag_attrs($attrRaw),
        'filters' => $filters,
        'fcode'   => cms_tag_filters_code($filters),
    );

    $def = cms_tag_def($name);
    if ($def) {
        // 块标签允许不写闭合时（position / prev / next），走 inline 编译器
        if (!empty($def['inline'])) {
            return call_user_func($def['inline'], $ctx);
        }
        // 普通单标签
        if (empty($def['block']) && !empty($def['compile'])) {
            return call_user_func($def['compile'], $ctx);
        }
        // 块标签的残留符号，如 {cici:else} / {cici:empty}，编译期直接丢弃
        return '';
    }

    // 没有任何标签认领这个名字，一律按「取当前文档字段」处理
    return cms_tag_field_compile($ctx);
}

/**
 * 编译块标签。未注册或未提供编译器的标签原样丢弃（与旧版行为一致）。
 */
function cms_tag_compile_block($name, $attrRaw, $body)
{
    $def = cms_tag_def($name);
    if ($def && !empty($def['block']) && !empty($def['compile'])) {
        return call_user_func($def['compile'], $attrRaw, $body);
    }
    log_msg('标签没有编译器：{' . CMS_TAG_NS . ':' . $name . '}');
    return '';
}
