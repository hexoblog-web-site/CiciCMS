<?php
/**
 * CiciCMS 标签引擎 · 编译上下文
 * 编译期的公共工具：属性解析、修饰器链、变量路径、循环体生成。
 * single/ 与 block/ 下的编译器都靠这些函数拼 PHP 代码。
 *
 * 文件：includes/tags/context.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 把 {cici:xxx} 后的内容拆成「修饰器链」和「属性串」。
 *   |limit:20|date:"Y-m-d" catid="1"
 * 返回 array($filters, $attrRaw)
 */
function cms_tag_split_filters($rest)
{
    $rest    = (string) $rest;
    $filters = array();
    if ($rest === '' || $rest[0] !== '|') {
        return array($filters, $rest);
    }
    $pos = 0;
    $len = strlen($rest);

    while ($pos < $len && $rest[$pos] === '|') {
        $pos++;
        if (!preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)/', $rest, $m, 0, $pos)) {
            break;
        }
        $fname = strtolower($m[1]);
        $pos  += strlen($m[1]);
        $args  = array();

        while ($pos < $len && $rest[$pos] === ':') {
            $pos++;
            if ($pos < $len && ($rest[$pos] === '"' || $rest[$pos] === "'")) {
                $qc = $rest[$pos];
                $pos++;
                $buf = '';
                while ($pos < $len && $rest[$pos] !== $qc) {
                    $buf .= $rest[$pos];
                    $pos++;
                }
                if ($pos < $len) {
                    $pos++;
                }
                $args[] = $buf;
            } else {
                $buf = '';
                while ($pos < $len && $rest[$pos] !== '|' && $rest[$pos] !== ':' && $rest[$pos] !== ' ' && $rest[$pos] !== "\t") {
                    $buf .= $rest[$pos];
                    $pos++;
                }
                $args[] = $buf;
            }
        }
        $filters[] = array($fname, $args);
        // 属性前通常有空格，跳到属性起点
        while ($pos < $len && ($rest[$pos] === ' ' || $rest[$pos] === "\t" || $rest[$pos] === "\n" || $rest[$pos] === "\r")) {
            $pos++;
        }
    }
    return array($filters, substr($rest, $pos));
}

/**
 * 从属性串里抽出修饰器，支持写在属性之后：
 *   {cici:field name="pubdate"|date:"Y-m-d"}
 * 引号内的 | 不算分隔符。
 */
function cms_tag_extract_filters($raw)
{
    $raw     = (string) $raw;
    $filters = array();
    if (strpos($raw, '|') === false) {
        return array($raw, $filters);
    }
    $out   = '';
    $len   = strlen($raw);
    $quote = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($quote !== '') {
            if ($c === $quote) {
                $quote = '';
            }
            $out .= $c;
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
            $out .= $c;
            continue;
        }
        if ($c !== '|') {
            $out .= $c;
            continue;
        }
        // 命中修饰器：解析 name 与 :arg
        if (!preg_match('/\G\|([a-zA-Z_][a-zA-Z0-9_]*)/', $raw, $m, 0, $i)) {
            $out .= $c;
            continue;
        }
        $fname = strtolower($m[1]);
        $i    += strlen($m[0]);
        $args  = array();
        while ($i < $len && $raw[$i] === ':') {
            $i++;
            if ($i < $len && ($raw[$i] === '"' || $raw[$i] === "'")) {
                $qc = $raw[$i];
                $i++;
                $buf = '';
                while ($i < $len && $raw[$i] !== $qc) {
                    $buf .= $raw[$i];
                    $i++;
                }
                if ($i < $len) {
                    $i++;
                }
                $args[] = $buf;
            } else {
                $buf = '';
                while ($i < $len && $raw[$i] !== '|' && $raw[$i] !== ':' && $raw[$i] !== ' ' && $raw[$i] !== "\t") {
                    $buf .= $raw[$i];
                    $i++;
                }
                $args[] = $buf;
            }
        }
        $i--; // 外层 for 会再 ++
        $filters[] = array($fname, $args);
    }
    return array($out, $filters);
}

/** 解析 key="v" 形式的属性串；没有 key 的整体放到 '' 键下（if 条件用） */
function cms_tag_attrs($raw)
{
    $out = array();
    $raw = (string) $raw;
    if (trim($raw) === '') {
        return $out;
    }
    $re = '/([a-zA-Z_][a-zA-Z0-9_\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/u';
    if (preg_match_all($re, $raw, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $v = '';
            if (isset($m[2]) && $m[2] !== '') {
                $v = $m[2];
            } elseif (isset($m[3]) && $m[3] !== '') {
                $v = $m[3];
            } elseif (isset($m[4])) {
                $v = $m[4];
            }
            $out[strtolower($m[1])] = $v;
        }
    }
    return $out;
}

/** 属性值 → PHP 表达式；以 $ 开头视为变量路径，运行时取值 */
function cms_tag_attr_expr($v)
{
    $v = (string) $v;
    if ($v !== '' && $v[0] === '$') {
        $path = substr($v, 1);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $path)) {
            return 'cms_val(' . var_export($path, true) . ')';
        }
    }
    return var_export($v, true);
}

/** 去掉 $ 前缀，得到变量路径 */
function cms_tag_attrv($v)
{
    $v = (string) $v;
    return ($v !== '' && $v[0] === '$') ? substr($v, 1) : $v;
}

