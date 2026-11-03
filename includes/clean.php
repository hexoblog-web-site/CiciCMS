<?php
/**
 * 系统清理：找出没有被任何内容引用的图片与附件
 *
 * 判定原则：**宁可漏清理，也不能误删**。
 * 所以引用检查用的是"文件名是否出现在任何正文里"这种宽松匹配——
 * 只要有一处提到，就算被引用。本系统生成的文件名带时间戳和随机串，
 * 撞名的概率可以忽略。
 *
 * 检查范围：文章（正文 / 编辑源文 / 摘要 / 封面）、单页、栏目横幅、
 * 友情链接 Logo、系统设置里的全部配置值、当前主题的模板文件。
 *
 * 文件：includes/clean.php
 */

/** 跳过这些文件：目录保护文件与占位页，不算附件 */
function cms_clean_skip($rel)
{
    $base = basename($rel);
    if ($base === '' || $base[0] === '.') {
        return true;
    }
    return in_array(strtolower($base), array('index.html', 'index.htm'), true);
}

/**
 * 建立"引用索引"：把可能提到图片的所有文本收集起来
 *
 * @return array 每项：type 来源类型 / label 来源名称 / text 文本
 */
function cms_clean_index()
{
    $idx = array();

    try {
        foreach (all('SELECT id, title, content, content_src, excerpt, cover FROM {p}article') as $r) {
            $idx[] = array(
                'type'  => '文章',
                'label' => '#' . (int) $r['id'] . ' ' . str_limit($r['title'], 30),
                'text'  => (string) $r['content'] . "\n" . (string) $r['content_src']
                         . "\n" . (string) $r['excerpt'] . "\n" . (string) $r['cover'],
            );
        }
    } catch (Throwable $ex) {
        log_msg('clean: 读取文章失败 ' . $ex->getMessage());
    }

    try {
        foreach (all('SELECT id, title, content FROM {p}page') as $r) {
            $idx[] = array(
                'type'  => '单页',
                'label' => '#' . (int) $r['id'] . ' ' . str_limit($r['title'], 30),
                'text'  => (string) $r['content'],
            );
        }
    } catch (Throwable $ex) {
        log_msg('clean: 读取单页失败 ' . $ex->getMessage());
    }

    try {
        foreach (all('SELECT id, name, banner FROM {p}category') as $r) {
            $idx[] = array(
                'type'  => '栏目',
                'label' => $r['name'],
                'text'  => (string) $r['banner'],
            );
        }
    } catch (Throwable $ex) {
        log_msg('clean: 读取栏目失败 ' . $ex->getMessage());
    }

    try {
        foreach (all('SELECT id, name, logo FROM {p}flink') as $r) {
            $idx[] = array(
                'type'  => '友链',
                'label' => $r['name'],
                'text'  => (string) $r['logo'],
            );
        }
    } catch (Throwable $ex) {
        log_msg('clean: 读取友链失败 ' . $ex->getMessage());
    }

    try {
        foreach (all('SELECT k, v FROM {p}config') as $r) {
            $idx[] = array(
                'type'  => '系统设置',
                'label' => $r['k'],
                'text'  => (string) $r['v'],
            );
        }
    } catch (Throwable $ex) {
        log_msg('clean: 读取配置失败 ' . $ex->getMessage());
    }

    // 模板里可能硬编码了图片地址，一并算作引用
    $dir = theme_path();
    foreach ((array) glob($dir . '/*.{php,html,htm}', GLOB_BRACE) as $f) {
        $idx[] = array(
            'type'  => '模板',
            'label' => basename($f),
            'text'  => (string) @file_get_contents($f),
        );
    }

    return $idx;
}

/**
 * 某个附件被哪些地方引用
 *
 * @param string $rel   相对 uploads 的路径，如 2026/11/a.png
 * @param array  $index cms_clean_index() 的结果
 * @return array 引用来源列表，空数组表示没被引用
 */
function cms_clean_refs($rel, $index)
{
    $name = basename($rel);
    if ($name === '') {
        return array();
    }
    $enc  = rawurlencode($name);
    $hits = array();
    foreach ($index as $item) {
        $text = $item['text'];
        if ($text === '') {
            continue;
        }
        if (strpos($text, $name) !== false || ($enc !== $name && strpos($text, $enc) !== false)) {
            $hits[] = array('type' => $item['type'], 'label' => $item['label']);
        }
    }
    return $hits;
}

/** 是否被引用（删除前复核用） */
function cms_clean_is_used($rel)
{
    return cms_clean_refs($rel, cms_clean_index()) !== array();
}

/**
 * 扫描 uploads 目录，逐个标注是否被引用
 *
 * @return array files 列表 / total 总数 / used 已引用 / unused 未引用 / bytes 可释放 / missing 记录失效数
 */
