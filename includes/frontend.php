<?php
/**
 * CiciCMS 前台数据与渲染
 * 这些函数供静态生成器（builder.php）与站内搜索（index.php?s=）共用。
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
        'name'     => setting('site_name', 'CiciCMS'),
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

/* fe_find_post / fe_find_page 已随动态路由（index.php?p= / ?single=）一并移除：
 * 前台只有静态文件与搜索两个入口，按 slug 动态取单条数据的场景不复存在。 */