/** 数组 → PHP 数组字面量，允许其中混入变量表达式 */
function cms_tag_qcode(array $q)
{
    if (empty($q)) {
        return 'array()';
    }
    $pairs = array();
    foreach ($q as $k => $v) {
        if (is_int($v) || is_float($v) || is_bool($v)) {
            $pairs[] = var_export($k, true) . ' => ' . ($v ? '1' : '0');
        } else {
            $pairs[] = var_export($k, true) . ' => ' . cms_tag_attr_expr($v);
        }
    }
    return 'array(' . implode(', ', $pairs) . ')';
}

/** 修饰器链 → PHP 数组字面量 */
function cms_tag_filters_code(array $filters)
{
    if (empty($filters)) {
        return 'array()';
    }
    $out = array();
    foreach ($filters as $f) {
        $args = array();
        foreach ($f[1] as $v) {
            $args[] = var_export($v, true);
        }
        $out[] = 'array(' . var_export($f[0], true) . ', array(' . implode(', ', $args) . '))';
    }
    return 'array(' . implode(', ', $out) . ')';
}

/** 路径末段是否属于「原样输出」字段 */
function cms_tag_is_raw_path($path)
{
    $path = (string) $path;
    $last = strrpos($path, '.');
    $last = $last === false ? $path : substr($path, $last + 1);
    return in_array(strtolower($last), cms_tag_raw_fields(), true);
}

/** {cici:field name="x"} → 变量路径（兼容字段别名） */
function cms_tag_path($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    if (strpos($name, '.') !== false) {
        return $name;
    }
    $alias = cms_tag_field_alias();
    if (isset($alias[$name])) {
        $name = $alias[$name];
    }
    // 页面级变量优先，其余一律按「当前文档字段」解析
    $pageVars = array('kw', 'tag_name', 'body_class', 'canonical', 'desc', 'keywords', 'noindex');
    if (in_array($name, $pageVars, true)) {
        return $name;
    }
    return 'post.' . $name;
}

/** 共用：列表类块标签的循环体生成 */
function cms_tag_loop_code($rowsExpr, $body)
{
    $uid = cms_tag_uid();
    $k   = '$__k' . $uid;
    $v   = '$__v' . $uid;
    return '<?php foreach (' . $rowsExpr . ' as ' . $k . ' => ' . $v . '): cms_loop_push(' . $v . ', ' . $k . '); ?>'
         . cms_tag_parse($body)
         . '<?php cms_loop_pop(); endforeach; ?>';
}

/** 在外层 if 的 body 里找 {cici:else}，跳过嵌套 if */
function cms_tag_split_else($body)
{
    $ns      = CMS_TAG_NS;
    $openTok = '{' . $ns . ':if';
    $closeLen = strlen('{/' . $ns . ':if}');
    $pos     = 0;

    while (true) {
        $e1 = strpos($body, '{' . $ns . ':else}', $pos);
        $e2 = strpos($body, '{' . $ns . ':else/}', $pos);
        $e  = false;
        $eLen = 0;
        if ($e1 !== false) {
            $e    = $e1;
            $eLen = strlen('{' . $ns . ':else}');
        }
        if ($e2 !== false && ($e === false || $e2 < $e)) {
            $e    = $e2;
            $eLen = strlen('{' . $ns . ':else/}');
        }
        if ($e === false) {
            return array($body, '');
        }
        // 前面若有未闭合的嵌套 if，跳过
        $before = substr($body, 0, $e);
        $depth  = substr_count($before, $openTok) - substr_count($before, '{/' . $ns . ':if}');
        if ($depth <= 0) {
            return array(substr($body, 0, $e), substr($body, $e + $eLen));
        }
        $pos = $e + $eLen;
    }
    return array($body, '');
}

/** 列表块：把属性映射成查询参数 */
function cms_tag_list_q(array $a, $defaultType = 'article')
{
    $map = array(
        'type'     => 'type',
        'catid'    => 'catid',
        'typeid'   => 'catid',
        'catdir'   => 'catdir',
        'dirname'  => 'catdir',
        'tag'      => 'tag',
        'tags'     => 'tag',
        'keyword'  => 'keyword',
        'keywords' => 'keyword',
        'limit'    => 'limit',
        'row'      => 'limit',
        'offset'   => 'offset',
        'start'    => 'offset',
        'order'    => 'order',
        'orderby'  => 'orderby',
        'orderway' => 'orderway',
        'current'  => 'current',
        'exclude'  => 'exclude',
        'notid'    => 'notid',
        'flag'     => 'flag',
        'noflag'   => 'noflag',
        'idlist'   => 'idlist',
        'subday'   => 'subday',
        'titlelen' => 'titlelen',
        'infolen'  => 'infolen',
        'col'      => 'col',
        'notcatid' => 'notcatid',
        'notypeid' => 'notcatid',
    );
    $q = array('type' => $defaultType);
    foreach ($map as $k => $to) {
        if (isset($a[$k]) && $a[$k] !== '') {
            $q[$to] = $a[$k];
        }
    }
    return $q;
}

function cms_tag_split_block_empty($body)
{
    $ns   = CMS_TAG_NS;
    $tag  = '{' . $ns . ':empty}';
    $tag2 = '{' . $ns . ':empty/}';
    $p    = strpos($body, $tag);
    $len  = strlen($tag);
    $p2   = strpos($body, $tag2);
    if ($p2 !== false && ($p === false || $p2 < $p)) {
        $p   = $p2;
        $len = strlen($tag2);
    }
    if ($p === false) {
        return array($body, '');
    }
    return array(substr($body, 0, $p), substr($body, $p + $len));
}
