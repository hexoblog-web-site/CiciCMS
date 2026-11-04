<?php
/**
 * CiciCMS 插件内核
 *
 * 设计目标：让插件能真正介入程序流程，而不只是改样式。
 *
 * 一个插件就是一个目录：content/plugins/<id>/
 *   plugin.json   元信息（名称、版本、说明、后台页面），不执行任何代码，用于列表展示
 *   plugin.php    逻辑入口，可在此注册钩子；只有被启用时才会被加载
 *   admin.php     可选，后台页面，在「插件管理」里点进去时载入
 *   assets/       可选，插件自带的 css / js / 图片
 *
 * 钩子分两类：
 *   filter  接管一个值并返回新值（例如给正文补一段 HTML）
 *   action  在某个时机做一件事，不返回值（例如文章保存后同步到别处）
 *
 * 全部钩子点见 cms_hook_docs()，后台「插件管理」里也会列出来。
 */

/** 插件目录 */
function cms_plugins_dir()
{
    return CMS_CONTENT . '/plugins';
}

/** 合法插件 ID：小写字母、数字、下划线、连字符 */
function cms_plugin_id_ok($id)
{
    return is_string($id) && preg_match('/^[a-z0-9_\-]{1,40}$/', $id) === 1;
}

/**
 * 钩子点清单（同时作为后台文档）
 *
 * @return array 钩子名 => array(类型, 说明, 参数)
 */
function cms_hook_docs()
{
    return array(
        'html_head'      => array('filter', '静态页与动态页的 &lt;/head&gt; 之前，用来插入 CSS / meta', 'html'),
        'html_foot'      => array('filter', '静态页与动态页的 &lt;/body&gt; 之前，用来插入 JS', 'html'),
        'html_final'     => array('filter', '整页 HTML 写盘前的最后一道，返回值即最终内容', 'html, context'),
        'content_html'   => array('filter', '正文渲染完成、入库之前，可在此改写 HTML', 'html, src'),
        'article_saved'  => array('action', '文章新增或更新之后', 'id, data, isNew'),
        'article_render' => array('filter', '内容页渲染前，可修改文章数据', 'post'),
        'build_start'    => array('action', '静态生成开始', 'total'),
        'build_done'     => array('action', '静态生成结束', 'files, errors'),
        'admin_menu'     => array('filter', '后台左侧菜单，可增删菜单项', 'nav'),
        'admin_head'     => array('action', '后台 &lt;/head&gt; 之前，用来插入后台样式', ''),
        'admin_foot'     => array('action', '后台 &lt;/body&gt; 之前，用来插入后台脚本', ''),
        'setting_saved'  => array('action', '系统设置保存之后', 'keys'),
        'search_query'   => array('filter', '前台搜索关键词，入库查询前', 'keyword'),
    );
}

/* ============ 钩子注册与执行 ============ */

/**
 * 注册过滤器
 *
 * @param string   $tag      钩子名
 * @param callable $callback 回调
 * @param int      $priority 数字小的先执行
 * @param int      $args     回调接收的参数个数
 */
function cms_add_filter($tag, $callback, $priority = 10, $args = 1)
{
    cms_hook_add('filter', $tag, $callback, $priority, $args);
}

/** 注册动作 */
function cms_add_action($tag, $callback, $priority = 10, $args = 1)
{
    cms_hook_add('action', $tag, $callback, $priority, $args);
}

function cms_hook_add($type, $tag, $callback, $priority, $args)
{
    if (!isset($GLOBALS['cms_hooks'][$type])) {
        $GLOBALS['cms_hooks'][$type] = array();
    }
    $p = (int) $priority;
    if (!isset($GLOBALS['cms_hooks'][$type][$tag])) {
        $GLOBALS['cms_hooks'][$type][$tag] = array();
    }
    $GLOBALS['cms_hooks'][$type][$tag][$p][] = array(
        'fn'    => $callback,
        'args'  => max(1, (int) $args),
        'owner' => isset($GLOBALS['cms_plugin_current']) ? $GLOBALS['cms_plugin_current'] : '',
    );
}

