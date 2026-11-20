<?php
/**
 * Hexo 导入 · 后台页面
 *
 * 三步走：上传 → 预览 → 确认入库。
 * 解析结果先落到 content/data/tmp/ 下的临时文件，确认后才写数据库，
 * 避免"点了上传就进库、结果没法反悔"。
 */

if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/remote.php';

/* ============ 工具 ============ */

function hexo_tmp_dir()
{
    $dir = CMS_DATA . '/tmp';
    ensure_dir($dir);
    return $dir;
}

function hexo_tmp_file($token)
{
    if (!preg_match('/^[a-f0-9]{16}$/', (string) $token)) {
        return '';
    }
    return hexo_tmp_dir() . '/hexo-' . $token . '.json';
}

/** 清理两小时前的临时文件，避免残留一堆半成品 */
function hexo_tmp_gc()
{
    foreach ((array) glob(hexo_tmp_dir() . '/hexo-*.json') as $f) {
        if (is_file($f) && filemtime($f) < time() - 7200) {
            @unlink($f);
        }
    }
}

/** 从文件名猜 slug：去掉 2024-03-18- 这类日期前缀 */
function hexo_slug_from_name($name)
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    $base = preg_replace('/^\d{4}[-_]\d{2}[-_]\d{2}[-_]/', '', $base);
    $slug = make_slug($base);
    return $slug !== '' ? $slug : '';
}

/**
 * 按分类名取 id，不存在就自动建一个栏目（逐篇导入用）
 *
 * dirname 必须唯一：中文名 make_slug 会变成空串，所以留空时用 md5 兜底。
 * @return int 分类 id，失败返回 0
 */
function hexo_ensure_category($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return 0;
    }
    $key = mb_strtolower($name, 'UTF-8');
    $hit = one('SELECT id FROM {p}category WHERE LOWER(name) = ? LIMIT 1', array($name));
    if (!$hit) {
        $hit = one('SELECT id FROM {p}category WHERE LOWER(dirname) = ? LIMIT 1', array($key));
    }
    if ($hit) {
        return (int) $hit['id'];
    }
    $dir = make_slug($name);
    if ($dir === '') {
        $dir = 'cat-' . substr(md5($name), 0, 8);
    }
    $base = $dir;
    $n = 2;
    while (one('SELECT id FROM {p}category WHERE dirname = ?', array($dir))) {
        $dir = $base . '-' . $n;
        $n++;
    }
    try {
        return (int) insert_row('category', array(
            'name'        => str_limit($name, 80),
            'dirname'     => str_limit($dir, 60),
            'parent_id'   => 0,
            'sort'        => 0,
            'description' => '',
            'keywords'    => '',
            'banner'      => '',
        ));
    } catch (Throwable $ex) {
        log_msg('hexo auto category failed: ' . $ex->getMessage() . ' | ' . $name);
        return 0;
    }
}

/** 把上传的文件读成 md 文本列表 */
function hexo_read_uploads(&$errors)
{
    $out = array();
    if (empty($_FILES['files']) || !isset($_FILES['files']['name'])) {
        $errors[] = '没有收到文件';
        return $out;
    }

    $names = (array) $_FILES['files']['name'];
    $tmps  = (array) $_FILES['files']['tmp_name'];
    $errs  = (array) $_FILES['files']['error'];
    $sizes = (array) $_FILES['files']['size'];

    $allow = array('md', 'markdown', 'txt', 'zip');
    $total = 0;

    foreach ($names as $i => $name) {
        $err = isset($errs[$i]) ? (int) $errs[$i] : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            if ($err !== UPLOAD_ERR_NO_FILE) {
                $errors[] = $name . '：上传失败（错误码 ' . $err . '）';
            }
            continue;
        }

        $size = isset($sizes[$i]) ? (int) $sizes[$i] : 0;
        $total += $size;
        if ($size > 4 * 1024 * 1024) {
            $errors[] = $name . '：单文件超过 4MB，已跳过';
            continue;
        }
        if ($total > 40 * 1024 * 1024) {
            $errors[] = '本次上传总量超过 40MB，后面的文件已跳过';
            break;
        }

        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $tmp  = isset($tmps[$i]) ? (string) $tmps[$i] : '';
        if (!in_array($ext, $allow, true) || $tmp === '' || !is_uploaded_file($tmp)) {
            $errors[] = $name . '：不支持的文件类型';
            continue;
        }

        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                $errors[] = $name . '：服务器未开启 ZipArchive 扩展，请改成逐个上传 .md';
                continue;
            }
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                $errors[] = $name . '：压缩包无法打开';
                continue;
            }
            for ($k = 0; $k < $zip->numFiles; $k++) {
                $entry = $zip->getNameIndex($k);
                $eExt  = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($eExt, array('md', 'markdown'), true)) {
                    continue;
                }
                if (strpos($entry, '__MACOSX') !== false) {
                    continue;
                }
                $body = $zip->getFromIndex($k);
                if ($body !== false && trim($body) !== '') {
                    $out[] = array('name' => basename($entry), 'body' => $body);
                }
            }
            $zip->close();
            continue;
        }

        $body = (string) file_get_contents($tmp);
        if (trim($body) === '') {
            $errors[] = $name . '：内容是空的';
            continue;
        }
        $out[] = array('name' => $name, 'body' => $body);
    }

    return $out;
}

