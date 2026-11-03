<?php
/**
 * HtmlCms 前台数据与渲染
 * 静态生成与动态预览共用同一套渲染函数，保证两者输出一致
 */

function fe_per_page()
{
    $n = (int) setting('per_page', 10);
    if ($n < 1) {
        $n = 10;
    }
    return $n > 100 ? 100 : $n;
}

function fe_site()
{
    return array(
        'name'     => setting('site_name', 'HtmlCms'),
        'desc'     => setting('site_desc', ''),
        'keywords' => setting('site_keywords', ''),
        'url'      => site_url(),
        'icp'      => setting('site_icp', ''),
        'footer'   => setting('site_footer', ''),
        'per_page' => fe_per_page(),
    );
}

function fe_cats()
{
    static $cats = null;
    if ($cats === null) {
        $cats = all('SELECT * FROM {p}category ORDER BY sort ASC, id ASC');
    }
    return $cats;
}

function fe_cat_map()
{
    static $map = null;
    if ($map === null) {
        $map = array();
        foreach (fe_cats() as $c) {
            $map[(int) $c['id']] = $c;
        }
    }
    return $map;
}

function fe_cat_by_dir($dir)
{
    foreach (fe_cats() as $c) {
        if ($c['dirname'] === $dir) {
            return $c;
        }
    }
    return null;
}

function fe_cat_by_id($id)
{
    $map = fe_cat_map();
    return isset($map[(int) $id]) ? $map[(int) $id] : null;
}

function fe_posts($where, $params = array(), $limit = 10, $offset = 0)
{
    $sql = 'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
            FROM {p}article a
            LEFT JOIN {p}category c ON c.id = a.cat_id
            WHERE ' . $where . '
            ORDER BY a.is_top DESC, a.created_at DESC, a.id DESC
            LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    return all($sql, $params);
}

function fe_count($where, $params = array())
{
    return (int) val('SELECT COUNT(*) FROM {p}article a WHERE ' . $where, $params);
}

/** 各分类已发布文章数，供导航/栏目块显示计数 */
function fe_cat_counts()
{
    static $counts = null;
    if ($counts === null) {
        $counts = array();
        foreach (all('SELECT cat_id, COUNT(*) AS n FROM {p}article WHERE status = 1 GROUP BY cat_id') as $r) {
            $counts[(int) $r['cat_id']] = (int) $r['n'];
        }
    }
    return $counts;
}

function fe_tags_all()
{
    $rows = all("SELECT tags FROM {p}article WHERE status = 1 AND tags <> ''");
    $tags = array();
    foreach ($rows as $r) {
        foreach (explode(',', $r['tags']) as $t) {
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            if (!isset($tags[$t])) {
                $tags[$t] = 0;
            }
            $tags[$t]++;
        }
    }
    arsort($tags);
    return $tags;
}

function fe_base_vars()
{
    return array(
        'site'           => fe_site(),
        'cats'           => fe_cats(),
        'title'          => '',
        'desc'           => '',
        'keywords'       => '',
        'canonical'      => '',
        'body_class'     => '',
        'posts'          => array(),
        'post'           => null,
        'cat'            => null,
        'pager'          => null,
        'tags'           => array(),
        'kw'             => '',
        'related'        => array(),
        'current_cat_id' => 0,
    );
}

function fe_pager($page, $pages, $total, $urlFn)
{
    $page  = max(1, (int) $page);
    $pages = max(1, (int) $pages);
    return array(
        'page'  => $page,
        'pages' => $pages,
        'total' => (int) $total,
        'url'   => $urlFn,
        'prev'  => $page > 1 ? $urlFn($page - 1) : null,
        'next'  => $page < $pages ? $urlFn($page + 1) : null,
    );
}

/** 生成翻页页码数组：键为页码，值为 URL；键 0 / -1 为省略号占位 */
function pager_numbers($pager, $span = 2)
{
    $out   = array();
    $pages = (int) $pager['pages'];
    $cur   = (int) $pager['page'];
    if ($pages < 1) {
        return $out;
    }
    $start = max(1, $cur - $span);
    $end   = min($pages, $cur + $span);
    if ($start > 1) {
        $out[1] = call_user_func($pager['url'], 1);
        if ($start > 2) {
            $out[0] = null;
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $out[$i] = call_user_func($pager['url'], $i);
    }
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $out[-1] = null;
        }
        $out[$pages] = call_user_func($pager['url'], $pages);
    }
    return $out;
}

/* ==================== 渲染 ==================== */