/**
 * 执行过滤器：把值依次交给每个回调，用返回值继续传递
 *
 * 回调抛异常时只记日志并跳过，不让一个插件把整站渲染搞挂。
 */
function cms_apply_filters($tag, $value)
{
    $extra = array_slice(func_get_args(), 2);
    if (empty($GLOBALS['cms_hooks']['filter'][$tag])) {
        return $value;
    }
    $bucket = $GLOBALS['cms_hooks']['filter'][$tag];
    ksort($bucket);
    foreach ($bucket as $list) {
        foreach ($list as $h) {
            $args = array_merge(array($value), array_slice($extra, 0, $h['args'] - 1));
            try {
                $value = call_user_func_array($h['fn'], $args);
            } catch (Throwable $ex) {
                log_msg('plugin filter ' . $tag . ' failed [' . $h['owner'] . ']: ' . $ex->getMessage());
            }
        }
    }
    return $value;
}

/** 执行动作：不关心返回值 */
function cms_do_action($tag)
{
    $args = array_slice(func_get_args(), 1);
    if (empty($GLOBALS['cms_hooks']['action'][$tag])) {
        return;
    }
    $bucket = $GLOBALS['cms_hooks']['action'][$tag];
    ksort($bucket);
    foreach ($bucket as $list) {
        foreach ($list as $h) {
            try {
                call_user_func_array($h['fn'], array_slice($args, 0, $h['args']));
            } catch (Throwable $ex) {
                log_msg('plugin action ' . $tag . ' failed [' . $h['owner'] . ']: ' . $ex->getMessage());
            }
        }
    }
}

/* ============ 插件发现与加载 ============ */

/**
 * 扫描插件目录，返回 id => 元信息
 *
 * 只读 plugin.json，不执行插件代码，所以停用的插件也能正常显示。
 *
 * @return array
 */
function cms_plugin_scan()
{
    $list = array();
    $dir  = cms_plugins_dir();
    if (!is_dir($dir)) {
        return $list;
    }

    foreach ((array) @scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $base = $dir . '/' . $entry;
        if (!is_dir($base) || !cms_plugin_id_ok($entry)) {
            continue;
        }
        $metaFile = $base . '/plugin.json';
        if (!is_file($metaFile) || !is_file($base . '/plugin.php')) {
            continue;
        }
        $raw  = (string) @file_get_contents($metaFile);
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            $meta = array();
        }

        $list[$entry] = array(
            'id'          => $entry,
            'name'        => isset($meta['name']) && $meta['name'] !== '' ? (string) $meta['name'] : $entry,
            'version'     => isset($meta['version']) ? (string) $meta['version'] : '1.0.0',
            'description' => isset($meta['description']) ? (string) $meta['description'] : '',
            'author'      => isset($meta['author']) ? (string) $meta['author'] : '',
            'admin'       => isset($meta['admin']) && is_array($meta['admin']) ? $meta['admin'] : array(),
            'dir'         => $base,
            'active'      => false,
        );
    }

    ksort($list);
    return $list;
}

/** 已启用的插件 ID 列表（存在 config 表，随备份一起迁移） */
function cms_plugin_active_ids()
{
    $all = settings_all();
    if (!array_key_exists('plugins_active', $all)) {
        // 老站点升级上来时还没有这个键：默认只开代码高亮，其余交给用户自己决定
        return is_dir(cms_plugins_dir() . '/highlight') ? array('highlight') : array();
    }

    $raw = (string) $all['plugins_active'];
    if ($raw === '') {
        return array();
    }
    $ids = json_decode($raw, true);
    if (!is_array($ids)) {
        $ids = array_filter(array_map('trim', explode(',', $raw)));
    }
    $out = array();
    foreach ($ids as $id) {
        if (cms_plugin_id_ok($id)) {
            $out[] = $id;
        }
    }
    return array_values(array_unique($out));
}

