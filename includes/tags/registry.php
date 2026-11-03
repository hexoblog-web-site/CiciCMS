<?php
/**
 * HtmlCms 标签引擎 · 注册表
 *
 * 每个标签 = includes/tags/single|block/ 下的一个文件，文件被 include 时
 * 返回一份定义数组，注册表把它记到「标签名 → 定义」的映射里。
 *
 * 定义数组的字段：
 *   name     标签名，可写字符串或数组（一个文件负责多个别名）
 *   desc     一句话说明，用于排错与后台展示
 *   block    true 表示这是 {cici:xxx}...{/cici:xxx} 的块标签
 *   compile  编译器函数名，块标签签名 (attrRaw, body)，单标签签名 (ctx)
 *   inline   块标签的「自闭合写法」编译器，如 {cici:position sep="/"}
 *   auto     true 表示允许不写闭合标签（position / prev / next）
 *
 * 新增一个标签只要加一个文件，不需要改这里，也不需要改引擎。
 *
 * 文件：includes/tags/registry.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}

/**
 * 标签注册表。只在编译模板时构建，编译结果缓存命中后完全不参与运行。
 * @return array
 */
function cms_tag_registry()
{
    static $reg = null;
    if ($reg !== null) {
        return $reg;
    }

    $reg = array();
    foreach (array('single', 'block') as $kind) {
        $files = glob(CMS_INC . '/tags/' . $kind . '/*.php');
        if (!is_array($files)) {
            continue;
        }
        sort($files);
        foreach ($files as $f) {
            $def = cms_tag_load_def($f);
            if (!$def) {
                continue;
            }
            $def['kind'] = $kind;
            $def['file'] = 'tags/' . $kind . '/' . basename($f);
            foreach ((array) $def['name'] as $n) {
                $reg[strtolower($n)] = $def;
            }
        }
    }

    return $reg;
}

/**
 * 载入单个标签定义文件。文件格式错误不抛异常，记日志后跳过，
 * 避免一个标签写坏导致整站白屏。
 */
function cms_tag_load_def($file)
{
    $def = include $file;
    if (!is_array($def) || empty($def['name'])) {
        log_msg('标签定义无效：' . $file);
        return null;
    }
    return $def;
}

/** 取某个标签的定义，没有返回 null */
function cms_tag_def($name)
{
    $reg = cms_tag_registry();
    $key = strtolower((string) $name);
    return isset($reg[$key]) ? $reg[$key] : null;
}

/** 是否是块标签（需要闭合） */
function cms_tag_is_block($name)
{
    $def = cms_tag_def($name);
    return $def ? !empty($def['block']) : false;
}

/** 允许省略闭合标签、直接输出默认结果的块标签 */
function cms_tag_auto_ok($name)
{
    $def = cms_tag_def($name);
    return $def ? !empty($def['auto']) : false;
}

/**
 * 字段级覆盖：includes/tags/fields/<字段名>.php
 * 命中时返回定义，字段编译器据此决定取值路径与是否原样输出。
 */
function cms_tag_field_def($name)
{
    static $cache = array();
    $key = strtolower((string) $name);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cache[$key] = null;
    if (!preg_match('#^[a-z_][a-z0-9_]*$#', $key)) {
        return null;
    }
    $file = CMS_INC . '/tags/fields/' . $key . '.php';
    if (is_file($file)) {
        $def = cms_tag_load_def($file);
        if ($def) {
            $cache[$key] = $def;
            return $def;
        }
        log_msg('字段定义无效：' . $file);
    }
    return null;
}

/**
 * 列出全部标签定义，供后台「可用标签」参考或排错使用。
 * @return array 标签名 => 定义
 */
function cms_tag_all()
{
    return cms_tag_registry();
}