function fe_render_home($page = 1)
{
    $per   = fe_per_page();
    $total = fe_count('a.status = 1');
    $pages = max(1, (int) ceil($total / $per));
    $page  = min(max(1, (int) $page), $pages);

    $vars          = fe_base_vars();
    $site          = $vars['site'];
    $vars['posts'] = fe_posts('a.status = 1', array(), $per, ($page - 1) * $per);
    $vars['pager'] = fe_pager($page, $pages, $total, 'u_home');
    $vars['canonical'] = u_home($page);
    $vars['desc']      = $site['desc'];
    $vars['keywords']  = $site['keywords'];
    $vars['body_class'] = 'home';
    $vars['title'] = $page > 1
        ? '第 ' . $page . ' 页 - ' . $site['name']
        : ($site['desc'] !== '' ? $site['name'] . ' - ' . $site['desc'] : $site['name']);
    return render('index', $vars);
}

function fe_render_category($cat, $page = 1)
{
    $per    = fe_per_page();
    $where  = 'a.status = 1 AND a.cat_id = ?';
    $params = array((int) $cat['id']);
    $total  = fe_count($where, $params);
    $pages  = max(1, (int) ceil($total / $per));
    $page   = min(max(1, (int) $page), $pages);

    $vars                   = fe_base_vars();
    // 横幅存的是相对路径，输出前转成可直接放进 style 的地址
    $cat['banner_url']      = !empty($cat['banner']) ? upload_url($cat['banner']) : '';
    $vars['cat']            = $cat;
    $vars['current_cat_id'] = (int) $cat['id'];
    $vars['posts']          = fe_posts($where, $params, $per, ($page - 1) * $per);
    $vars['pager']          = fe_pager($page, $pages, $total, function ($n) use ($cat) {
        return u_cat($cat, $n);
    });
    $vars['canonical'] = u_cat($cat, $page);
    $vars['title']     = $cat['name'] . ($page > 1 ? ' - 第 ' . $page . ' 页' : '') . ' - ' . $vars['site']['name'];
    $vars['desc']      = $cat['description'] !== '' ? $cat['description'] : $vars['site']['desc'];
    $vars['keywords']  = $cat['keywords'] !== '' ? $cat['keywords'] : $vars['site']['keywords'];
    $vars['body_class'] = 'category';
    return render('list', $vars);
}

function fe_render_article($post)
{
    $vars                   = fe_base_vars();
    $vars['post']           = $post;
    $vars['current_cat_id'] = (int) $post['cat_id'];
    $vars['title']          = $post['title'] . ' - ' . $vars['site']['name'];
    $vars['desc']           = $post['excerpt'] !== '' ? $post['excerpt'] : make_excerpt($post['content'], 150);
    $vars['keywords']       = $post['tags'] !== '' ? str_replace(',', '，', $post['tags']) : $vars['site']['keywords'];
    $vars['canonical']      = u_post($post);
    $vars['body_class']     = 'single';
    $vars['related']        = fe_posts(
        'a.status = 1 AND a.cat_id = ? AND a.id <> ?',
        array((int) $post['cat_id'], (int) $post['id']),
        5,
        0
    );
    return render('article', $vars);
}

function fe_render_page($page)
{
    $vars              = fe_base_vars();
    $vars['post']      = $page;
    $vars['title']     = $page['title'] . ' - ' . $vars['site']['name'];
    $vars['desc']      = make_excerpt($page['content'], 150);
    $vars['canonical'] = u_page($page);
    $vars['body_class'] = 'page';
    return render('page', $vars);
}

function fe_render_tag($name, $page = 1)
{
    // 标签页固定单页输出（最多 200 篇），避免分页地址与静态文件不一致
    $per    = 200;
    $where  = 'a.status = 1 AND FIND_IN_SET(?, a.tags)';
    $params = array($name);
    $total  = fe_count($where, $params);

    $vars          = fe_base_vars();
    $vars['posts'] = fe_posts($where, $params, $per, 0);
    $vars['pager'] = fe_pager(1, 1, $total, function ($n) use ($name) {
        return u_tag($name);
    });
    $vars['canonical'] = u_tag($name);
    $vars['title']     = '标签：' . $name . ' - ' . $vars['site']['name'];
    $vars['desc']      = '标签 ' . $name . ' 下的全部文章。';
    $vars['keywords']  = $name;
    $vars['body_class'] = 'tag';
    $vars['tag_name']  = $name;
    return render('list', $vars);
}

