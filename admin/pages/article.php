<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 文章管理：列表 / 新增 / 编辑 / 删除 */

$cats = fe_cats();

/* ---------- 写操作 ---------- */
if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'save') {
        $id      = (int) post_str('id', '0');
        $title   = post_str('title');
        // 正文按原样取（post() 会 trim 首尾空白，代码块的边界不该被修剪）
        $contentSrc = isset($_POST['content']) && is_string($_POST['content']) ? $_POST['content'] : '';
        // 顺序很重要：先把 ``` 围栏转成 <pre><code>，再只对代码块之外做净化。
        // 反过来的话，教程里演示的 <script>、onclick 会被 clean_html 删掉。
        $content = render_content_clean($contentSrc);
        $catId   = (int) post_str('cat_id', '0');
        $status  = post_str('status', '0') === '1' ? 1 : 0;
        $isTop   = post_str('is_top', '') === '1' ? 1 : 0;
        $backUrl = admin_url('article', array('action' => $id > 0 ? 'edit' : 'new', 'id' => $id));

        if ($title === '') {
            flash('标题不能为空', 'err');
            redirect($backUrl);
        }
        // 栏目必须真实存在，否则文章会落到 uncategorized 目录、列表页也查不到
        if ($catId < 1 || !one('SELECT id FROM {p}category WHERE id = ?', array($catId))) {
            flash('请选择有效的栏目', 'err');
            redirect($backUrl);
        }
        if (trim(strip_tags($content)) === '') {
            flash('正文不能为空', 'err');
            redirect($backUrl);
        }

        $excerpt = post_str('excerpt');
        if ($excerpt === '') {
            // 摘要排除代码块，不然列表页的简介会变成一堆代码
            $excerpt = str_limit(content_plain($content), 150);
        }

        // 字段长度兜底：数据库列有限，超长会直接抛 SQL 错误导致内容丢失
        $cover = post_str('cover');
        if (function_exists('mb_substr')) {
            $cover = mb_substr($cover, 0, 255, 'UTF-8');
        } else {
            $cover = substr($cover, 0, 255);
        }

        $flags = array();
        foreach (array_keys(cms_flag_map()) as $fk) {
            if (post_str('flag_' . $fk) === '1') {
                $flags[] = $fk;
            }
        }

        // 附件：地址留空就一并清掉名字、大小与显示开关，避免留下孤儿数据
        $attach     = cms_file_clean(post_str('attachment'));
        $attachName = str_limit(post_str('attach_name'), 100);
        $attachSize = max(0, (int) post_str('attach_size', '0'));
        $attachShow = ($attach !== '' && post_str('attach_show') === '1') ? 1 : 0;
        if ($attach === '') {
            $attachName = '';
            $attachSize = 0;
        } elseif ($attachName === '') {
            // 名字没填就退回文件名，至少有东西可点
            $attachName = str_limit(basename((string) parse_url($attach, PHP_URL_PATH)), 100);
        }

        $data = array(
            'cat_id'     => $catId,
            'title'      => str_limit($title, 150),
            'excerpt'    => str_limit($excerpt, 200),
            'content'    => $content,
            'content_src' => $contentSrc,
            'attachment' => $attach,
            'attach_name' => $attachName,
            'attach_size' => $attachSize,
            'attach_show' => $attachShow,
            'cover'      => $cover,
            'tags'       => normalize_tags(post_str('tags')),
            'flag'       => implode(',', $flags),
            'status'     => $status,
            'is_top'     => $isTop,
            'updated_at' => time(),
        );

        $slugRaw = make_slug(post_str('slug'));
        $user    = auth_user();
        // 生成静态页时需要知道"改之前"属于哪个分类、有哪些标签，否则旧页面不会被重建
        $oldRow  = $id > 0 ? one('SELECT cat_id, tags, slug FROM {p}article WHERE id = ?', array($id)) : null;

        if ($id > 0) {
            if ($slugRaw !== '') {
                $data['slug'] = unique_slug('article', $slugRaw, $id);
            }
            update_row('article', $data, 'id = ?', array($id));
            $msg = '文章已更新';
        } else {
            $data['slug']       = $slugRaw !== '' ? unique_slug('article', $slugRaw, 0) : 'tmp' . substr(md5(uniqid('', true)), 0, 10);
            $data['author_id']  = (int) $user['id'];
            $data['views']      = 0;
            $data['created_at'] = time();
            $id = insert_row('article', $data);
            if ($slugRaw === '') {
                // 中文标题未生成 slug 时，用文章 ID 作为访问路径
                update_row('article', array('slug' => (string) $id), 'id = ?', array($id));
            }
            $msg = '文章已创建';
        }

        // 改为草稿、换了分类：把旧的静态页删掉，避免仍能被访问
        $newRow = one('SELECT slug, cat_id, tags, status FROM {p}article WHERE id = ?', array($id));
        $stale  = array();
        if ($oldRow) {
            // 改版前的老地址，升级后第一次保存时顺手清掉
            if ($oldRow['slug'] !== '') {
                $stale[] = 'post/' . $oldRow['slug'] . '.html';
            }
            // 文章地址由"分类目录 + ID"决定，换分类就换了地址
            if ((int) $oldRow['cat_id'] !== (int) $catId || (int) $newRow['status'] !== 1) {
                $oldDir = (string) val('SELECT dirname FROM {p}category WHERE id = ?', array((int) $oldRow['cat_id']));
                if ($oldDir !== '') {
                    $stale[] = cat_dir_of($oldDir) . '/' . (int) $id . '.html';
                }
            }
        }
        if ($stale) {
            static_forget($stale);
        }

        if (setting('auto_build', '1') === '1') {
            $catIds = array($catId);
            $tags   = array();
            if ($oldRow) {
                $catIds[] = (int) $oldRow['cat_id'];
                foreach (explode(',', (string) $oldRow['tags']) as $t) {
                    if (trim($t) !== '') {
                        $tags[] = trim($t);
                    }
                }
            }
            foreach (explode(',', (string) $data['tags']) as $t) {
                if (trim($t) !== '') {
                    $tags[] = trim($t);
                }
            }
            $r = build_incremental(array(
                'post_id' => $id,
                'cat_ids' => $catIds,
                'tags'    => array_values(array_unique($tags)),
            ));
            if (!empty($r['partial'])) {
                $msg .= '，其余 ' . (int) $r['left'] . ' 个页面正在后台继续生成';
            } elseif ($r['ok']) {
                $msg .= '，已更新 ' . (int) $r['files'] . ' 个静态页面';
            } else {
                $msg .= '，但静态生成有 ' . count($r['errors']) . ' 个问题（' . implode('；', array_slice($r['errors'], 0, 2)) . '）';
            }
        }
        // 插件钩子：同步到外部系统、生成推送、写索引都在这里接
        cms_do_action('article_saved', $id, $data, $oldRow ? false : true);

        flash($msg);
        redirect(admin_url('article'));
    }

    if ($do === 'delete') {
        $id = (int) post_str('id', '0');
        if ($id > 0) {
            $row = one('SELECT cat_id, tags, slug FROM {p}article WHERE id = ?', array($id));
            delete_row('article', 'id = ?', array($id));
            // 不管是否开启自动生成，被删文章的静态页都必须立刻删掉
            if ($row) {
                $gone = array();
                if ($row['slug'] !== '') {
                    $gone[] = 'post/' . $row['slug'] . '.html';
                }
                $dir = (string) val('SELECT dirname FROM {p}category WHERE id = ?', array((int) $row['cat_id']));
                $gone[] = cat_dir_of($dir) . '/' . (int) $id . '.html';
                static_forget($gone);
            }
            if (setting('auto_build', '1') === '1') {
                $tags = array();
                if ($row) {
                    foreach (explode(',', (string) $row['tags']) as $t) {
                        if (trim($t) !== '') {
                            $tags[] = trim($t);
                        }
                    }
                }
                build_incremental(array(
                    'cat_ids' => array($row ? (int) $row['cat_id'] : 0),
                    'tags'    => $tags,
                ));
            }
            flash('文章已删除');
        }
        redirect(admin_url('article'));
    }
}

