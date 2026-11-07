<?php
/**
 * CiciCMS 后台内容预览（弹窗内 iframe 使用）
 *
 * 前台是纯静态站，没有任何动态页面；这里的渲染结果只给登录后的编辑者本人在后台弹窗里看：
 *  - GET  ?type=article&id=12  按数据库当前内容渲染（草稿也能看）
 *  - POST 表单字段              按编辑器里未保存的内容渲染（先看效果再决定存不存）
 * POST 分支与 admin/pages/article.php、page.php 的保存链路保持同构：
 * 源文 → 代码块围栏渲染 → HTML 净化，保证「所见即所得」。
 * 页面带 noindex，浏览计数脚本会被剔除，预览绝不计入浏览量。
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_login();

define('CMS_ADMIN', true);

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');

/** 预览不计浏览量、不劫持弹窗：剔除计数脚本，站内链接一律新开窗口 */
function pv_strip_view_counter($html)
{
    $html = (string) $html;
    // 主题 footer 里是内联 script（无 src），整段剔除
    $html = preg_replace(
        '#<script\b(?![^>]*\bsrc=)[^>]*>(?:(?!</script>).)*api\.php\?act=view(?:(?!</script>).)*</script>#is',
        '',
        $html
    );
    // 兜底：主题换了写法也绝不能计数
    $html = str_replace('api.php?act=view', 'about:blank#no-view', $html);
    // 只设 target 的 <base> 不改变 URL 基准，只让链接落在新窗口
    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('#</head>#i', "<base target=\"_blank\">\n</head>", $html, 1);
    }
    return (string) $html;
}

function pv_fail($msg)
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="zh-CN"><meta charset="utf-8">'
        . '<body style="font:14px/1.7 system-ui;padding:32px;color:#666">' . e($msg) . '</body></html>';
    exit;
}

$type = get_str('type', 'article');

/* ---------- POST：按编辑器里未保存的内容预览 ---------- */
if (is_post()) {
    csrf_verify();
    $type = post_str('type', $type);
    $id   = (int) post_str('id', 0);

    // 与保存时完全一致：不 trim 源文，先渲染 ``` 围栏再净化其余部分
    $contentSrc = isset($_POST['content']) && is_string($_POST['content']) ? $_POST['content'] : '';
    $content    = render_content_clean($contentSrc);

    if ($type === 'page') {
        $row = array(
            'id'         => $id,
            'title'      => str_limit(post_str('title'), 150),
            'slug'       => post_str('slug'),
            'content'    => $content,
            'content_src' => $contentSrc,
            'status'     => post_str('status', '0') === '1' ? 1 : 0,
            'sort'       => (int) post_str('sort', 0),
            'views'      => 0,
            'created_at' => time(),
            'updated_at' => time(),
        );
        echo pv_strip_view_counter(fe_render_page($row));
        exit;
    }

    $excerpt = post_str('excerpt');
    if ($excerpt === '') {
        $excerpt = str_limit(content_plain($content), 150);
    }

    $flags = array();
    foreach (array_keys(cms_flag_map()) as $fk) {
        if (post_str('flag_' . $fk) === '1') {
            $flags[] = $fk;
        }
    }

    $catId = (int) post_str('cat_id', 0);
    $cat   = $catId > 0 ? one('SELECT name, dirname FROM {p}category WHERE id = ?', array($catId)) : null;

    $attach     = cms_file_clean(post_str('attachment'));
    $attachName = str_limit(post_str('attach_name'), 100);
    $attachSize = max(0, (int) post_str('attach_size', '0'));
    $attachShow = ($attach !== '' && post_str('attach_show') === '1') ? 1 : 0;
    if ($attach === '') {
        $attachName = '';
        $attachSize = 0;
    } elseif ($attachName === '') {
        $attachName = str_limit(basename((string) parse_url($attach, PHP_URL_PATH)), 100);
    }

    $row = array(
        'id'          => $id,
        'cat_id'      => $catId,
        'cat_name'    => $cat ? (string) $cat['name'] : '',
        'cat_dir'     => $cat ? (string) $cat['dirname'] : '',
        'title'       => str_limit(post_str('title'), 150),
        'slug'        => post_str('slug'),
        'excerpt'     => str_limit($excerpt, 200),
        'content'     => $content,
        'content_src' => $contentSrc,
        'cover'       => post_str('cover'),
        'tags'        => normalize_tags(post_str('tags')),
        'flag'        => implode(',', $flags),
        'status'      => post_str('status', '0') === '1' ? 1 : 0,
        'is_top'      => post_str('is_top') === '1' ? 1 : 0,
        'attachment'  => $attach,
        'attach_name' => $attachName,
        'attach_size' => $attachSize,
        'attach_show' => $attachShow,
        'views'       => 0,
        'created_at'  => time(),
        'updated_at'  => time(),
    );
    echo pv_strip_view_counter(fe_render_article($row));
    exit;
}

/* ---------- GET：按数据库当前内容预览（草稿也可） ---------- */
$id = get_int('id', 0);
if ($id <= 0) {
    pv_fail('缺少内容编号');
}

if ($type === 'page') {
    $row = one('SELECT * FROM {p}page WHERE id = ?', array($id));
    if (!$row) {
        pv_fail('单页不存在（可能已被删除）');
    }
    echo pv_strip_view_counter(fe_render_page($row));
    exit;
}

$row = one(
    'SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
     FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
     WHERE a.id = ?',
    array($id)
);
if (!$row) {
    pv_fail('文章不存在（可能已被删除）');
}
echo pv_strip_view_counter(fe_render_article($row));