function fe_render_tags_index()
{
    $vars              = fe_base_vars();
    $vars['tags']      = fe_tags_all();
    $vars['title']     = '标签云 - ' . $vars['site']['name'];
    $vars['desc']      = '站点全部标签。';
    $vars['canonical'] = site_url('tags.html');
    $vars['body_class'] = 'tags';
    return render('tags', $vars);
}

function fe_render_search($kw, $page = 1)
{
    $per  = fe_per_page();
    $kw   = trim((string) $kw);
    $like = '%' . addcslashes($kw, '%_\\') . '%';

    $where        = 'a.status = 1 AND (a.title LIKE ? OR a.content LIKE ?)';
    $params       = array($like, $like);
    $total        = $kw === '' ? 0 : fe_count($where, $params);
    $pages        = max(1, (int) ceil($total / $per));
    $page         = min(max(1, (int) $page), $pages);
    $vars         = fe_base_vars();
    $vars['kw']   = $kw;
    $vars['posts'] = $kw === '' ? array() : fe_posts($where, $params, $per, ($page - 1) * $per);
    $vars['pager'] = fe_pager($page, $pages, $total, function ($n) use ($kw) {
        return site_url('index.php?s=' . urlencode($kw) . ($n > 1 ? '&page=' . $n : ''));
    });
    $vars['title'] = ($kw !== '' ? '搜索：' . $kw : '搜索') . ' - ' . $vars['site']['name'];
    $vars['desc']  = '站内搜索结果。';
    $vars['body_class'] = 'search';
    $vars['noindex'] = true;
    return render('search', $vars);
}

function fe_render_404()
{
    $vars              = fe_base_vars();
    $vars['title']     = '页面不存在 - ' . $vars['site']['name'];
    $vars['desc']      = '您访问的页面不存在或已被删除。';
    $vars['canonical'] = '';
    $vars['body_class'] = 'error404';
    return render('404', $vars);
}