function cms_clean_scan()
{
    $index   = cms_clean_index();
    $records = array();
    foreach (all('SELECT id, path, name, mime, size, created_at FROM {p}attachment') as $r) {
        $rel = upload_rel($r['path']);
        if ($rel !== '') {
            $records[$rel] = $r;
        }
    }

    $files = array();
    $root  = CMS_UPLOADS;

    // 1) 磁盘上真实存在的文件
    if (is_dir($root)) {
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $abs = str_replace('\\', '/', $f->getPathname());
                $rel = ltrim(substr($abs, strlen(str_replace('\\', '/', $root))), '/');
                if ($rel === '' || strpos($rel, '..') !== false || cms_clean_skip($rel)) {
                    continue;
                }
                $rec = isset($records[$rel]) ? $records[$rel] : null;
                $refs = cms_clean_refs($rel, $index);
                $files[$rel] = array(
                    'rel'      => $rel,
                    'url'      => upload_url(CMS_CONTENT_REL . '/uploads/' . $rel),
                    'name'     => $rec ? $rec['name'] : basename($rel),
                    'mime'     => $rec ? $rec['mime'] : '',
                    'size'     => (int) $f->getSize(),
                    'mtime'    => (int) $f->getMTime(),
                    'id'       => $rec ? (int) $rec['id'] : 0,
                    'missing'  => false,
                    'used'     => $refs !== array(),
                    'refs'     => $refs,
                    'is_image' => $rec ? strpos((string) $rec['mime'], 'image/') === 0 : true,
                );
            }
        } catch (Throwable $ex) {
            log_msg('clean: 扫描目录失败 ' . $ex->getMessage());
        }
    }

    // 2) 有记录但文件已不在（多数是手工删了文件、或迁移没带过来）
    foreach ($records as $rel => $rec) {
        if (isset($files[$rel])) {
            continue;
        }
        $files[$rel] = array(
            'rel'      => $rel,
            'url'      => upload_url(CMS_CONTENT_REL . '/uploads/' . $rel),
            'name'     => $rec['name'],
            'mime'     => $rec['mime'],
            'size'     => (int) $rec['size'],
            'mtime'    => 0,
            'id'       => (int) $rec['id'],
            'missing'  => true,
            'used'     => false,
            'refs'     => array(),
            'is_image' => strpos((string) $rec['mime'], 'image/') === 0,
        );
    }

    // 未引用在前，未引用里再按占用从大到小
    uasort($files, function ($a, $b) {
        if ($a['used'] !== $b['used']) {
            return $a['used'] ? 1 : -1;
        }
        return $b['size'] - $a['size'];
    });

    $total = count($files);
    $used  = 0;
    $bytes = 0;
    $miss  = 0;
    foreach ($files as $f) {
        if ($f['used']) {
            $used++;
        } else {
            $bytes += $f['size'];
        }
        if ($f['missing']) {
            $miss++;
        }
    }

    return array(
        'files'   => array_values($files),
        'total'   => $total,
        'used'    => $used,
        'unused'  => $total - $used,
        'bytes'   => $bytes,
        'missing' => $miss,
    );
}

/** 删除某附件的入库记录 */
function cms_clean_forget_record($rel)
{
    foreach (all('SELECT id, path FROM {p}attachment') as $r) {
        if (upload_rel($r['path']) === $rel) {
            delete_row('attachment', 'id = ?', array((int) $r['id']));
        }
    }
}

/**
 * 批量删除（删文件 + 删记录）
 *
 * 删除前会用最新的引用索引再复核一次：扫描页面可能开着很久，
 * 期间用户可能刚把这张图插进文章，这时必须跳过。
 *
 * @param array $paths 相对 uploads 的路径列表
 * @return array ok 成功 / skip 已引用被跳过 / fail 失败
 */
function cms_clean_delete_many($paths)
{
    $index = cms_clean_index();
    $ok    = 0;
    $skip  = array();
    $fail  = array();

    // 当前所有入库记录，用来判断"文件不在时该不该删记录"
    $known = array();
    foreach (all('SELECT path FROM {p}attachment') as $r) {
        $k = upload_rel($r['path']);
        if ($k !== '') {
            $known[$k] = true;
        }
    }

    foreach ((array) $paths as $p) {
        $raw = str_replace('\\', '/', trim((string) $p));
        // 只接受 uploads 内的相对路径：绝对路径与 .. 一律拒绝
        if ($raw === '' || $raw[0] === '/' || strpos($raw, '..') !== false) {
            $fail[] = (string) $p;
            continue;
        }

        // 两种写法都支持：2026/11/a.png 与 content/uploads/2026/11/a.png
        $rel = upload_rel($raw);
        if ($rel === '') {
            $rel = upload_rel(CMS_CONTENT_REL . '/uploads/' . $raw);
        }
        if ($rel === '') {
            $fail[] = (string) $p;
            continue;
        }

        $abs = upload_path($rel);
        // 文件本来就不在：有入库记录才清掉，否则说明这是个无效路径，不算成功
        if (!is_file($abs)) {
            if (isset($known[$rel])) {
                cms_clean_forget_record($rel);
                $ok++;
            } else {
                $fail[] = $rel;
            }
            continue;
        }
        if (cms_clean_refs($rel, $index) !== array()) {
            $skip[] = $rel;
            continue;
        }
        if (!@unlink($abs)) {
            $fail[] = $rel;
            continue;
        }
        cms_clean_forget_record($rel);
        $ok++;
    }

    return array('ok' => $ok, 'skip' => $skip, 'fail' => $fail);
}
