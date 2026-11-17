<?php
/**
 * Nebula 后台数据适配层
 *
 * 原型 db 对象是浏览器里的完整状态；CiciCMS 是 7 张 MySQL 表。
 * 这里做双向转换，让界面代码只面对 db，不关心底层表结构。
 *
 * 映射：
 *   db.posts      → article     （tags 逗号串 ↔ 数组，md ↔ content_src + content）
 *   db.pages      → page        （展示开关存在 config.nebula_pages_meta）
 *   db.categories → category    （slug/desc 等已是真列）
 *   db.media      → attachment  （file/path、post/content_id、trashed/deleted_at）
 *   db.settings   → config      （site_name 等键）
 *   db.users      → user
 *   其余（revisions/templates/backups/ftp/seq/…）→ config.nebula_extra（JSON）
 *
 * 不新增表，不动现有前端构建器。
 */

if (!defined('CMS_ROOT')) {
    exit;
}


/** mbstring 未必开启，给一个 UTF-8 安全兜底 */
function nb_substr($s, $start, $len, $enc = 'UTF-8')
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, $start, $len, $enc);
    }
    return substr($s, $start, $len * 4);
}

/** 原型 status ↔ article.status */
function nebula_status_to_db($s)
{
    $map = array('publish' => 1, 'draft' => 0, 'pending' => 2, 'trash' => 3);
    return isset($map[$s]) ? $map[$s] : 0;
}
function nebula_status_from_db($n)
{
    $map = array(1 => 'publish', 0 => 'draft', 2 => 'pending', 3 => 'trash');
    return isset($map[(int) $n]) ? $map[(int) $n] : 'draft';
}

/** '2026/11/08 10:20:00' ↔ Unix 时间戳 */
function nebula_time_to_db($s)
{
    $s = trim((string) $s);
    if ($s === '') {
        return time();
    }
    $s = str_replace('/', '-', $s);
    $t = strtotime($s);
    return $t === false ? time() : $t;
}
function nebula_time_from_db($ts)
{
    $ts = (int) $ts;
    return $ts > 0 ? date('Y/m/d H:i:s', $ts) : '';
}
function nebula_date_from_db($ts)
{
    $ts = (int) $ts;
    return $ts > 0 ? date('Y/m/d', $ts) : '';
}