/** 归档数据：按年 → 月 → 文档分组 */
function fe_archive_groups($limit = 0)
{
    static $cache = null;
    if ($cache !== null && $limit <= 0) {
        return $cache;
    }
    $rows = all('SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
                 FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                 WHERE a.status = 1 ORDER BY a.created_at DESC, a.id DESC');
    $years = array();
    $n     = 0;
    foreach ($rows as $r) {
        if ($limit > 0 && $n >= $limit) {
            break;
        }
        $ts = (int) $r['created_at'];
        $y  = date('Y', $ts > 0 ? $ts : time());
        $m  = date('m', $ts > 0 ? $ts : time());
        if (!isset($years[$y])) {
            $years[$y] = array('year' => $y, 'label' => $y . ' 年', 'count' => 0, 'months' => array());
        }
        if (!isset($years[$y]['months'][$m])) {
            $years[$y]['months'][$m] = array(
                'month' => $m,
                'label' => (int) $m . ' 月',
                'count' => 0,
                'posts' => array(),
            );
        }
        $years[$y]['months'][$m]['posts'][] = cms_row_expand($r);
        $years[$y]['months'][$m]['count']++;
        $years[$y]['count']++;
        $n++;
    }
    foreach ($years as $y => $g) {
        $years[$y]['months'] = array_values($g['months']);
    }
    $out = array_values($years);
    if ($limit <= 0) {
        $cache = $out;
    }
    return $out;
}

function fe_render_archive()
{
    $vars              = fe_base_vars();
    $vars['title']     = '文章归档 - ' . $vars['site']['name'];
    $vars['desc']      = '按时间顺序归档的全部文章。';
    $vars['keywords']  = '归档,文章列表,' . $vars['site']['keywords'];
    $vars['canonical'] = site_url('archive.html');
    $vars['body_class'] = 'archive';
    $vars['archive']   = fe_archive_groups();
    return render('archive', $vars);
}

/** 友链页：数据由模板里的 {cici:flink} 自行调取 */
function fe_render_links()
{
    $vars              = fe_base_vars();
    $vars['title']     = '友情链接 - ' . $vars['site']['name'];
    $vars['desc']      = '本站的友情链接与合作伙伴。';
    $vars['canonical'] = site_url('links.html');
    $vars['body_class'] = 'links';
    return render('links', $vars);
}

/* ==================== 单条查询 ==================== */

function fe_find_post($slug)
{
    return one(
        'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
         FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
         WHERE a.slug = ? AND a.status = 1 LIMIT 1',
        array($slug)
    );
}

function fe_find_page($slug)
{
    return one('SELECT * FROM {p}page WHERE slug = ? AND status = 1 LIMIT 1', array($slug));
}

/* ------------------------------------------------------------------ *
 * 老地址兼容：改版后老链接一律 301 到新地址，不留下 404
 * ------------------------------------------------------------------ */

/**
 * 分类目录改名链：old => new，改名时写进 setting('cat_dir_map')
 * 支持 A→B→C 的连续改名，最多跟 5 跳，防环形。
 */
function cms_cat_dir_alias($dir)
{
    $map = json_decode((string) setting('cat_dir_map', ''), true);
    if (!is_array($map)) {
        return $dir;
    }
    for ($i = 0; $i < 5 && isset($map[$dir]); $i++) {
        $dir = (string) $map[$dir];
    }
    return $dir;
}

/**
 * 把请求路径解析成"应该跳去哪"，解析不出返回 ''（交给 404）。
 *
 * 覆盖三种历史地址：
 *   /post/<slug>.html                      改版前的文章地址
 *   /cat/<目录>/index.html、/cat/<目录>/page/N/index.html   改版前的分类地址
 *   /<旧目录>/<id>.html                    分类改过名之后的文章地址
 *
 * @param string $path 不带首部斜杠的请求路径
 * @return string 301 目标绝对地址，或 ''
 */
function legacy_redirect_target($path)
{
    $path = trim((string) $path, '/');
    if ($path === '') {
        return '';
    }

    // 1) 老文章地址 /post/<slug>.html
    if (preg_match('#^post/([^/]+)\.html$#i', $path, $m)) {
        $post = fe_find_post(rawurldecode($m[1]));
        return $post ? (string) post_static_url($post) : '';
    }

    // 2) 老分类地址 /cat/<dir>[/page/N]/index.html
    if (preg_match('#^cat/([^/]+)/page/([0-9]+)/index\.html$#i', $path, $m)) {
        $cat = fe_cat_by_dir(cms_cat_dir_alias(rawurldecode($m[1])));
        return $cat ? cat_static_url($cat, (int) $m[2]) : '';
    }
    if (preg_match('#^cat/([^/]+)/(?:index\.html)?$#i', $path, $m)) {
        $cat = fe_cat_by_dir(cms_cat_dir_alias(rawurldecode($m[1])));
        return $cat ? cat_static_url($cat, 1) : '';
    }

    // 3) 分类改过名：/<旧目录>[/page/N]/index.html → 按改名链找到现在的目录
    if (preg_match('#^([^/]+)/page/([0-9]+)/index\.html$#', $path, $m)) {
        $cat = fe_cat_by_dir(cms_cat_dir_alias($m[1]));
        return ($cat && $cat['dirname'] !== $m[1]) ? cat_static_url($cat, (int) $m[2]) : '';
    }
    if (preg_match('#^([^/]+)/index\.html$#', $path, $m)) {
        $cat = fe_cat_by_dir(cms_cat_dir_alias($m[1]));
        return ($cat && $cat['dirname'] !== $m[1]) ? cat_static_url($cat, 1) : '';
    }

    // 4) 分类改过名之后的文章地址 /<旧目录>/<id>.html（按文章 ID 找到现地址）
    if (preg_match('#^([^/]+)/([0-9]+)\.html$#', $path, $m)) {
        $oldDir = $m[1];
        $row    = one('SELECT a.id, a.slug, c.dirname AS cat_dir
                       FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                       WHERE a.id = ? AND a.status = 1 LIMIT 1', array((int) $m[2]));
        if ($row && cat_dir_of($row['cat_dir']) !== $oldDir) {
            return (string) post_static_url($row);
        }
        // 目录没变却没生成：可能只是还没生成静态页，不再跳，避免自我循环
        return '';
    }

    // 5) 栏目地址不带 index.html、只带尾斜杠：/旧目录/、/旧目录/page/N/
    //    真实服务器上访客更常这样访问（复制地址栏、外链），与 4) 的规则互补
    if (preg_match('#^([^/]+)/page/([0-9]+)$#', $path, $m)) {
        $cat = fe_cat_by_dir(cms_cat_dir_alias($m[1]));
        return ($cat && $cat['dirname'] !== $m[1]) ? cat_static_url($cat, (int) $m[2]) : '';
    }
    if (preg_match('#^([^/]+)$#', $path, $m)) {
        $cat = fe_cat_by_dir(cms_cat_dir_alias($m[1]));
        return ($cat && $cat['dirname'] !== $m[1]) ? cat_static_url($cat, 1) : '';
    }

    return '';
}