/** 保存启用列表 */
function cms_plugin_save_active(array $ids)
{
    $clean = array();
    foreach ($ids as $id) {
        if (cms_plugin_id_ok($id)) {
            $clean[] = $id;
        }
    }
    $clean = array_values(array_unique($clean));
    set_setting('plugins_active', json_encode($clean));
    return $clean;
}

/** 某个插件是否启用 */
function cms_plugin_active($id)
{
    return in_array($id, cms_plugin_active_ids(), true);
}

/**
 * 加载所有已启用的插件
 *
 * 插件 ID 会写进 $GLOBALS['cms_plugin_current']，钩子回调抛出异常时能定位到是哪个插件。
 * 插件加载失败只记日志，不影响站点本身。
 *
 * @return array 实际加载成功的 ID
 */
function cms_plugin_load()
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $loaded = array();

    $active = cms_plugin_active_ids();
    if (empty($active)) {
        return $loaded;
    }

    $all = cms_plugin_scan();
    foreach ($active as $id) {
        if (!isset($all[$id])) {
            continue; // 插件目录被删了，静默跳过
        }
        $file = $all[$id]['dir'] . '/plugin.php';
        $GLOBALS['cms_plugin_current'] = $id;
        try {
            include_once $file;
            $loaded[] = $id;
        } catch (Throwable $ex) {
            log_msg('plugin load failed [' . $id . ']: ' . $ex->getMessage());
        }
    }
    $GLOBALS['cms_plugin_current'] = '';

    return $loaded;
}

/**
 * 插件内资源的 URL
 *
 * @param string $id   插件 ID
 * @param string $path 相对插件目录的路径
 */
function cms_plugin_url($id, $path = '')
{
    $path = ltrim(str_replace('\\', '/', (string) $path), '/');
    if (!cms_plugin_id_ok($id)) {
        return '';
    }
    return site_url(CMS_CONTENT_REL . '/plugins/' . $id . ($path !== '' ? '/' . $path : ''));
}

/** 插件自带文件的绝对路径（做了越界校验） */
function cms_plugin_path($id, $path = '')
{
    if (!cms_plugin_id_ok($id)) {
        return '';
    }
    $base = cms_plugins_dir() . '/' . $id;
    $path = ltrim(str_replace('\\', '/', (string) $path), '/');
    if ($path === '') {
        return $base;
    }
    if (strpos($path, '..') !== false) {
        return '';
    }
    return $base . '/' . $path;
}

/**
 * 取插件声明的后台页面
 *
 * @return array id => array('title','sub','file')
 */
function cms_plugin_admin_pages()
{
    $pages = array();
    foreach (cms_plugin_scan() as $id => $meta) {
        if (!empty($meta['admin']['file'])) {
            $file = cms_plugin_path($id, (string) $meta['admin']['file']);
            if ($file !== '' && is_file($file) && cms_plugin_active($id)) {
                $pages[$id] = array(
                    'title' => isset($meta['admin']['title']) ? (string) $meta['admin']['title'] : $meta['name'],
                    'sub'   => isset($meta['admin']['sub']) ? (string) $meta['admin']['sub'] : '',
                    'file'  => $file,
                );
            }
        }
    }
    return $pages;
}

/** 页面收尾：给整页 HTML 插入插件资源（静态生成与动态预览共用） */
function cms_finalize_html($html, $context = 'static')
{
    $html = (string) $html;
    if ($html === '') {
        return $html;
    }

    $head = (string) cms_apply_filters('html_head', '');
    if ($head !== '' && stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $head . "\n</head>", $html, 1);
    }

    $foot = (string) cms_apply_filters('html_foot', '');
    if ($foot !== '' && stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $foot . "\n</body>", $html, 1);
    }

    return (string) cms_apply_filters('html_final', $html, $context);
}