/** 体积：'486 KB' ↔ 字节 */
function nebula_size_to_db($s)
{
    $s = strtoupper(trim((string) $s));
    if (!preg_match('/^([\d.]+)\s*(B|KB|MB|GB)?$/', $s, $m)) {
        return 0;
    }
    $unit = isset($m[2]) ? $m[2] : 'B';
    $mul  = array('B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824);
    return (int) round((float) $m[1] * (isset($mul[$unit]) ? $mul[$unit] : 1));
}
function nebula_size_from_db($bytes)
{
    $b = (int) $bytes;
    if ($b >= 1073741824) {
        return round($b / 1073741824, 1) . ' GB';
    }
    if ($b >= 1048576) {
        return round($b / 1048576, 1) . ' MB';
    }
    if ($b >= 1024) {
        return round($b / 1024, 0) . ' KB';
    }
    return $b . ' B';
}

/** 从文件名兜一个 slug */
function nebula_slug($name, $fallback = '')
{
    $s = strtolower(trim((string) $name));
    $s = preg_replace('/[^\x{4e00}-\x{9fa5}a-z0-9]+/u', '-', $s);
    $s = trim($s, '-');
    if ($s === '') {
        $s = 'item-' . substr(md5((string) $fallback . microtime(true)), 0, 8);
    }
    return nb_substr($s, 0, 100, 'UTF-8');
}

/** 读 config 里的一个 JSON 块 */
function nebula_extra_read($key)
{
    $raw = setting($key, '');
    if ($raw === '') {
        return array();
    }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : array();
}
function nebula_extra_write($key, $value)
{
    set_setting($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** 标签字符串 ↔ 数组（原型存名字，article.tags 逗号串） */
function nebula_tags_from_db($s)
{
    $s = trim((string) $s);
    if ($s === '') {
        return array();
    }
    $out = array();
    foreach (explode(',', $s) as $t) {
        $t = trim($t);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return array_values(array_unique($out));
}
function nebula_tags_to_db($arr)
{
    if (!is_array($arr)) {
        return '';
    }
    $out = array();
    foreach ($arr as $t) {
        $t = trim((string) $t);
        if ($t !== '' && !in_array($t, $out, true)) {
            $out[] = $t;
        }
    }
    return implode(',', $out);
}

/* ================================================================
 * 拉取完整状态
 * ================================================================ */

function nebula_bootstrap()
{
    $extra   = nebula_extra_read('nebula_extra');
    $pgMeta  = nebula_extra_read('nebula_pages_meta');
    $tagIds  = isset($extra['tagIds']) && is_array($extra['tagIds']) ? $extra['tagIds'] : array();
    $catIds  = isset($extra['catIds']) && is_array($extra['catIds']) ? $extra['catIds'] : array();
    $catMeta = isset($extra['catMeta']) && is_array($extra['catMeta']) ? $extra['catMeta'] : array();
    $mediaMeta = isset($extra['mediaMeta']) && is_array($extra['mediaMeta']) ? $extra['mediaMeta'] : array();

    /* 设置 */
    $settings = array(
        'siteName'    => setting('site_name', 'CiciCMS'),
        'slogan'      => setting('site_slogan', setting('site_description', '')),
        'author'      => setting('site_author', ''),
        'keywords'    => setting('site_keywords', ''),
        'description' => setting('site_description', ''),
        'logoLight'   => setting('logo', ''),
        'logoDark'    => setting('logo', ''),
        'favicon'     => setting('favicon', ''),
    );
    if (isset($extra['settings']) && is_array($extra['settings'])) {
        $settings = array_merge($settings, $extra['settings']);
    }

    /* 用户 */
    $users = array();
    foreach (all('SELECT * FROM {p}user ORDER BY id ASC') as $r) {
        $users[] = array(
            'id'   => (int) $r['id'],
            'name' => isset($r['nickname']) && $r['nickname'] !== '' ? $r['nickname'] : $r['username'],
            'user' => $r['username'],
            'pass' => '',                       // 哈希不下发到浏览器
            'role' => isset($r['role']) ? $r['role'] : 'admin',
        );
    }
    if (!$users) {
        $users[] = array('id' => 1, 'name' => '管理员', 'user' => 'admin', 'pass' => '', 'role' => 'admin');
    }

    /* 栏目 */
    $categories = array();
    foreach (all('SELECT * FROM {p}category ORDER BY sort ASC, id ASC') as $r) {
        $categories[] = $r['name'];
        if (!isset($catIds[$r['name']])) {
            $catIds[$r['name']] = (int) $r['id'];
        }
        $catMeta[$r['name']] = array(
            'catSlugs'   => $r['dirname'],
            'catDescs'   => $r['description'],
            'catKeywords'=> $r['keywords'],
            'catCovers'  => $r['banner'],
        );
    }

    /* 文章 */
    $posts = array();
    foreach (all('SELECT * FROM {p}article ORDER BY id ASC') as $r) {
        $md  = isset($r['content_src']) && $r['content_src'] !== null ? (string) $r['content_src'] : (string) $r['content'];
        $st  = nebula_status_from_db($r['status']);
        $row = array(
            'id'      => (int) $r['id'],
            'title'   => $r['title'],
            'status'  => $st,
            'cat'     => '',                    // 下面用 cat_id 反查
            'tags'    => nebula_tags_from_db($r['tags']),
            'date'    => nebula_date_from_db($r['created_at']),
            'updated' => nebula_date_from_db($r['updated_at'] ? $r['updated_at'] : $r['created_at']),
            'md'      => $md,
            'views'   => (int) $r['views'],
        );
        $row['cat'] = nebula_cat_name((int) $r['cat_id']);
        if ($r['cover'] !== '') {
            $row['cover'] = $r['cover'];
        }
        if ($r['excerpt'] !== '') {
            $row['excerpt'] = $r['excerpt'];
        }
        if ($r['slug'] !== '') {
            $row['slug'] = $r['slug'];
        }
        $posts[] = $row;
    }

    /* 单页 */
    $pages = array();
    foreach (all('SELECT * FROM {p}page ORDER BY id ASC') as $r) {
        $md   = isset($r['content_src']) && $r['content_src'] !== null ? (string) $r['content_src'] : (string) $r['content'];
        $meta = isset($pgMeta[$r['id']]) && is_array($pgMeta[$r['id']]) ? $pgMeta[$r['id']] : array();
        $row  = array(
            'id'        => (int) $r['id'],
            'title'     => $r['title'],
            'slug'      => $r['slug'],
            'template'  => isset($meta['template']) ? $meta['template'] : 'page',
            'status'    => nebula_status_from_db($r['status']),
            'date'      => nebula_time_from_db($r['created_at']),
            'updated'   => nebula_date_from_db($r['updated_at'] ? $r['updated_at'] : $r['created_at']),
            'parent'    => isset($meta['parent']) ? (int) $meta['parent'] : 0,
            'inNav'     => !empty($meta['inNav']),
            'navOrder'  => isset($meta['navOrder']) ? (int) $meta['navOrder'] : 0,
            'visible'   => isset($meta['visible']) ? $meta['visible'] : 'public',
            'hideTitle' => !empty($meta['hideTitle']),
            'wide'      => !empty($meta['wide']),
            'keywords'  => isset($meta['keywords']) ? $meta['keywords'] : '',
            'excerpt'   => isset($meta['excerpt']) ? $meta['excerpt'] : '',
            'md'        => $md,
        );
        $pages[] = $row;
    }

    /* 媒体 */
    $media = array();
    foreach (all('SELECT * FROM {p}attachment ORDER BY id ASC') as $r) {
        $path = (string) $r['path'];
        $mm   = isset($mediaMeta[$r['id']]) && is_array($mediaMeta[$r['id']]) ? $mediaMeta[$r['id']] : array();
        $mime = (string) $r['mime'];
        $row  = array(
            'id'    => (int) $r['id'],
            'file'  => basename($path),
            'type'  => strpos($mime, 'image/') === 0 ? 'image' : (preg_match('#/(zip|rar|7z|gzip|octet-stream)$#', $mime) ? 'zip' : 'file'),
            'dim'   => isset($mm['dim']) ? $mm['dim'] : '',
            'size'  => nebula_size_from_db($r['size']),
            'post'  => (int) $r['content_id'] > 0 ? (int) $r['content_id'] : null,
            'date'  => nebula_date_from_db($r['created_at']),
            'alt'   => (string) $r['alt'],
        );
        if ((int) $r['deleted_at'] > 0) {
            $row['trashed']   = true;
            $row['trashedAt'] = nebula_time_from_db($r['deleted_at']);
        }
        if (!empty($mm['variant'])) {
            $row['variant'] = $mm['variant'];
        }
        if (!empty($mm['c']) && is_array($mm['c'])) {
            $row['c'] = $mm['c'];
        }
        $media[] = $row;
    }

    /* 标签：从当前文章反推，按名字去重 */
    $tagSet = array();
    foreach ($posts as $p) {
        foreach ($p['tags'] as $t) {
            if (!isset($tagSet[$t])) {
                $tagSet[$t] = isset($tagIds[$t]) ? (int) $tagIds[$t] : 0;
            }
        }
    }
    $tags = array();
    foreach ($tagSet as $name => $id) {
        $tags[] = array('id' => $id, 'name' => $name);
    }

    /* seq */
    $seq = isset($extra['seq']) && is_array($extra['seq']) ? $extra['seq'] : array();
    foreach (array('post' => $posts, 'page' => $pages, 'media' => $media) as $k => $rows) {
        $mx = 0;
        foreach ($rows as $r) {
            if ((int) $r['id'] > $mx) {
                $mx = (int) $r['id'];
            }
        }
        if (!isset($seq[$k]) || (int) $seq[$k] <= $mx) {
            $seq[$k] = $mx + 1;
        }
    }

    $db = array(
        'seq'        => $seq,
        'settings'   => $settings,
        'categories' => $categories,
        'posts'      => $posts,
        'pages'      => $pages,
        'media'      => $media,
        'tags'       => $tags,
        'users'      => $users,
    );

    /* 原型其余状态整块带回去，界面照常工作 */
    foreach (array('revisions', 'templates', 'backups', 'ftp', 'catTrash', 'catCovers',
                   'catSlugs', 'catDescs', 'catUrls', 'catKeywords', 'touchedAt',
                   'lastPurge', 'seedVer') as $k) {
        if (array_key_exists($k, $extra)) {
            $db[$k] = $extra[$k];
        }
    }
    /* 真实站点没有 seedVer：必须补一个与前端一致的值，
     * 否则前端 migrate() 会认为这是老缓存，把 27 篇演示文章重新塞进来。 */
    if (!isset($db['seedVer'])) {
        $db['seedVer'] = 5;
    }
    foreach (array('revisions', 'templates', 'backups', 'catTrash') as $k) {
        if (!isset($db[$k]) || !is_array($db[$k])) {
            $db[$k] = array();
        }
    }

    return $db;
}

/** cat_id → 栏目名（缓存一次） */
function nebula_cat_name($id)
{
    static $map = null;
    if ($map === null) {
        $map = array();
        foreach (all('SELECT id, name FROM {p}category') as $r) {
            $map[(int) $r['id']] = $r['name'];
        }
    }
    return isset($map[(int) $id]) ? $map[(int) $id] : '';
}
function nebula_cat_id($name)
{
    static $map = null;
    if ($map === null) {
        $map = array();
        foreach (all('SELECT id, name FROM {p}category') as $r) {
            $map[$r['name']] = (int) $r['id'];
        }
    }
    $name = (string) $name;
    return isset($map[$name]) ? $map[$name] : 0;
}

/* ================================================================
 * 提交完整状态（增量写回）
 * ================================================================ */

function nebula_commit($db)
{
    if (!is_array($db)) {
        throw new InvalidArgumentException('无效的数据包');
    }
    $posts   = isset($db['posts']) && is_array($db['posts']) ? $db['posts'] : array();
    $pages   = isset($db['pages']) && is_array($db['pages']) ? $db['pages'] : array();
    $cats    = isset($db['categories']) && is_array($db['categories']) ? $db['categories'] : array();
    $media   = isset($db['media']) && is_array($db['media']) ? $db['media'] : array();
    $set     = isset($db['settings']) && is_array($db['settings']) ? $db['settings'] : array();

    $now = time();
    $counts = array('posts' => 0, 'pages' => 0, 'categories' => 0, 'media' => 0);

    /* ---- 栏目 ---- */
    $oldCats = array();
    foreach (all('SELECT id, name FROM {p}category') as $r) {
        $oldCats[$r['name']] = (int) $r['id'];
    }
    $catExtra = array();
    $keepCats = array();
    foreach ($cats as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $keepCats[] = $name;
        $id = isset($oldCats[$name]) ? $oldCats[$name] : 0;
        $slug = isset($db['catSlugs'][$name]) && $db['catSlugs'][$name] !== ''
            ? $db['catSlugs'][$name] : nebula_slug($name, 'cat-' . $id);
        $desc = isset($db['catDescs'][$name]) ? $db['catDescs'][$name] : '';
        $kw   = isset($db['catKeywords'][$name]) ? $db['catKeywords'][$name] : '';
        $cover= isset($db['catCovers'][$name]) ? $db['catCovers'][$name] : '';
        $data = array(
            'name' => nb_substr($name, 0, 80, 'UTF-8'),
            'dirname' => nb_substr($slug, 0, 60, 'UTF-8'),
            'description' => nb_substr((string) $desc, 0, 255, 'UTF-8'),
            'keywords' => nb_substr((string) $kw, 0, 255, 'UTF-8'),
            'banner' => nb_substr((string) $cover, 0, 255, 'UTF-8'),
            'sort' => 0,
        );
        if ($id > 0) {
            // dirname 有唯一索引，改名时可能撞车；撞车就保留旧值
            $dupe = one('SELECT id FROM {p}category WHERE dirname = ? AND id <> ?', array($data['dirname'], $id));
            if ($dupe) {
                unset($data['dirname']);
            }
            update_row('category', $data, 'id = ?', array($id));
        } else {
            $dupe = one('SELECT id FROM {p}category WHERE dirname = ?', array($data['dirname']));
            if ($dupe) {
                $data['dirname'] = $data['dirname'] . '-' . substr(md5($name), 0, 4);
            }
            $id = insert_row('category', $data);
        }
        $catExtra[$name] = $id;
        $counts['categories']++;
    }
    // 栏目删除：原型把它放进 catTrash，这里只处理彻底不在列表里的
    foreach ($oldCats as $name => $id) {
        if (!in_array($name, $keepCats, true)) {
            delete_row('category', 'id = ?', array($id));
            // 该栏目下的文章退回未分类
            q('UPDATE {p}article SET cat_id = 0 WHERE cat_id = ?', array($id));
        }
    }

    /* ---- 文章 ---- */
    $oldPosts = array();
    foreach (all('SELECT id FROM {p}article') as $r) {
        $oldPosts[(int) $r['id']] = true;
    }
    $keepPosts = array();
    foreach ($posts as $p) {
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        if ($id < 1) {
            continue;
        }
        $keepPosts[$id] = true;
        $md = isset($p['md']) ? (string) $p['md'] : '';
        $slug = isset($p['slug']) && $p['slug'] !== ''
            ? (string) $p['slug'] : 'post-' . $id;
        $data = array(
            'cat_id'      => nebula_cat_id(isset($p['cat']) ? $p['cat'] : ''),
            'title'       => nb_substr(isset($p['title']) ? (string) $p['title'] : '（无标题）', 0, 200, 'UTF-8'),
            'slug'        => nb_substr($slug, 0, 120, 'UTF-8'),
            'excerpt'     => nb_substr(isset($p['excerpt']) ? (string) $p['excerpt'] : nebula_excerpt($md), 0, 500, 'UTF-8'),
            'content'     => function_exists('render_content') ? render_content($md) : '<p>' . e($md) . '</p>',
            'content_src' => $md,
            'tags'        => nebula_tags_to_db(isset($p['tags']) ? $p['tags'] : array()),
            'cover'       => isset($p['cover']) ? (string) $p['cover'] : '',
            'status'      => nebula_status_to_db(isset($p['status']) ? $p['status'] : 'draft'),
            'views'       => isset($p['views']) ? (int) $p['views'] : 0,
            'created_at'  => nebula_time_to_db(isset($p['date']) ? $p['date'] : ''),
            'updated_at'  => $now,
        );
        // slug 唯一索引：别人占了就加后缀
        $dupe = one('SELECT id FROM {p}article WHERE slug = ? AND id <> ?', array($data['slug'], $id));
        if ($dupe) {
            $data['slug'] = $data['slug'] . '-' . $id;
        }
        if (isset($oldPosts[$id])) {
            update_row('article', $data, 'id = ?', array($id));
        } else {
            $data['id'] = $id;
            insert_row('article', $data);
            $oldPosts[$id] = true;
        }
        $counts['posts']++;
    }
    // 原型删除的文章：直接删行（静态页由调用方按 dirty 队列清理）
    foreach (array_keys($oldPosts) as $id) {
        if (!isset($keepPosts[$id])) {
            delete_row('article', 'id = ?', array($id));
        }
    }

    /* ---- 单页 ---- */
    $pgMeta = nebula_extra_read('nebula_pages_meta');
    $oldPages = array();
    foreach (all('SELECT id FROM {p}page') as $r) {
        $oldPages[(int) $r['id']] = true;
    }
    $keepPages = array();
    foreach ($pages as $pg) {
        $id = isset($pg['id']) ? (int) $pg['id'] : 0;
        if ($id < 1) {
            continue;
        }
        $keepPages[$id] = true;
        $md = isset($pg['md']) ? (string) $pg['md'] : '';
        $slug = isset($pg['slug']) && $pg['slug'] !== '' ? (string) $pg['slug'] : 'page-' . $id;
        $data = array(
            'title'       => nb_substr(isset($pg['title']) ? (string) $pg['title'] : '（无标题）', 0, 200, 'UTF-8'),
            'slug'        => nb_substr($slug, 0, 120, 'UTF-8'),
            'content'     => function_exists('render_content') ? render_content($md) : '<p>' . e($md) . '</p>',
            'content_src' => $md,
            'status'      => nebula_status_to_db(isset($pg['status']) ? $pg['status'] : 'draft'),
            'sort'        => isset($pg['navOrder']) ? (int) $pg['navOrder'] : 0,
            'created_at'  => nebula_time_to_db(isset($pg['date']) ? $pg['date'] : ''),
            'updated_at'  => $now,
        );
        $dupe = one('SELECT id FROM {p}page WHERE slug = ? AND id <> ?', array($data['slug'], $id));
        if ($dupe) {
            $data['slug'] = $data['slug'] . '-' . $id;
        }
        if (isset($oldPages[$id])) {
            update_row('page', $data, 'id = ?', array($id));
        } else {
            $data['id'] = $id;
            insert_row('page', $data);
            $oldPages[$id] = true;
        }
        $pgMeta[$id] = array(
            'template'  => isset($pg['template']) ? $pg['template'] : 'page',
            'parent'    => isset($pg['parent']) ? (int) $pg['parent'] : 0,
            'inNav'     => !empty($pg['inNav']),
            'navOrder'  => isset($pg['navOrder']) ? (int) $pg['navOrder'] : 0,
            'visible'   => isset($pg['visible']) ? $pg['visible'] : 'public',
            'hideTitle' => !empty($pg['hideTitle']),
            'wide'      => !empty($pg['wide']),
            'keywords'  => isset($pg['keywords']) ? $pg['keywords'] : '',
            'excerpt'   => isset($pg['excerpt']) ? $pg['excerpt'] : '',
        );
        $counts['pages']++;
    }
    foreach (array_keys($oldPages) as $id) {
        if (!isset($keepPages[$id])) {
            delete_row('page', 'id = ?', array($id));
            unset($pgMeta[$id]);
        }
    }
    nebula_extra_write('nebula_pages_meta', $pgMeta);

    /* ---- 媒体 ---- */
    $oldMedia = array();
    foreach (all('SELECT id, path FROM {p}attachment') as $r) {
        $oldMedia[(int) $r['id']] = $r['path'];
    }
    $keepMedia = array();
    $mediaMeta = array();
    foreach ($media as $m) {
        $id = isset($m['id']) ? (int) $m['id'] : 0;
        if ($id < 1) {
            continue;
        }
        $keepMedia[$id] = true;
        $file = isset($m['file']) ? (string) $m['file'] : '';
        $path = isset($oldMedia[$id]) ? $oldMedia[$id] : ('content/uploads/' . $file);
        $mime = isset($m['type']) && $m['type'] === 'image' ? 'image/jpeg'
              : (isset($m['type']) && $m['type'] === 'zip' ? 'application/zip' : 'application/octet-stream');
        $data = array(
            'path'       => nb_substr($path, 0, 255, 'UTF-8'),
            'name'       => nb_substr(basename($file), 0, 200, 'UTF-8'),
            'alt'        => nb_substr(isset($m['alt']) ? (string) $m['alt'] : '', 0, 200, 'UTF-8'),
            'mime'       => $mime,
            'size'       => nebula_size_to_db(isset($m['size']) ? $m['size'] : 0),
            'content_id' => isset($m['post']) && $m['post'] !== null ? (int) $m['post'] : 0,
            'deleted_at' => !empty($m['trashed']) ? nebula_time_to_db(isset($m['trashedAt']) ? $m['trashedAt'] : '') : 0,
            'created_at' => nebula_time_to_db(isset($m['date']) ? $m['date'] : ''),
        );
        if (isset($oldMedia[$id])) {
            update_row('attachment', $data, 'id = ?', array($id));
        } else {
            $data['id'] = $id;
            insert_row('attachment', $data);
            $oldMedia[$id] = $path;
        }
        $mm = array();
        foreach (array('dim', 'variant', 'c') as $k) {
            if (array_key_exists($k, $m)) {
                $mm[$k] = $m[$k];
            }
        }
        if ($mm) {
            $mediaMeta[$id] = $mm;
        }
        $counts['media']++;
    }
    foreach (array_keys($oldMedia) as $id) {
        if (!isset($keepMedia[$id])) {
            delete_row('attachment', 'id = ?', array($id));
        }
    }

    /* ---- 设置 ---- */
    if (isset($set['siteName']))    { set_setting('site_name', (string) $set['siteName']); }
    if (isset($set['description'])) { set_setting('site_description', (string) $set['description']); }
    if (isset($set['keywords']))    { set_setting('site_keywords', (string) $set['keywords']); }
    if (isset($set['author']))      { set_setting('site_author', (string) $set['author']); }
    if (isset($set['slogan']))      { set_setting('site_slogan', (string) $set['slogan']); }
    if (isset($set['logoLight']) || isset($set['logoDark'])) {
        $logo = isset($set['logoLight']) ? (string) $set['logoLight'] : '';
        if ($logo === '' && isset($set['logoDark'])) {
            $logo = (string) $set['logoDark'];
        }
        set_setting('logo', $logo);
    }
    if (isset($set['favicon']))     { set_setting('favicon', (string) $set['favicon']); }

    /* ---- 其余状态整块存回 ---- */
    $extra = nebula_extra_read('nebula_extra');
    foreach (array('seq', 'revisions', 'templates', 'backups', 'ftp', 'catTrash',
                   'catCovers', 'catSlugs', 'catDescs', 'catUrls', 'catKeywords',
                   'touchedAt', 'lastPurge', 'seedVer') as $k) {
        if (array_key_exists($k, $db)) {
            $extra[$k] = $db[$k];
        }
    }
    $extra['tagIds'] = array();
    if (!empty($db['tags']) && is_array($db['tags'])) {
        foreach ($db['tags'] as $t) {
            if (isset($t['name'], $t['id']) && (int) $t['id'] > 0) {
                $extra['tagIds'][$t['name']] = (int) $t['id'];
            }
        }
    }
    $extra['catIds']   = $catExtra;
    $extra['catMeta']  = isset($extra['catMeta']) ? $extra['catMeta'] : array();
    $extra['mediaMeta'] = $mediaMeta;
    nebula_extra_write('nebula_extra', $extra);

    return $counts;
}

function nebula_excerpt($md)
{
    $s = preg_replace('/^[#>\s`\-]+/m', '', (string) $md);
    $s = strip_tags($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return nb_substr(trim($s), 0, 150, 'UTF-8');
}