/**
 * 单篇 md → 待入库数组
 *
 * @param string $name 文件名
 * @param string $body md 原文
 * @param string $base 图片地址前缀，用来把相对地址补成可下载的绝对地址
 */
function hexo_parse_one($name, $body, $base = '')
{
    list($meta, $md) = hexo_parse_front_matter($body);
    $conv = hexo_md_to_html($md);
    $html = $conv['html'];

    // 代码块整体挖出来再净化：否则教程里演示的 onclick、javascript: 会被清掉
    $blocks = array();
    $masked = code_blocks_mask($html, $blocks);
    $html   = clean_html($masked);
    if ($blocks) {
        $html = strtr($html, $blocks);
    }

    $title = isset($meta['title']) && is_string($meta['title']) ? trim($meta['title']) : '';
    if ($title === '') {
        $title = pathinfo($name, PATHINFO_FILENAME);
    }

    $cats = array();
    if (isset($meta['categories'])) {
        $cats = is_array($meta['categories']) ? $meta['categories'] : array((string) $meta['categories']);
    } elseif (isset($meta['category'])) {
        $cats = array((string) $meta['category']);
    }

    $tags = array();
    if (isset($meta['tags'])) {
        $tags = is_array($meta['tags']) ? $meta['tags'] : array((string) $meta['tags']);
    } elseif (isset($meta['tag'])) {
        $tags = array((string) $meta['tag']);
    }

    $cover = '';
    foreach (array('cover', 'banner', 'thumbnail') as $k) {
        if (!empty($meta[$k]) && is_string($meta[$k])) {
            $cover = $meta[$k];
            break;
        }
    }
    if ($cover === '' && !empty($meta['photos']) && is_array($meta['photos'])) {
        $cover = (string) reset($meta['photos']);
    }

    $ts = time();
    if (!empty($meta['date']) && is_string($meta['date'])) {
        $t = strtotime($meta['date']);
        if ($t !== false && $t > 0) {
            $ts = $t;
        }
    }

    $slug = '';
    if (!empty($meta['slug']) && is_string($meta['slug'])) {
        $slug = make_slug($meta['slug']);
    }
    // Hexo 的 abbrlink 是稳定的永久链接别名，天然适合当 slug（比中文文件名干净）
    if ($slug === '' && !empty($meta['abbrlink']) && is_string($meta['abbrlink'])) {
        $slug = make_slug($meta['abbrlink']);
    }
    if ($slug === '') {
        $slug = hexo_slug_from_name($name);
    }

    $excerpt = '';
    foreach (array('excerpt', 'description', 'summary') as $k) {
        if (!empty($meta[$k]) && is_string($meta[$k])) {
            $excerpt = str_limit(trim(strip_tags($meta[$k])), 200);
            break;
        }
    }
    if ($excerpt === '') {
        $excerpt = str_limit(content_plain($html), 200);
    }

    $draft = !empty($meta['draft']) && !in_array((string) $meta['draft'], array('false', '0'), true);

    // 图片：先按老逻辑收集（给原批量流程用，不破坏）
    $imgs = array();
    foreach (hexo_img_srcs($html) as $src) {
        $imgs[] = array('src' => $src, 'url' => hexo_img_url($src, $base));
    }
    // 逐篇工作台：所有需要"对应替换路径"的旧资源（图片 + 附件）
    $assets = hexo_asset_refs($html);

    return array(
        'file'    => $name,
        'title'   => str_limit($title, 150),
        'slug'    => $slug,
        'ts'      => $ts,
        'cat'     => isset($cats[0]) ? trim((string) $cats[0]) : '',
        'tags'    => implode(',', array_filter(array_map('trim', $tags))),
        'cover'     => str_limit($cover, 255),
        'abbrlink'  => isset($meta['abbrlink']) ? str_limit(trim((string) $meta['abbrlink']), 60) : '',
        'excerpt'   => $excerpt,
        'html'      => $html,
        'imgs'      => $imgs,
        'assets'    => $assets,
        'draft'     => $draft ? 1 : 0,
        'chars'     => mb_strlen(content_plain($html), 'UTF-8'),
        // 逐篇工作台的编辑态：封面 + 每个资源槽位的上传结果，按 slot 索引
        'cover_local' => '',
        'asset_local' => array(),
        'imported'    => 0,
    );
}