$action = get_str('action', 'list');
$id     = (int) get('id', 0);

/* ---------- 编辑表单 ---------- */
if ($action === 'new' || $action === 'edit') {
    if ($action === 'edit') {
        $row = one('SELECT * FROM {p}article WHERE id = ?', array($id));
        if (!$row) {
            flash('文章不存在', 'err');
            redirect(admin_url('article'));
        }
    } else {
        $row = array(
            'id' => 0, 'cat_id' => (isset($cats[0]) ? (int) $cats[0]['id'] : 0),
            'title' => '', 'slug' => '', 'excerpt' => '', 'content' => '', 'content_src' => '',
            'cover' => '', 'tags' => '', 'flag' => '', 'status' => 1, 'is_top' => 0,
            'attachment' => '', 'attach_name' => '', 'attach_size' => 0, 'attach_show' => 0,
        );
    }
    // 结构升级没跑成功时（数据库账号可能没有 ALTER 权限）字段会缺，这里兜一层默认值
    foreach (array('attachment' => '', 'attach_name' => '', 'attach_size' => 0, 'attach_show' => 0) as $fk2 => $fv2) {
        if (!isset($row[$fk2])) {
            $row[$fk2] = $fv2;
        }
    }
    ?>
    <div class="card">
        <div class="card-head">
            <h2><?php echo $row['id'] ? '编辑文章 #' . (int) $row['id'] : '写新文章'; ?></h2>
            <a class="btn btn-sm" href="<?php echo e(admin_url('article')); ?>">返回列表</a>
        </div>
        <div class="card-body">
            <form method="post" action="<?php echo e(admin_url('article')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                <div class="form-row">
                    <label for="title">标题</label>
                    <input class="input" type="text" id="title" name="title" value="<?php echo e($row['title']); ?>" placeholder="请输入文章标题" required>
                </div>

                <?php
                $flagsOn = array_filter(array_map('trim', explode(',', (string) (isset($row['flag']) ? $row['flag'] : ''))));
                ?>
                <div class="card card-inset">
                    <div class="card-head">
                        <h2>属性</h2>
                        <span class="hint" style="margin:0">选好分类与推荐位，再往下写正文</span>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <label>推荐位 <span class="hint" style="display:inline;margin:0">（勾选后可在模板中用 <code>flag="c"</code> 调取）</span></label>
                            <div class="flag-pick">
                                <?php foreach (cms_flag_map() as $fk => $fv): ?>
                                    <label class="flag-chip">
                                        <input type="checkbox" name="flag_<?php echo e($fk); ?>" value="1" <?php echo in_array($fk, $flagsOn, true) ? 'checked' : ''; ?>>
                                        <span><?php echo e($fv); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="grid3">
                            <div class="form-row">
                                <label for="cat_id">分类</label>
                                <select class="input" id="cat_id" name="cat_id">
                                    <option value="0">未分类</option>
                                    <?php foreach ($cats as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $row['cat_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($cats)): ?>
                                    <div class="hint">还没有分类，<a href="<?php echo e(admin_url('category')); ?>">先建一个</a></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-row">
                                <label for="tags">标签</label>
                                <input class="input" type="text" id="tags" name="tags" value="<?php echo e($row['tags']); ?>" placeholder="用逗号分隔，最多 8 个">
                            </div>
                            <div class="form-row">
                                <label for="slug">自定义路径（可选）</label>
                                <input class="input" type="text" id="slug" name="slug" value="<?php echo e($row['slug']); ?>" placeholder="留空自动生成">
                                <div class="hint">仅英文/数字，输出 /post/xxx.html</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="cover">封面图地址</label>
                            <div class="cover-field<?php echo trim((string) $row['cover']) !== '' ? ' has-img' : ''; ?>" data-cover-field>
                                <input class="input" type="text" id="cover" name="cover" value="<?php echo e($row['cover']); ?>" placeholder="https://... 或 /content/uploads/...">
                                <div class="cover-pick">
                                    <img id="cover-preview" alt="封面预览"<?php echo trim((string) $row['cover']) !== '' ? ' src="' . e($row['cover']) . '"' : ' hidden'; ?>>
                                    <span class="cover-empty"<?php echo trim((string) $row['cover']) !== '' ? ' hidden' : ''; ?>>暂无封面</span>
                                    <button type="button" class="cover-clear" title="移除封面" aria-label="移除封面">&times;</button>
                                </div>
                            </div>
                            <div class="hint">填了地址右侧立即预览；可先在<a href="<?php echo e(admin_url('media')); ?>">媒体信息</a>上传后复制地址</div>
                        </div>

                        <?php
                        $hasAttach = trim((string) $row['attachment']) !== '';
                        ?>
                        <div class="form-row" style="margin-bottom:0">
                            <label for="attachment">附件（可选）</label>
                            <div class="attach-field<?php echo $hasAttach ? ' has-file' : ''; ?>" data-attach-field>
                                <input class="input" type="text" id="attachment" name="attachment" value="<?php echo e($row['attachment']); ?>" placeholder="点右侧上传，或粘贴一个下载地址">
                                <button type="button" class="btn btn-sm" data-attach-pick>选择文件</button>
                                <button type="button" class="attach-clear" title="移除附件" aria-label="移除附件">&times;</button>
                                <input type="file" class="attach-file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md,.csv,.rtf,.zip,.rar,.7z,.gz,.tar,.exe,.msi,.apk,.dmg,.ipa,.pkg" hidden>
                            </div>
                            <input type="hidden" name="attach_size" id="attach_size" value="<?php echo (int) $row['attach_size']; ?>">
                            <div class="grid2" style="margin-top:10px">
                                <div class="form-row" style="margin-bottom:0">
                                    <label for="attach_name">下载按钮上的名字</label>
                                    <input class="input" type="text" id="attach_name" name="attach_name" value="<?php echo e($row['attach_name']); ?>" placeholder="留空则用文件名">
                                </div>
                                <div class="form-row" style="margin-bottom:0">
                                    <label>是否显示</label>
                                    <label class="inline" style="font-weight:400;padding-top:7px">
                                        <input class="check" type="checkbox" name="attach_show" value="1" <?php echo (int) $row['attach_show'] === 1 ? 'checked' : ''; ?>>
                                        文章页底部显示下载按钮
                                    </label>
                                </div>
                            </div>
                            <div class="hint" data-attach-info>
                                <?php if ($hasAttach): ?>
                                    当前附件：<?php echo e($row['attach_name'] !== '' ? $row['attach_name'] : basename($row['attachment'])); ?><?php echo (int) $row['attach_size'] > 0 ? '（' . e(format_bytes($row['attach_size'])) . '）' : ''; ?>
                                <?php else: ?>
                                    支持 pdf / word / excel / ppt / 压缩包 / apk / exe，单个不超过 <?php echo e(format_bytes(cms_file_max_bytes())); ?>。默认不显示，勾上「显示下载按钮」才会出现在文章页。
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <label for="content">正文（支持 HTML）</label>
                    <div class="editor-tools">
                        <button type="button" data-insert="p">段落</button>
                        <button type="button" data-insert="h2">标题</button>
                        <button type="button" data-insert="bold">加粗</button>
                        <button type="button" data-insert="ul">列表</button>
                        <button type="button" data-insert="quote">引用</button>
                        <button type="button" data-insert="code">代码块</button>
                        <button type="button" data-insert="link">链接</button>
                        <button type="button" data-insert="img">图片</button>
                    </div>
                    <textarea class="input" id="content" name="content" style="min-height:430px" placeholder="<p>开始写点什么…</p>"><?php echo e(content_source($row)); ?></textarea>
                    <div class="hint">图片：点工具栏的「图片」，可以上传本地文件（也能直接拖进去），或粘贴网址让程序抓回本站。</div>
                    <div class="hint">代码块写法：<code>```php</code> 开头，写好代码后另起一行 <code>```</code> 收尾。支持 php / sql / bash / js / html / css / json 等，保存后前台自动高亮并可一键复制。</div>
                    <div class="hint">出于安全考虑，保存时会移除 script / iframe 标签与 onclick 等事件属性。</div>
                </div>
                <div class="form-row">
                    <label for="excerpt">摘要</label>
                    <textarea class="input" id="excerpt" name="excerpt" style="min-height:74px" placeholder="留空则自动截取正文前 150 字"><?php echo e($row['excerpt']); ?></textarea>
                </div>

                <div class="card card-inset">
                    <div class="card-head"><h2>发布</h2></div>
                    <div class="card-body">
                        <div class="form-row">
                            <label for="status">发布状态</label>
                            <div class="inline">
                                <select class="input" id="status" name="status" style="width:180px">
                                    <option value="1" <?php echo (int) $row['status'] === 1 ? 'selected' : ''; ?>>已发布</option>
                                    <option value="0" <?php echo (int) $row['status'] === 0 ? 'selected' : ''; ?>>草稿</option>
                                </select>
                                <label style="font-weight:400">
                                    <input class="check" type="checkbox" name="is_top" value="1" <?php echo (int) $row['is_top'] === 1 ? 'checked' : ''; ?>>
                                    置顶（列表优先展示）
                                </label>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">保存并生成</button>
                            <?php $previewUrl = $row['id'] ? u_post_preview($row) : ''; ?>
                            <button class="btn" type="button" data-preview="article" data-static-url="<?php echo e($previewUrl); ?>"
                                    title="在当前窗口弹窗预览，未保存的改动、草稿也能看">预览</button>
                            <a class="btn" href="<?php echo e(admin_url('article')); ?>">取消</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
    return;
}

/* ---------- 列表 ---------- */
$page   = max(1, (int) get('page', 1));
$per    = 15;
$kw     = get_str('kw');
$fc     = (int) get('cat', 0);
$fs     = get_str('status', '');
$where  = '1=1';
$params = array();

if ($kw !== '') {
    $where .= ' AND a.title LIKE ?';
    $params[] = '%' . addcslashes($kw, '%_\\') . '%';
}
if ($fc > 0) {
    $where .= ' AND a.cat_id = ?';
    $params[] = $fc;
}
if ($fs === '0' || $fs === '1') {
    $where .= ' AND a.status = ?';
    $params[] = (int) $fs;
}

$total = (int) val('SELECT COUNT(*) FROM {p}article a WHERE ' . $where, $params);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows  = all(
    'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
     WHERE ' . $where . ' ORDER BY a.is_top DESC, a.id DESC
     LIMIT ' . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
    $params
);
?>
<div class="card">
    <div class="card-head">
        <h2>文章列表</h2>
        <div class="filter-bar">
            <form method="get" action="index.php" class="filter-bar">
                <input type="hidden" name="p" value="article">
                <input class="input" type="text" name="kw" value="<?php echo e($kw); ?>" placeholder="搜索标题">
                <select class="input" name="cat">
                    <option value="0">全部分类</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $fc === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="input" name="status">
                    <option value="">全部状态</option>
                    <option value="1" <?php echo $fs === '1' ? 'selected' : ''; ?>>已发布</option>
                    <option value="0" <?php echo $fs === '0' ? 'selected' : ''; ?>>草稿</option>
                </select>
                <button class="btn btn-sm" type="submit">筛选</button>
            </form>
        </div>
    </div>

    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <div class="empty">没有符合条件的文章</div>
        <?php else: ?>
            <table class="table">
                <tr>
                    <th style="width:44%">标题</th><th>分类</th><th>状态</th><th>浏览</th><th>发布时间</th><th class="actions-cell">操作</th>
                </tr>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <?php if ((int) $r['is_top'] === 1): ?><span class="badge badge-amber">置顶</span> <?php endif; ?>
                            <?php
                            $flagMap = cms_flag_map();
                            if (!empty($r['flag'])):
                                foreach (explode(',', $r['flag']) as $fk):
                                    $fk = trim($fk);
                                    if ($fk !== '' && isset($flagMap[$fk])):
                                        ?>
                                        <span class="badge badge-blue"><?php echo e($flagMap[$fk]); ?></span>
                                        <?php
                                    endif;
                                endforeach;
                            endif;
                            ?>
                            <a href="<?php echo e(admin_url('article', array('action' => 'edit', 'id' => $r['id']))); ?>"><?php echo e($r['title']); ?></a>
                        </td>
                        <td><?php echo e($r['cat_name'] ? $r['cat_name'] : '未分类'); ?></td>
                        <td>
                            <?php if ((int) $r['status'] === 1): ?>
                                <span class="badge badge-green">已发布</span>
                            <?php else: ?>
                                <span class="badge badge-gray">草稿</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) $r['views']; ?></td>
                        <td class="hint"><?php echo e(format_date($r['created_at'], 'Y-m-d H:i')); ?></td>
                        <td class="actions-cell">
                            <?php $pv = u_post_preview($r); ?>
                            <?php if ($pv !== ''): ?>
                                <a class="btn btn-sm" target="_blank" href="<?php echo e($pv); ?>">预览</a>
                            <?php else: ?>
                                <button class="btn btn-sm" type="button" disabled title="草稿不生成静态页">预览</button>
                            <?php endif; ?>
                            <a class="btn btn-sm" href="<?php echo e(admin_url('article', array('action' => 'edit', 'id' => $r['id']))); ?>">编辑</a>
                            <form method="post" action="<?php echo e(admin_url('article')); ?>" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                <button class="btn btn-sm btn-danger" type="submit" data-confirm="确定删除《<?php echo e($r['title']); ?>》？该操作不可恢复。">删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    <?php
    echo admin_pager_html($page, $pages, function ($n) use ($kw, $fc, $fs) {
        return admin_url('article', array('kw' => $kw, 'cat' => $fc ? $fc : '', 'status' => $fs, 'page' => $n));
    }, $total);
    ?>
</div>
