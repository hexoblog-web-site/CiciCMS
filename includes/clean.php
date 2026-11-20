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
function CMS_clean_skip($rel)
{
    $base = basename($rel);
    if ($base === '' || $base[0] === '.') {
        return true;
    }
    return in_array(strtolower($base), array('index.html', 'index.htm'), true);
}

/** 没有入库记录的文件，按后缀判断是不是图片（附件目录里全是 pdf / zip 这些） */
function CMS_clean_is_image_name($rel)
{
    $ext = strtolower(pathinfo((string) $rel, PATHINFO_EXTENSION));
    return in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'avif'), true);
}

/**
 * 建立"引用索引"：把可能提到图片的所有文本收集起来
 *
 * @return array 每项：type 来源类型 / label 来源名称 / text 文本
 */
function CMS_clean_index()
{
    $idx = array();

    try {
        // attachment 列是 v7 才加的：老库还没升级时不能让它把整段索引带崩
        $hasAttach = !empty(all("SHOW COLUMNS FROM {p}article LIKE 'attachment'"));
        $cols = 'id, title, content, content_src, excerpt, cover' . ($hasAttach ? ', attachment' : '');
        foreach (all('SELECT ' . $cols . ' FROM {p}article') as $r) {
            $idx[] = array(
                'type'  => '文章',
                'label' => '#' . (int) $r['id'] . ' ' . str_limit($r['title'], 30),
                'text'  => (string) $r['content'] . "\n" . (string) $r['content_src']
                         . "\n" . (string) $r['excerpt'] . "\n" . (string) $r['cover']
                         . "\n" . (isset($r['attachment']) ? (string) $r['attachment'] : ''),
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
 * @param array  $index CMS_clean_index() 的结果
 * @return array 引用来源列表，空数组表示没被引用
 */
function CMS_clean_refs($rel, $index)
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
function CMS_clean_is_used($rel)
{
    return CMS_clean_refs($rel, CMS_clean_index()) !== array();
}

/**
 * 扫描 uploads 目录，逐个标注是否被引用
 *
 * @return array files 列表 / total 总数 / used 已引用 / unused 未引用 / bytes 可释放 / missing 记录失效数
 */
function CMS_clean_scan()
{
    $index   = CMS_clean_index();
    $records = array();
    /* deleted_at 是 v8 才加的：老库没升级时不能因为这一列把整段扫描带崩 */
    $hasDel  = !empty(all("SHOW COLUMNS FROM {p}attachment LIKE 'deleted_at'"));
    $cols    = 'id, path, name, mime, size, created_at' . ($hasDel ? ', deleted_at' : '');
    foreach (all('SELECT ' . $cols . ' FROM {p}attachment') as $r) {
        $rel = upload_rel($r['path']);
        if ($rel !== '') {
            $records[$rel] = $r;
        }
    }

    /* 回收站里的文件由「媒体 → 回收站」负责删除，这里一律当作「有人管」，
       否则扫描页会把它们列成可删项，用户顺手清掉后回收站就恢复不出来了 */
    $trashRef = array(array('type' => '回收站', 'label' => '媒体回收站'));
    $inTrash  = function ($rec) use ($hasDel) {
        return $hasDel && is_array($rec) && isset($rec['deleted_at']) && (int) $rec['deleted_at'] > 0;
    };

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
                if ($rel === '' || strpos($rel, '..') !== false || CMS_clean_skip($rel)) {
                    continue;
                }
                $rec   = isset($records[$rel]) ? $records[$rel] : null;
                $trash = $inTrash($rec);
                $refs  = $trash ? $trashRef : CMS_clean_refs($rel, $index);
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
                    'trash'    => $trash,
                    'is_image' => $rec ? strpos((string) $rec['mime'], 'image/') === 0 : CMS_clean_is_image_name($rel),
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
        $trash = $inTrash($rec);
        $files[$rel] = array(
            'rel'      => $rel,
            'url'      => upload_url(CMS_CONTENT_REL . '/uploads/' . $rel),
            'name'     => $rec['name'],
            'mime'     => $rec['mime'],
            'size'     => (int) $rec['size'],
            'mtime'    => 0,
            'id'       => (int) $rec['id'],
            'missing'  => true,
            'used'     => $trash,
            'refs'     => $trash ? $trashRef : array(),
            'trash'    => $trash,
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
function CMS_clean_forget_record($rel)
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
function CMS_clean_delete_many($paths)
{
    $index = CMS_clean_index();
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

    /* 回收站里的文件不在这条链路删：删了回收站就恢复不出来了 */
    $trash = array();
    if (!empty(all("SHOW COLUMNS FROM {p}attachment LIKE 'deleted_at'"))) {
        foreach (all('SELECT path FROM {p}attachment WHERE deleted_at > 0') as $r) {
            $k = upload_rel($r['path']);
            if ($k !== '') {
                $trash[$k] = true;
            }
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
        if (isset($trash[$rel])) {
            $skip[] = $rel;
            continue;
        }
        // 文件本来就不在：有入库记录才清掉，否则说明这是个无效路径，不算成功
        if (!is_file($abs)) {
            if (isset($known[$rel])) {
                CMS_clean_forget_record($rel);
                $ok++;
            } else {
                $fail[] = $rel;
            }
            continue;
        }
        if (CMS_clean_refs($rel, $index) !== array()) {
            $skip[] = $rel;
            continue;
        }
        if (!@unlink($abs)) {
            $fail[] = $rel;
            continue;
        }
        CMS_clean_forget_record($rel);
        $ok++;
    }

    return array('ok' => $ok, 'skip' => $skip, 'fail' => $fail);
}