/* ============ 逐篇工作台：读取/保存临时数据 ============ */

/** 读当前 token 的解析结果 */
function hexo_wb_load($token)
{
    $file = hexo_tmp_file($token);
    if ($file === '' || !is_file($file)) {
        return array('file' => '', 'data' => null);
    }
    $data = json_decode((string) file_get_contents($file), true);
    return array('file' => $file, 'data' => is_array($data) ? $data : null);
}

/** 写回解析结果（封面/配图的上传结果按 slot 记在这里） */
function hexo_wb_save($file, $data)
{
    return write_file_atomic($file, json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * 插件页地址：后台入口为单入口路由，p=插件ID 即可直接打开
 */
function hexo_import_link($extra = array())
{
    return admin_url('hexo-import', (array) $extra);
}

/** 工作台跳转地址，focus 用来滚动回刚才那一篇 */
function hexo_wb_url($token, $idx = -1)
{
    $extra = array('token' => $token);
    if ($idx >= 0) {
        $extra['focus'] = $idx;
    }
    return hexo_import_link($extra);
}

/* ============ 提交处理 ============ */

hexo_tmp_gc();

/* 逐篇工作台 · 上传单张正文配图：按 slot 对位，导入时替换对应的旧引用 */
if (is_post() && post_str('do') === 'wb_img') {
    csrf_verify();
    $token = post_str('token');
    $w = hexo_wb_load($token);
    $idx  = (int) post_str('idx', '-1');
    $slot = (int) post_str('slot', '-1');
    if (!$w['data'] || !isset($w['data']['items'][$idx]) || $slot < 0) {
        flash('工作台数据已过期，请重新上传', 'err');
        redirect(hexo_import_link());
    }
    $assets = isset($w['data']['items'][$idx]['assets']) ? $w['data']['items'][$idx]['assets'] : array();
    $kind = isset($assets[$slot]['kind']) ? $assets[$slot]['kind'] : 'image';
    $r = hexo_img_from_upload(isset($_FILES['file']) ? $_FILES['file'] : null, $err, $kind);
    if ($r) {
        $w['data']['items'][$idx]['asset_local'][(string) $slot] = $r;
        hexo_wb_save($w['file'], $w['data']);
        flash('第 ' . ($idx + 1) . ' 篇 · 第 ' . ($slot + 1) . ' 个' . ($kind === 'file' ? '附件' : '配图') . '已上传', 'ok');
    } else {
        flash('上传失败：' . ($err !== '' ? $err : '未知原因'), 'err');
    }
    redirect(hexo_wb_url($token, $idx));
}

/* 逐篇工作台 · 上传封面 */
if (is_post() && post_str('do') === 'wb_cover') {
    csrf_verify();
    $token = post_str('token');
    $w = hexo_wb_load($token);
    $idx = (int) post_str('idx', '-1');
    if (!$w['data'] || !isset($w['data']['items'][$idx])) {
        flash('工作台数据已过期，请重新上传', 'err');
        redirect(hexo_import_link());
    }
    $r = hexo_img_from_upload(isset($_FILES['file']) ? $_FILES['file'] : null, $err);
    if ($r) {
        $w['data']['items'][$idx]['cover_local'] = $r;
        hexo_wb_save($w['file'], $w['data']);
        flash('第 ' . ($idx + 1) . ' 篇 · 封面已上传', 'ok');
    } else {
        flash('封面上传失败：' . ($err !== '' ? $err : '未知原因'), 'err');
    }
    redirect(hexo_wb_url($token, $idx));
}

/* 逐篇工作台 · 只导入这一篇 */
if (is_post() && post_str('do') === 'wb_import') {
    csrf_verify();
    $token = post_str('token');
    $w = hexo_wb_load($token);
    $idx = (int) post_str('idx', '-1');
    if (!$w['data'] || !isset($w['data']['items'][$idx])) {
        flash('工作台数据已过期，请重新上传', 'err');
        redirect(hexo_import_link());
    }
    $it = $w['data']['items'][$idx];
    $title = (string) $it['title'];

    // 同名 protection
    $skipDup = post_str('skip_dup', '1') === '1';
    if ($skipDup && one('SELECT id FROM {p}article WHERE title = ? LIMIT 1', array($title))) {
        flash('已存在同名文章「' . $title . '」，未重复导入', 'err');
        redirect(hexo_wb_url($token, $idx));
    }

    // 正文：把已上传的资源按 slot 对位替换旧路径，没上传的保留原样
    $html = (string) $it['html'];
    $assets = isset($it['assets']) && is_array($it['assets']) ? $it['assets'] : array();
    $local  = isset($it['asset_local']) && is_array($it['asset_local']) ? $it['asset_local'] : array();
    $replaced = 0;
    foreach ($local as $slot => $loc) {
        if (!isset($assets[(int) $slot]) || empty($loc['url'])) {
            continue;
        }
        $old = (string) $assets[(int) $slot]['src'];
        if ($old !== '') {
            $html = hexo_img_replace($html, $old, (string) $loc['url']);
            $replaced++;
        }
    }

    // 封面：优先用上传的，没传就用 front-matter 里的原值
    $cover = '';
    if (!empty($it['cover_local']['url'])) {
        $cover = (string) $it['cover_local']['url'];
    } elseif (!empty($it['cover'])) {
        $cover = (string) $it['cover'];
    }

    // 分类：工作台输入优先，其次 front-matter，都为空则落到未分类
    $catName = trim(post_str('cat', ''));
    if ($catName === '' && !empty($it['cat'])) {
        $catName = (string) $it['cat'];
    }
    $cid = hexo_ensure_category($catName);

    // slug：工作台可改，默认解析值
    $slug = trim(post_str('slug', ''));
    if ($slug === '') {
        $slug = (string) $it['slug'];
    }
    $slug = $slug !== '' ? unique_slug('article', $slug, 0)
                        : 'hexo' . substr(md5($title . microtime()), 0, 8);

    $flags = array_map('strval', (array) (isset($_POST['flag']) ? $_POST['flag'] : array()));
    $flag  = implode(',', array_slice(array_filter($flags), 0, 8));
    $user  = auth_user();

    $row = array(
        'cat_id'      => $cid,
        'title'       => $title,
        'excerpt'     => str_limit((string) $it['excerpt'], 200),
        'content'     => $html,
        'content_src' => $html,
        'cover'       => str_limit($cover, 255),
        'tags'        => (string) $it['tags'],
        'flag'        => str_limit($flag, 60),
        'status'      => (int) $it['draft'] === 1 ? 0 : 1,
        'is_top'      => post_str('is_top', '0') === '1' ? 1 : 0,
        'views'       => 0,
        'author_id'   => (int) $user['id'],
        'created_at'  => (int) $it['ts'],
        'updated_at'  => time(),
        'slug'        => $slug,
    );

    try {
        $newId = insert_row('article', $row);
        $w['data']['items'][$idx]['imported'] = 1;
        $w['data']['items'][$idx]['article_id'] = (int) $newId;
        hexo_wb_save($w['file'], $w['data']);
        CMS_do_action('article_saved', $newId, $row, true);
        flash('已导入「' . $title . '」：配图替换 ' . $replaced . ' 张，封面'
              . ($cover !== '' ? '已设置' : '未设置') . '。可继续下一篇。', 'ok');
    } catch (Throwable $ex) {
        log_msg('hexo wb import failed: ' . $ex->getMessage() . ' | ' . $title);
        flash('导入失败：' . $ex->getMessage(), 'err');
    }
    redirect(hexo_wb_url($token, $idx));
}

if (is_post() && post_str('do') === 'parse') {
    csrf_verify();
    $errors = array();
    $files  = hexo_read_uploads($errors);
    $base   = hexo_img_base(post_str('img_base'));

    if (!$files) {
        flash($errors ? implode('；', array_slice($errors, 0, 3)) : '没有可导入的文件', 'err');
        redirect(hexo_import_link());
    }

    $items = array();
    foreach ($files as $f) {
        $items[] = hexo_parse_one($f['name'], $f['body'], $base);
    }

    $imgMiss = 0;
    foreach ($items as $it) {
        foreach ($it['imgs'] as $im) {
            if ($im['url'] === '') {
                $imgMiss++;
            }
        }
    }

    $token = bin2hex(random_bytes(8));
    $json  = json_encode(array('created' => time(), 'base' => $base, 'items' => $items), JSON_UNESCAPED_UNICODE);
    if (!write_file_atomic(hexo_tmp_file($token), $json)) {
        flash('临时文件写入失败，请检查 content/data/tmp 目录权限', 'err');
        redirect(hexo_import_link());
    }

    if ($imgMiss > 0) {
        flash('有 ' . $imgMiss . ' 张图片还是相对地址，请在下一步填写原站地址，否则这些图导入后仍指向旧站。', 'err');
    }

    if ($errors) {
        flash('已解析 ' . count($items) . ' 篇；' . count($errors) . ' 个文件被跳过：' . implode('；', array_slice($errors, 0, 2)), 'err');
    }
    redirect(hexo_import_link(array('token' => $token)));
}

if (is_post() && post_str('do') === 'import') {
    csrf_verify();
    $token = post_str('token');
    $file  = hexo_tmp_file($token);
    $data  = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    if (!is_array($data) || empty($data['items'])) {
        flash('导入数据已过期，请重新上传', 'err');
        redirect(hexo_import_link());
    }

    $pick  = array_map('intval', (array) (isset($_POST['pick']) ? $_POST['pick'] : array()));
    $catId = (int) post_str('cat_id', '0');
    $skip  = post_str('skip_dup', '0') === '1';
    $user  = auth_user();

    // 图片地址前缀：以确认页提交的为准（输入框已经带上了解析时的值，清空就表示不下载）
    $base = isset($_POST['img_base'])
        ? hexo_img_base(post_str('img_base'))
        : (isset($data['base']) ? (string) $data['base'] : '');
    $imgCache = array();
    $imgOk    = 0;
    $imgFail  = array();

    if ($catId < 1 || !one('SELECT id FROM {p}category WHERE id = ?', array($catId))) {
        flash('请选择有效的目标栏目', 'err');
        redirect(hexo_import_link(array('token' => $token)));
    }

    // front-matter 里写了分类名且库里存在，就用它，否则落到用户选的目标栏目
    $catMap = array();
    foreach (all('SELECT id, name FROM {p}category') as $c) {
        $catMap[mb_strtolower(trim($c['name']), 'UTF-8')] = (int) $c['id'];
    }

    $ok = 0;
    $dup = 0;
    foreach ($data['items'] as $i => $it) {
        if (!in_array($i, $pick, true)) {
            continue;
        }

        $title = (string) $it['title'];
        if ($skip) {
            $exists = one('SELECT id FROM {p}article WHERE title = ? LIMIT 1', array($title));
            if ($exists) {
                $dup++;
                continue;
            }
        }

        $cid = $catId;
        $key = mb_strtolower(trim((string) $it['cat']), 'UTF-8');
        if ($key !== '' && isset($catMap[$key])) {
            $cid = $catMap[$key];
        }

        $slug = (string) $it['slug'];
        $slug = $slug !== '' ? unique_slug('article', $slug, 0) : 'hexo' . substr(md5($title . microtime()), 0, 8);

        // 图片先拉到本地，再把正文里的地址换掉；单张失败只记下来，不拦整篇入库
        $html = (string) $it['html'];
        $imgs = isset($it['imgs']) && is_array($it['imgs']) ? $it['imgs'] : array();
        $imgDone = 0;
        foreach ($imgs as $im) {
            $src = (string) $im['src'];
            if (strpos($src, CMS_CONTENT_REL . '/uploads/') !== false) {
                continue; // 本来就是本站附件，不用再下一遍
            }
            if ($imgDone >= 60) {
                $imgFail[] = $title . '：单篇图片超过 60 张，其余未下载';
                break;
            }
            $imgDone++;
            // 一律按提交上来的前缀重新解析，避免改了地址还用旧值
            $url = hexo_img_url($src, $base);
            if ($url === '') {
                $imgFail[] = $title . '：' . $src . '（缺少完整地址）';
                continue;
            }
            $saved = hexo_img_localize($url, $imgCache, $err);
            if (!$saved) {
                $imgFail[] = $title . '：' . $src . '（' . $err . '）';
                continue;
            }
            $html = hexo_img_replace($html, $src, $saved['url']);
            $imgOk++;
        }

        $row = array(
            'cat_id'      => $cid,
            'title'       => $title,
            'excerpt'     => str_limit((string) $it['excerpt'], 200),
            'content'     => $html,
            'content_src' => $html,
            'cover'       => (string) $it['cover'],
            'tags'        => (string) $it['tags'],
            'flag'        => '',
            'status'      => (int) $it['draft'] === 1 ? 0 : 1,
            'is_top'      => 0,
            'views'       => 0,
            'author_id'   => (int) $user['id'],
            'created_at'  => (int) $it['ts'],
            'updated_at'  => time(),
            'slug'        => $slug,
        );

        try {
            $id = insert_row('article', $row);
            $ok++;
            CMS_do_action('article_saved', $id, $row, true);
        } catch (Throwable $ex) {
            log_msg('hexo import failed: ' . $ex->getMessage() . ' | ' . $title);
        }
    }

    @unlink($file);

    $msg = '导入完成：成功 ' . $ok . ' 篇';
    if ($dup > 0) {
        $msg .= '，跳过同名 ' . $dup . ' 篇';
    }
    if ($imgOk > 0) {
        $msg .= '，图片已本地化 ' . $imgOk . ' 张';
    }
    if ($imgFail) {
        $msg .= '，' . count($imgFail) . ' 张失败（保留原地址）：' . implode('；', array_slice($imgFail, 0, 2));
    }
    $msg .= '。记得重新生成静态页。';
    flash($msg, $imgFail ? 'err' : 'ok');
    redirect(hexo_import_link());
}

/* ============ 展示 ============ */

$cats   = all('SELECT id, name FROM {p}category ORDER BY id');
$token  = get_str('token');
$file   = hexo_tmp_file($token);
$preview = ($file !== '' && is_file($file)) ? json_decode((string) file_get_contents($file), true) : null;
$items   = (is_array($preview) && !empty($preview['items'])) ? $preview['items'] : array();
$base    = isset($preview['base']) ? (string) $preview['base'] : '';

/* 提前算好每篇的资源数/已上传数/已导入状态，模板里不要再套循环 */
$doneCount = 0;
foreach ($items as $k => $it) {
    $assets = !empty($it['assets']) && is_array($it['assets']) ? $it['assets'] : array();
    $local  = !empty($it['asset_local']) && is_array($it['asset_local']) ? $it['asset_local'] : array();
    $up = 0;
    foreach ($assets as $s => $a) {
        if (!empty($local[$s]['url'])) {
            $up++;
        }
    }
    $items[$k]['_asset_n'] = count($assets);
    $items[$k]['_asset_up'] = $up;
    if (!empty($it['imported'])) {
        $doneCount++;
    }
}
$focusIdx = get_int('focus', -1);
?>

<?php if (!$items): ?>
    <div class="card">
        <div class="card-head"><h2>上传 Markdown</h2></div>
        <div class="card-body">
            <p class="hint" style="margin-top:0;line-height:1.9">
                支持 Hexo 的 <code>source/_posts</code> 目录：可以多选 <code>.md</code> 文件，也可以直接上传整个 <code>_posts.zip</code>。
                解析基于 front-matter，没有头部的文件会退化为「文件名当标题、当前时间」。
            </p>
            <form method="post" action="<?php echo e(hexo_import_link()); ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="parse">
                <div class="form-row">
                    <label for="files">选择文件</label>
                    <input class="input" type="file" id="files" name="files[]" multiple accept=".md,.markdown,.txt,.zip">
                    <div class="hint">单文件不超过 4MB，一次合计不超过 40MB。上传后先预览，不会直接入库。</div>
                </div>
                <div class="hint" style="background:var(--color-background-info);padding:10px 12px;border-radius:8px;line-height:1.8;margin:0 0 14px">
                    解析后进入逐篇工作台：每篇文章单独处理，配图/附件一张张上传、封面单独上传，
                    程序按文件名对位替换正文里的旧路径。不需要原站地址，也不需要联网下载。
                </div>
                <button class="btn btn-primary" type="submit">解析并进入工作台</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>字段映射</h2></div>
        <div class="card-body tight">
            <table class="table">
                <tr><th style="width:180px">Hexo front-matter</th><th>导入到</th></tr>
                <tr><td><code>title</code></td><td>文章标题（缺失时用文件名）</td></tr>
                <tr><td><code>date</code></td><td>发布时间</td></tr>
                <tr><td><code>tags</code> / <code>tag</code></td><td>标签，逗号分隔</td></tr>
                <tr><td><code>categories</code> / <code>category</code></td><td>分类：工作台里可改，站点没有会自动新建栏目</td></tr>
                <tr><td><code>cover</code> / <code>photos</code></td><td>封面：在工作台里单独上传，写入 cover 字段</td></tr>
                <tr><td><code>excerpt</code> / <code>description</code></td><td>摘要（缺失时自动截取正文）</td></tr>
                <tr><td><code>abbrlink</code> / <code>slug</code></td><td>URL 别名（abbrlink 优先，工作台可改）</td></tr>
                <tr><td><code>draft: true</code></td><td>导入为草稿</td></tr>
            </table>
        </div>
        <div class="card-foot hint" style="line-height:1.9">
            正文按 Markdown 转成 HTML 后入库；围栏代码块会沿用站内的深色面板，装好「代码高亮」插件即有配色。<br>
            正文里的图片/附件不会联网下载：在工作台里对着文件名逐张上传本站文件，程序按路径对位把正文里的旧地址换成新地址。
            图片落到 <code>content/uploads/images/</code>，附件落到 <code>content/uploads/files/</code>，都自动登记到媒体库。<br>
            Hexo 标签插件（<code>{% codeblock %}</code> 之类）会被忽略。
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card-head">
            <h2>逐篇导入工作台</h2>
            <span class="badge badge-blue">共 <?php echo count($items); ?> 篇 · 已导入 <?php echo (int) $doneCount; ?> 篇</span>
        </div>
        <div class="card-body">
            <p class="hint" style="margin-top:0;line-height:1.9">
                一次只处理一篇：先逐张上传这张文章的配图/附件（程序会按文件名对位替换正文里的旧路径），
                再传封面，最后点「导入这一篇」。每次请求只传一个文件，虚拟主机也能稳稳跑完。
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a class="btn" href="<?php echo e(hexo_import_link()); ?>">重新上传 md</a>
            </div>
        </div>
    </div>

    <?php foreach ($items as $i => $it): ?>
        <?php
            $isDone = !empty($it['imported']);
            $assets = !empty($it['assets']) && is_array($it['assets']) ? $it['assets'] : array();
            $local  = !empty($it['asset_local']) && is_array($it['asset_local']) ? $it['asset_local'] : array();
            $coverLocal = !empty($it['cover_local']) && is_array($it['cover_local']) ? $it['cover_local'] : array();
            $open = ($focusIdx === $i) || ($focusIdx < 0 && !$isDone);
        ?>
        <details class="card wb-card" id="post-<?php echo (int) $i; ?>"<?php echo $open ? ' open' : ''; ?> style="margin-bottom:12px">
            <summary style="cursor:pointer;padding:14px 16px;list-style:none">
                <span style="font-weight:500"><?php echo e($it['title']); ?></span>
                <?php if ($isDone): ?>
                    <span class="badge badge-green" style="margin-left:8px">已导入 #<?php echo (int) (isset($it['article_id']) ? $it['article_id'] : 0); ?></span>
                <?php endif; ?>
                <span class="badge badge-gray" style="margin-left:6px"><?php echo e(date('Y-m-d', (int) $it['ts'])); ?></span>
                <span class="hint" style="margin-left:8px;font-size:12px">
                    资源 <?php echo (int) $it['_asset_up']; ?>/<?php echo (int) $it['_asset_n']; ?>
                    <?php if (!empty($coverLocal['url'])): ?> · 封面已传<?php endif; ?>
                </span>
            </summary>
            <div class="card-body" style="border-top:1px solid var(--color-border-tertiary)">
                <div class="hint" style="font-size:12px;margin-bottom:12px;line-height:1.7">
                    源文件：<code><?php echo e($it['file']); ?></code> ·
                    标签：<code><?php echo e($it['tags'] !== '' ? $it['tags'] : '—'); ?></code> ·
                    字数：<?php echo (int) $it['chars']; ?>
                </div>

                <?php if ($isDone): ?>
                    <div class="alert alert-ok" style="line-height:1.8">这篇已经导入过了，可以跳过。要重新导入请改标题后手动处理，避免重复。</div>
                <?php else: ?>

                <!-- 封面 -->
                <div class="wb-section">
                    <div class="wb-sec-title">封面图 <span class="hint">（写入 cover 字段）</span></div>
                    <div class="wb-cover-row">
                        <?php if (!empty($coverLocal['url'])): ?>
                            <img src="<?php echo e($coverLocal['url']); ?>" alt="封面预览" class="wb-thumb">
                            <span class="badge badge-green">已上传 · <?php echo e($coverLocal['name']); ?></span>
                        <?php else: ?>
                            <div class="hint">原路径：<code><?php echo e($it['cover'] !== '' ? $it['cover'] : '（无）'); ?></code></div>
                        <?php endif; ?>
                        <form method="post" action="<?php echo e(hexo_import_link()); ?>" enctype="multipart/form-data" class="wb-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="do" value="wb_cover">
                            <input type="hidden" name="token" value="<?php echo e($token); ?>">
                            <input type="hidden" name="idx" value="<?php echo (int) $i; ?>">
                            <input type="file" name="file" accept="image/*" required onchange="this.form.submit()">
                        </form>
                    </div>
                </div>

                <!-- 正文资源逐张上传 -->
                <div class="wb-section">
                    <div class="wb-sec-title">正文配图 / 附件 <span class="hint">（逐张上传，自动对位替换旧路径）</span></div>
                    <?php if (!$assets): ?>
                        <div class="hint">这篇正文没有需要替换的图片或附件。</div>
                    <?php else: ?>
                        <?php foreach ($assets as $s => $a): ?>
                            <?php $done = !empty($local[$s]['url']); ?>
                            <div class="wb-asset-row"<?php echo $done ? ' data-done="1"' : ''; ?>>
                                <div class="wb-asset-info">
                                    <?php if ($done && $a['kind'] === 'image'): ?>
                                        <img src="<?php echo e($local[$s]['url']); ?>" alt="预览" class="wb-thumb">
                                    <?php elseif ($done): ?>
                                        <span class="wb-fileico">附件</span>
                                    <?php else: ?>
                                        <span class="wb-slotno"><?php echo (int) ($s + 1); ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <?php if ($a['kind'] === 'file'): ?><span class="badge badge-amber" style="margin-right:5px">附件</span><?php endif; ?>
                                        <code style="font-size:12px"><?php echo e($a['label']); ?></code>
                                        <?php if ($done): ?>
                                            <div class="hint" style="font-size:11px">已替换为本站路径</div>
                                        <?php else: ?>
                                            <div class="hint" style="font-size:11px">原：<code><?php echo e($a['src']); ?></code></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <form method="post" action="<?php echo e(hexo_import_link()); ?>" enctype="multipart/form-data" class="wb-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="do" value="wb_img">
                                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                                    <input type="hidden" name="idx" value="<?php echo (int) $i; ?>">
                                    <input type="hidden" name="slot" value="<?php echo (int) $s; ?>">
                                    <input type="file" name="file" <?php echo $a['kind'] === 'image' ? 'accept="image/*"' : ''; ?> required onchange="this.form.submit()">
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- 导入设置 + 导入按钮 -->
                <form method="post" action="<?php echo e(hexo_import_link()); ?>" class="wb-form wb-import-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="wb_import">
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                    <input type="hidden" name="idx" value="<?php echo (int) $i; ?>">
                    <div class="wb-fields">
                        <div class="form-row">
                            <label>分类</label>
                            <input class="input" type="text" name="cat" value="<?php echo e($it['cat'] !== '' ? $it['cat'] : ''); ?>" placeholder="留空则归入未分类；不存在会自动建">
                        </div>
                        <div class="form-row">
                            <label>URL 别名 slug</label>
                            <input class="input" type="text" name="slug" value="<?php echo e($it['slug']); ?>" placeholder="abbrlink 已自动填入">
                            <div class="hint" style="font-size:11px">已从 Hexo 的 abbrlink 自动带入，可改</div>
                        </div>
                        <div class="form-row">
                            <label>推荐位 flag</label>
                            <div class="wb-flags">
                                <?php
                                    $flagMap = array('c' => '推荐', 'h' => '头条', 'p' => '图片', 'f' => '幻灯',
                                                     'a' => '特荐', 's' => '滚动', 'b' => '加粗', 'j' => '图文');
                                    foreach ($flagMap as $fk => $fname):
                                ?>
                                <label class="wb-flag"><input type="checkbox" name="flag[]" value="<?php echo e($fk); ?>"> <?php echo e($fname); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <label class="inline" style="font-weight:400">
                            <input class="check" type="checkbox" name="is_top" value="1"> 置顶这篇文章
                        </label>
                        <label class="inline" style="font-weight:400;margin-left:14px">
                            <input class="check" type="checkbox" name="skip_dup" value="1" checked> 跳过同名
                        </label>
                    </div>
                    <div style="margin-top:14px">
                        <button class="btn btn-primary" type="submit">导入这一篇</button>
                    </div>
                </form>

                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>

    <style>
    .wb-card > summary::-webkit-details-marker { display:none; }
    .wb-card > summary::before { content:'▸'; display:inline-block; margin-right:8px; color:var(--color-text-tertiary); }
    .wb-card[open] > summary::before { content:'▾'; }
    .wb-section { margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid var(--color-border-tertiary); }
    .wb-sec-title { font-weight:500; font-size:13px; margin-bottom:10px; }
    .wb-cover-row, .wb-asset-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:8px 0; }
    .wb-asset-row + .wb-asset-row { border-top:1px solid var(--color-border-tertiary); }
    .wb-asset-row[data-done="1"] { opacity:.85; }
    .wb-asset-info { display:flex; align-items:center; gap:10px; flex:1; min-width:220px; }
    .wb-thumb { width:64px; height:48px; object-fit:cover; border-radius:6px; border:1px solid var(--color-border-tertiary); }
    .wb-slotno { width:26px; height:26px; line-height:26px; text-align:center; border-radius:50%; background:var(--color-background-tertiary); font-size:12px; }
    .wb-fileico { font-size:11px; padding:3px 8px; border-radius:5px; background:var(--color-background-warning); color:var(--color-text-warning); }
    .wb-form { display:inline-flex; align-items:center; }
    .wb-form input[type=file] { font-size:12px; max-width:230px; }
    .wb-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; }
    .wb-flags { display:flex; gap:10px; flex-wrap:wrap; }
    .wb-flag { font-weight:400; font-size:12px; white-space:nowrap; }
    </style>
    <script>
    (function () {
        var params = new URLSearchParams(location.search);
        var focus = params.get('focus');
        if (focus !== null) {
            var el = document.getElementById('post-' + focus);
            if (el) { el.scrollIntoView({behavior:'smooth', block:'start'}); }
        }
    })();
    </script>
<?php endif; ?>
