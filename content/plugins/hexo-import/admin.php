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

/** 单篇 md → 待入库数组 */
function hexo_parse_one($name, $body)
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

    return array(
        'file'    => $name,
        'title'   => str_limit($title, 150),
        'slug'    => $slug,
        'ts'      => $ts,
        'cat'     => isset($cats[0]) ? trim((string) $cats[0]) : '',
        'tags'    => implode(',', array_filter(array_map('trim', $tags))),
        'cover'   => str_limit($cover, 255),
        'excerpt' => $excerpt,
        'html'    => $html,
        'draft'   => $draft ? 1 : 0,
        'chars'   => mb_strlen(content_plain($html), 'UTF-8'),
    );
}

/* ============ 提交处理 ============ */

hexo_tmp_gc();

if (is_post() && post_str('do') === 'parse') {
    csrf_verify();
    $errors = array();
    $files  = hexo_read_uploads($errors);

    if (!$files) {
        flash($errors ? implode('；', array_slice($errors, 0, 3)) : '没有可导入的文件', 'err');
        redirect(admin_url('plugin', array('id' => 'hexo-import')));
    }

    $items = array();
    foreach ($files as $f) {
        $items[] = hexo_parse_one($f['name'], $f['body']);
    }

    $token = bin2hex(random_bytes(8));
    $json  = json_encode(array('created' => time(), 'items' => $items), JSON_UNESCAPED_UNICODE);
    if (!write_file_atomic(hexo_tmp_file($token), $json)) {
        flash('临时文件写入失败，请检查 content/data/tmp 目录权限', 'err');
        redirect(admin_url('plugin', array('id' => 'hexo-import')));
    }

    if ($errors) {
        flash('已解析 ' . count($items) . ' 篇；' . count($errors) . ' 个文件被跳过：' . implode('；', array_slice($errors, 0, 2)), 'err');
    }
    redirect(admin_url('plugin', array('id' => 'hexo-import', 'token' => $token)));
}

if (is_post() && post_str('do') === 'import') {
    csrf_verify();
    $token = post_str('token');
    $file  = hexo_tmp_file($token);
    $data  = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    if (!is_array($data) || empty($data['items'])) {
        flash('导入数据已过期，请重新上传', 'err');
        redirect(admin_url('plugin', array('id' => 'hexo-import')));
    }

    $pick  = array_map('intval', (array) (isset($_POST['pick']) ? $_POST['pick'] : array()));
    $catId = (int) post_str('cat_id', '0');
    $skip  = post_str('skip_dup', '0') === '1';
    $user  = auth_user();

    if ($catId < 1 || !one('SELECT id FROM {p}category WHERE id = ?', array($catId))) {
        flash('请选择有效的目标栏目', 'err');
        redirect(admin_url('plugin', array('id' => 'hexo-import', 'token' => $token)));
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

        $row = array(
            'cat_id'      => $cid,
            'title'       => $title,
            'excerpt'     => str_limit((string) $it['excerpt'], 200),
            'content'     => (string) $it['html'],
            'content_src' => (string) $it['html'],
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
            cms_do_action('article_saved', $id, $row, true);
        } catch (Throwable $ex) {
            log_msg('hexo import failed: ' . $ex->getMessage() . ' | ' . $title);
        }
    }

    @unlink($file);

    $msg = '导入完成：成功 ' . $ok . ' 篇';
    if ($dup > 0) {
        $msg .= '，跳过同名 ' . $dup . ' 篇';
    }
    $msg .= '。记得重新生成静态页。';
    flash($msg);
    redirect(admin_url('plugin', array('id' => 'hexo-import')));
}

/* ============ 展示 ============ */

$cats   = all('SELECT id, name FROM {p}category ORDER BY id');
$token  = get_str('token');
$file   = hexo_tmp_file($token);
$preview = ($file !== '' && is_file($file)) ? json_decode((string) file_get_contents($file), true) : null;
$items   = (is_array($preview) && !empty($preview['items'])) ? $preview['items'] : array();
?>

