<?php
/**
 * HtmlCms 标签引擎 · 运行时
 * 模板片段引入。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/include.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 渲染 .html 标签模板。$vars 与 PHP 模板保持一致（site / posts / post / cat / pager ...）
 */
function cms_tag_render_file($file, array $vars = array())
{
    $cache = cms_tag_compile($file);
    if ($cache === '') {
        $msg = '模板渲染失败（未找到或无法编译）：' . $file;
        log_msg($msg);
        // 生成静态时抛出，交给 build_tick 记错误并跳过该任务；
        // 返回空串会被 build_put 当成正常内容写盘，出现整站空白。
        if (!empty($GLOBALS['cms_static_mode'])) {
            throw new RuntimeException($msg);
        }
        return '';
    }

    $GLOBALS['__cms_scope'] = $vars;
    if (!isset($GLOBALS['__cms_loop'])) {
        $GLOBALS['__cms_loop'] = array();
    }

    ob_start();
    include $cache;
    return ob_get_clean();
}

/** 片段引入：优先 .html，其次 .php；不传 scope 时继承当前模板变量 */
function cms_tag_include($name, $scope = null)
{
    $name = trim((string) $name, '/');
    if ($name === '') {
        return '';
    }
    $name = preg_replace('/\.(html|php)$/i', '', $name);
    if (!preg_match('#^[A-Za-z0-9_\-/]+$#', $name)) {
        return '';
    }
    $vars = is_array($scope) ? $scope : cms_scope();
    $base = theme_path($name);
    if (is_file($base . '.html')) {
        return cms_tag_render_file($base . '.html', $vars);
    }
    if (is_file($base . '.php')) {
        return render_php_file($base . '.php', $vars);
    }
    log_msg('模板片段缺失：' . $name);
    return '';
}
