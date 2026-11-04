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

    // 图片：先把 src 收集起来，能补成绝对地址的先补，补不上的留给预览页提示
    $imgs = array();
    foreach (hexo_img_srcs($html) as $src) {
        $imgs[] = array('src' => $src, 'url' => hexo_img_url($src, $base));
    }

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
        'imgs'    => $imgs,
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
    $base   = hexo_img_base(post_str('img_base'));

    if (!$files) {
        flash($errors ? implode('；', array_slice($errors, 0, 3)) : '没有可导入的文件', 'err');
        redirect(admin_url('hexo-import'));
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
        redirect(admin_url('hexo-import'));
    }

    if ($imgMiss > 0) {
        flash('有 ' . $imgMiss . ' 张图片还是相对地址，请在下一步填写原站地址，否则这些图导入后仍指向旧站。', 'err');
    }

    if ($errors) {
        flash('已解析 ' . count($items) . ' 篇；' . count($errors) . ' 个文件被跳过：' . implode('；', array_slice($errors, 0, 2)), 'err');
    }
    redirect(admin_url('hexo-import', array('token' => $token)));
}

if (is_post() && post_str('do') === 'import') {
    csrf_verify();
    $token = post_str('token');
    $file  = hexo_tmp_file($token);
    $data  = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    if (!is_array($data) || empty($data['items'])) {
        flash('导入数据已过期，请重新上传', 'err');
        redirect(admin_url('hexo-import'));
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
        redirect(admin_url('hexo-import', array('token' => $token)));
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
    if ($imgOk > 0) {
        $msg .= '，图片已本地化 ' . $imgOk . ' 张';
    }
    if ($imgFail) {
        $msg .= '，' . count($imgFail) . ' 张失败（保留原地址）：' . implode('；', array_slice($imgFail, 0, 2));
    }
    $msg .= '。记得重新生成静态页。';
    flash($msg, $imgFail ? 'err' : 'ok');
    redirect(admin_url('hexo-import'));
}

/* ============ 展示 ============ */

$cats   = all('SELECT id, name FROM {p}category ORDER BY id');
$token  = get_str('token');
$file   = hexo_tmp_file($token);
$preview = ($file !== '' && is_file($file)) ? json_decode((string) file_get_contents($file), true) : null;
$items   = (is_array($preview) && !empty($preview['items'])) ? $preview['items'] : array();
$base    = isset($preview['base']) ? (string) $preview['base'] : '';

/* 提前算好每篇的图片数，模板里不要再套循环 */
$imgTotal = 0;
$imgNeed  = 0;
foreach ($items as $k => $it) {
    $n = 0;
    $need = 0;
    if (!empty($it['imgs']) && is_array($it['imgs'])) {
        foreach ($it['imgs'] as $im) {
            $n++;
            if ($im['url'] === '') {
                $need++;
            }
        }
    }
    $items[$k]['_img_n']    = $n;
    $items[$k]['_img_need'] = $need;
    $imgTotal += $n;
    $imgNeed  += $need;
}
?>

<?php if (!$items): ?>
    <div class="card">
        <div class="card-head"><h2>上传 Markdown</h2></div>
        <div class="card-body">
            <p class="hint" style="margin-top:0;line-height:1.9">
                支持 Hexo 的 <code>source/_posts</code> 目录：可以多选 <code>.md</code> 文件，也可以直接上传整个 <code>_posts.zip</code>。
                解析基于 front-matter，没有头部的文件会退化为「文件名当标题、当前时间」。
            </p>
            <form method="post" action="<?php echo e(admin_url('hexo-import')); ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="parse">
                <div class="form-row">
                    <label for="files">选择文件</label>
                    <input class="input" type="file" id="files" name="files[]" multiple accept=".md,.markdown,.txt,.zip">
                    <div class="hint">单文件不超过 4MB，一次合计不超过 40MB。上传后先预览，不会直接入库。</div>
                </div>
                <div class="form-row">
                    <label for="img_base">图片附件完整地址</label>
                    <input class="input" type="text" id="img_base" name="img_base" placeholder="https://旧站域名" autocomplete="off">
                    <div class="hint">
                        正文里的图片若是相对地址（<code>/images/a.png</code>、<code>images/a.png</code>），
                        填上原站地址才能下载到本地；全站图片在同一个域名下时填域名即可。
                        要带 <code>http://</code> 或 <code>https://</code>，留空则图片保持原样，仍指向旧站。
                    </div>
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
            图片在导入时下载到 <code>content/uploads/images/年/月日/</code>，改名为「日期 + 微秒时间戳」并保持原格式，正文地址同步换成本站地址；下载失败的保留原地址，导入结果里会列出来。<br>
            Hexo 标签插件（<code>{% codeblock %}</code> 之类）会被忽略。
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card-head">
            <h2>确认导入</h2>
            <span class="badge badge-blue"><?php echo count($items); ?> 篇待处理</span>
        </div>
        <form method="post" action="<?php echo e(admin_url('hexo-import')); ?>">
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
                        <th style="width:90px">图片</th>
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
                            <td class="hint" style="font-size:12px">
                                <?php if ((int) $it['_img_n'] === 0): ?>
                                    —
                                <?php else: ?>
                                    <?php echo (int) $it['_img_n']; ?> 张
                                    <?php if ((int) $it['_img_need'] > 0): ?>
                                        <span class="badge badge-amber" style="margin-left:4px">缺地址</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="hint" style="font-size:12px"><?php echo (int) $it['chars']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <div class="card-body">
                <?php if ($imgTotal > 0): ?>
                    <div class="alert <?php echo $imgNeed > 0 ? 'alert-err' : 'alert-ok'; ?>" style="margin:0 0 14px;line-height:1.8">
                        <?php if ($imgNeed > 0): ?>
                            共检测到 <b><?php echo (int) $imgTotal; ?></b> 张图片，其中 <b><?php echo (int) $imgNeed; ?></b> 张是相对地址。
                            不填下面的「图片附件完整地址」，这部分图片就会保留旧站地址，导入后大概率显示不出来。
                        <?php else: ?>
                            共检测到 <b><?php echo (int) $imgTotal; ?></b> 张图片，导入时会自动下载到
                            <code>content/uploads/images/年/月日/</code> 并换成本站地址。
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="form-row">
                    <label for="img_base">图片附件完整地址</label>
                    <input class="input" type="text" id="img_base" name="img_base" value="<?php echo e($base); ?>" placeholder="https://旧站域名" autocomplete="off">
                    <div class="hint">
                        只对相对地址生效，拼法是「这里填的地址 + 原文里的路径」，例如填 <code>https://old.com</code>，
                        <code>/images/a.png</code> 就会去下 <code>https://old.com/images/a.png</code>。
                        本来就写全的 <code>http(s)://</code> 地址不受影响，照样会下载。
                    </div>
                </div>
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
                    <a class="btn" style="margin-left:8px" href="<?php echo e(admin_url('hexo-import')); ?>">重新上传</a>
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