<?php if (!$items): ?>
    <div class="card">
        <div class="card-head"><h2>上传 Markdown</h2></div>
        <div class="card-body">
            <p class="hint" style="margin-top:0;line-height:1.9">
                支持 Hexo 的 <code>source/_posts</code> 目录：可以多选 <code>.md</code> 文件，也可以直接上传整个 <code>_posts.zip</code>。
                解析基于 front-matter，没有头部的文件会退化为「文件名当标题、当前时间」。
            </p>
            <form method="post" action="<?php echo e(admin_url('plugin', array('id' => 'hexo-import'))); ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="parse">
                <div class="form-row">
                    <label for="files">选择文件</label>
                    <input class="input" type="file" id="files" name="files[]" multiple accept=".md,.markdown,.txt,.zip">
                    <div class="hint">单文件不超过 4MB，一次合计不超过 40MB。上传后先预览，不会直接入库。</div>
                </div>
                <button class="btn btn-primary" type="submit">解析并预览</button>
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
                <tr><td><code>categories</code> / <code>category</code></td><td>分类：名称能在本站找到就自动对上，找不到用你选择的栏目</td></tr>
                <tr><td><code>cover</code> / <code>photos</code></td><td>封面图地址</td></tr>
                <tr><td><code>excerpt</code> / <code>description</code></td><td>摘要（缺失时自动截取正文）</td></tr>
                <tr><td><code>slug</code></td><td>URL 别名（缺失时取文件名，自动去日期前缀）</td></tr>
                <tr><td><code>draft: true</code></td><td>导入为草稿</td></tr>
            </table>
        </div>
        <div class="card-foot hint" style="line-height:1.9">
            正文按 Markdown 转成 HTML 后入库；围栏代码块会沿用站内的深色面板，装好「代码高亮」插件即有配色。<br>
            Hexo 标签插件（<code>{% codeblock %}</code> 之类）会被忽略，图片仍指向原来的路径，需要自己把图片传进媒体库或改成新地址。
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card-head">
            <h2>确认导入</h2>
            <span class="badge badge-blue"><?php echo count($items); ?> 篇待处理</span>
        </div>
        <form method="post" action="<?php echo e(admin_url('plugin', array('id' => 'hexo-import'))); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="import">
            <input type="hidden" name="token" value="<?php echo e($token); ?>">
            <div class="card-body tight">
                <table class="table">
                    <tr>
                        <th style="width:44px"><input class="check" type="checkbox" id="pick-all" checked></th>
                        <th>标题</th>
                        <th style="width:120px">日期</th>
                        <th style="width:110px">分类</th>
                        <th style="width:150px">标签</th>
                        <th style="width:80px">字数</th>
                    </tr>
                    <?php foreach ($items as $i => $it): ?>
                        <tr>
                            <td><input class="check" type="checkbox" name="pick[]" value="<?php echo (int) $i; ?>" checked></td>
                            <td>
                                <?php echo e($it['title']); ?>
                                <?php if ((int) $it['draft'] === 1): ?><span class="badge badge-gray" style="margin-left:6px">草稿</span><?php endif; ?>
                                <div class="hint" style="font-size:11px"><?php echo e($it['file']); ?> · <?php echo e($it['slug']); ?></div>
                            </td>
                            <td class="hint" style="font-size:12px"><?php echo e(date('Y-m-d', (int) $it['ts'])); ?></td>
                            <td class="hint" style="font-size:12px"><?php echo e($it['cat'] !== '' ? $it['cat'] : '—'); ?></td>
                            <td class="hint" style="font-size:12px"><?php echo e($it['tags'] !== '' ? $it['tags'] : '—'); ?></td>
                            <td class="hint" style="font-size:12px"><?php echo (int) $it['chars']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <label for="cat_id">目标栏目</label>
                    <select class="input" id="cat_id" name="cat_id">
                        <?php foreach ($cats as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo e($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint">front-matter 里的分类名在站内存在时会优先使用它，其余落到这里选的栏目。</div>
                </div>
                <label class="inline" style="font-weight:400">
                    <input class="check" type="checkbox" name="skip_dup" value="1" checked>
                    跳过标题已存在的文章
                </label>
                <div style="margin-top:14px">
                    <button class="btn btn-primary" type="submit">确认导入所选文章</button>
                    <a class="btn" style="margin-left:8px" href="<?php echo e(admin_url('plugin', array('id' => 'hexo-import'))); ?>">重新上传</a>
                </div>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var all = document.getElementById('pick-all');
        if (!all) { return; }
        all.addEventListener('change', function () {
            var boxes = document.querySelectorAll('input[name="pick[]"]');
            for (var i = 0; i < boxes.length; i++) { boxes[i].checked = all.checked; }
        });
    })();
    </script>
<?php endif; ?>
