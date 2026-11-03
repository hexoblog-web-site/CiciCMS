<?php
/**
 * HtmlCms 静态生成器
 * 输出：首页/分页、分类、文章、单页、标签、404、sitemap.xml、rss.xml
 *
 * 设计要点：
 * 1. 生成任务被拆成"任务清单 + 游标"，可以分多次请求执行（build_tick），
 *    因此文章再多也不会因为单个请求超时（max_execution_time）而中断。
 * 2. 只有"整轮全量生成无任何错误"时才清理旧文件；中途失败只会保留旧站，
 *    绝不会出现删空站点的情况。
 * 3. 保存单篇文章走增量生成（build_incremental），只重建受影响的页面。
 */

function static_manifest_file()
{
    return CMS_DATA . '/static_manifest.json';
}

function build_state_file()
{
    return CMS_DATA . '/build_state.json';
}

/** 单页输出到站点根目录，这些名字已被系统占用，不能用作单页 slug */
function build_reserved_slugs()
{
    return array('index', 'tags', 'archive', 'links', '404', 'rss', 'sitemap', 'page', 'cat', 'post', 'tag');
}

/** 允许生成的静态文件后缀：可写区绝不能产出可执行文件（攻击者拿到写权限也变不成 webshell） */
function build_allowed_ext()
{
    return array('html', 'htm', 'xml', 'txt', 'json');
}

function build_put($rel, $html, &$files, &$errors)
{
    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        build_err($errors, '非法输出路径：' . $rel);
        return false;
    }
    // 程序目录只读是推荐部署方式，生成器必须自己保证不往那里写。
    // 正常数据走不到这里，只有栏目别名被直接改库改成 admin/content 才会命中。
    if (cms_is_system_rel($rel)) {
        build_err($errors, '拒绝写入系统目录：' . $rel . '（请把栏目别名改成普通名称）');
        return false;
    }
    if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), build_allowed_ext(), true)) {
        build_err($errors, '拒绝生成可执行文件：' . $rel);
        return false;
    }
    // 空内容一律视为失败：标签模板编译失败、缓存目录不可写时都会走到这里，
    // 若不拦，会一边写空文件一边报告「生成成功」，最后把旧页面全清理掉。
    if (trim((string) $html) === '') {
        build_err($errors, '生成内容为空：' . $rel . '（模板编译失败或缓存目录不可写）');
        log_msg('build empty output: ' . $rel);
        return false;
    }
    if (write_file_atomic(CMS_ROOT . '/' . $rel, $html)) {
        if (!isset($GLOBALS['build_file_seen'][$rel])) {
            $GLOBALS['build_file_seen'][$rel] = 1;
            $files[] = $rel;
        }
        return true;
    }
    build_err($errors, '写入失败：' . $rel . '（请检查目录写入权限）');
    return false;
}

/** 错误信息限流，避免目录不可写时产生上万条记录 */
function build_err(&$errors, $msg)
{
    if (count($errors) < 200) {
        $errors[] = $msg;
    }
}

/* ------------------------------------------------------------------ *
 * 任务清单
 * ------------------------------------------------------------------ */

/** 全量任务清单：只存"要渲染什么"，渲染时再查最新数据 */
function build_plan_all()
{
    $plan = array();
    $per  = fe_per_page();

    $total = fe_count('a.status = 1');
    $pages = max(1, (int) ceil($total / $per));
    for ($p = 1; $p <= $pages; $p++) {
        $plan[] = array('home', $p);
    }

    foreach (fe_cats() as $cat) {
        $t  = fe_count('a.status = 1 AND a.cat_id = ?', array((int) $cat['id']));
        $cs = max(1, (int) ceil($t / $per));
        for ($p = 1; $p <= $cs; $p++) {
            $plan[] = array('cat', (int) $cat['id'], $p);
        }
    }

    foreach (all('SELECT id FROM {p}article WHERE status = 1 ORDER BY id ASC') as $r) {
        $plan[] = array('post', (int) $r['id']);
    }
    foreach (all('SELECT id FROM {p}page WHERE status = 1 ORDER BY sort ASC, id ASC') as $r) {
        $plan[] = array('page', (int) $r['id']);
    }

    foreach (fe_tags_all() as $name => $cnt) {
        $plan[] = array('tag', $name);
    }
    $plan[] = array('tags');
    $plan[] = array('archive');
    $plan[] = array('links');
    $plan[] = array('404');
    $plan[] = array('sitemap');
    $plan[] = array('rss');

    return $plan;
}

/**
 * 增量任务清单：保存/删除一条内容后，只重建受影响的页面。
 * @param array $opts post_id / page_id / cat_ids / tags / no_home
 */
function build_plan_incremental($opts = array())
{
    $plan = array();
    $per  = fe_per_page();

    // 内容自身排在最前：即使本批预算用完，刚保存的文章页也已经写好了
    if (!empty($opts['post_id'])) {
        $plan[] = array('post', (int) $opts['post_id']);
    }
    if (!empty($opts['page_id'])) {
        $plan[] = array('page', (int) $opts['page_id']);
    }

    if (empty($opts['no_home'])) {
        $total = fe_count('a.status = 1');
        $pages = max(1, (int) ceil($total / $per));
        for ($p = 1; $p <= $pages; $p++) {
            $plan[] = array('home', $p);
        }
    }

    $catIds = isset($opts['cat_ids']) ? (array) $opts['cat_ids'] : array();
    foreach (array_unique($catIds) as $cid) {
        $cid = (int) $cid;
        if ($cid <= 0) {
            continue;
        }
        $t  = fe_count('a.status = 1 AND a.cat_id = ?', array($cid));
        $cs = max(1, (int) ceil($t / $per));
        for ($p = 1; $p <= $cs; $p++) {
            $plan[] = array('cat', $cid, $p);
        }
    }

    foreach ((array) (isset($opts['tags']) ? $opts['tags'] : array()) as $tg) {
        if (trim((string) $tg) !== '') {
            $plan[] = array('tag', (string) $tg);
        }
    }

    $plan[] = array('tags');
    $plan[] = array('archive');
    $plan[] = array('links');
    $plan[] = array('sitemap');
    $plan[] = array('rss');

    return $plan;
}

/* ------------------------------------------------------------------ *
 * 任务执行
 * ------------------------------------------------------------------ */

/** 供 sitemap/rss 复用，避免每个任务重复查全表 */
function build_posts_all($refresh = false)
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = all('SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
                      FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                      WHERE a.status = 1 ORDER BY a.id ASC');
    }
    return $cache;
}

function build_pages_all($refresh = false)
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = all('SELECT * FROM {p}page WHERE status = 1 ORDER BY sort ASC, id ASC');
    }
    return $cache;
}

/**
 * 执行一个任务，成功返回写入的相对路径，跳过返回 null。
 * 抛异常由调用方捕获。
 */
function build_exec_task($task, &$files, &$errors)
{
    $kind = isset($task[0]) ? $task[0] : '';

    switch ($kind) {
        case 'home':
            $p   = max(1, (int) $task[1]);
            $rel = ($p === 1) ? 'index.html' : 'page/' . $p . '/index.html';
            return build_put($rel, fe_render_home($p), $files, $errors);

        case 'cat':
            $cat = one('SELECT * FROM {p}category WHERE id = ?', array((int) $task[1]));
            if (!$cat) {
                return null;
            }
            $dir = cat_dir_of($cat);
            if (in_array($dir, cms_reserved_dirs(), true)) {
                build_err($errors, '分类「' . $cat['name'] . '」的目录名 ' . $dir . ' 与系统目录冲突，已跳过，请换一个');
                return null;
            }
            $p   = max(1, (int) $task[2]);
            $rel = ($p === 1)
                ? $dir . '/index.html'
                : $dir . '/page/' . $p . '/index.html';
            return build_put($rel, fe_render_category($cat, $p), $files, $errors);

        case 'post':
            $row = one('SELECT a.*, c.name AS cat_name, c.dirname AS cat_dir
                        FROM {p}article a LEFT JOIN {p}category c ON c.id = a.cat_id
                        WHERE a.id = ? AND a.status = 1', array((int) $task[1]));
            if (!$row) {
                return null;
            }
            if ((string) $row['slug'] === '') {
                build_err($errors, '文章 #' . (int) $row['id'] . ' 缺少 slug，已跳过');
                return null;
            }
            $rel = post_static_rel($row);
            if ($rel === '') {
                build_err($errors, '文章 #' . (int) $row['id'] . ' 无法确定输出路径，已跳过');
                return null;
            }
            return build_put($rel, fe_render_article($row), $files, $errors);

        case 'page':
            $row = one('SELECT * FROM {p}page WHERE id = ? AND status = 1', array((int) $task[1]));
            if (!$row || (string) $row['slug'] === '') {
                return null;
            }
            if (in_array(strtolower($row['slug']), build_reserved_slugs(), true)) {
                build_err($errors, '单页「' . $row['title'] . '」的路径 ' . $row['slug'] . ' 与系统页面冲突，已跳过，请换一个路径');
                return null;
            }
            return build_put($row['slug'] . '.html', fe_render_page($row), $files, $errors);

        case 'tag':
            $name = (string) $task[1];
            return build_put('tag/' . tag_slug($name) . '.html', fe_render_tag($name), $files, $errors);

        case 'tags':
            return build_put('tags.html', fe_render_tags_index(), $files, $errors);

        case 'archive':
            return build_put('archive.html', fe_render_archive(), $files, $errors);

        case 'links':
            return build_put('links.html', fe_render_links(), $files, $errors);

        case '404':
            return build_put('404.html', fe_render_404(), $files, $errors);

        case 'sitemap':
            return build_put('sitemap.xml', build_sitemap(build_posts_all(), build_pages_all()), $files, $errors);

        case 'rss':
            return build_put('rss.xml', build_rss(build_posts_all()), $files, $errors);
    }

    return null;
}

/* ------------------------------------------------------------------ *
 * 任务状态机：start / tick / finish
 * ------------------------------------------------------------------ */

function build_read_state()
{
    $file = build_state_file();
    if (!is_file($file)) {
        return null;
    }
    $arr = json_decode((string) @file_get_contents($file), true);
    if (!is_array($arr) || !isset($arr['plan']) || !is_array($arr['plan'])) {
        return null;
    }
    return $arr;
}

function build_write_state($state)
{
    $state['updated'] = time();
    @write_file_atomic(build_state_file(), json_encode($state));
}

function build_clear_state()
{
    $file = build_state_file();
    if (is_file($file)) {
        @unlink($file);
    }
}

/** 是否有未完成的生成任务 */
function build_pending()
{
    $st = build_read_state();
    if (!$st) {
        return null;
    }
    $total = count($st['plan']);
    $pos   = (int) $st['pos'];
    if ($pos >= $total) {
        return null;
    }
    // 超过 1 小时没有进展的任务视为已失效，直接清掉
    if (time() - (int) $st['updated'] > 3600) {
        build_clear_state();
        return null;
    }
    return array('pos' => $pos, 'total' => $total, 'mode' => $st['mode']);
}

/**
 * 初始化一轮生成。
 * 已经有未完成的任务时不会报错阻塞，而是按下面的规则接着来：
 *  - 新任务是全量：直接替换（全量本来就覆盖一切）；
 *  - 旧任务是增量：直接替换（增量计划幂等，重复替换不会漏页面）；
 *  - 旧任务是全量、新任务是增量：追加到队尾，保证全量剩下的部分不被丢掉。
 *
 * @param string $mode full|inc
 * @param array  $plan 任务清单
 * @return array ok/msg/total
 */
function build_start($mode, $plan)
{
    $plan = array_values((array) $plan);
    if (empty($plan)) {
        return array('ok' => false, 'msg' => '没有需要生成的内容', 'total' => 0);
    }

    $lock = @fopen(CMS_DATA . '/build.lock', 'c');
    if (!$lock) {
        return array('ok' => false, 'msg' => '无法创建生成锁文件，请检查 data 目录权限', 'total' => 0);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return array('ok' => false, 'msg' => '已有生成任务正在运行，请稍后再试', 'total' => 0);
    }

    try {
        $running    = build_read_state();
        $incomplete = $running && (int) $running['pos'] < count($running['plan']);

        if ($incomplete && $mode !== 'full' && $running['mode'] !== 'inc') {
            // 全量还没跑完，又有新的增量任务：插到当前位置，让刚保存的内容先出来
            array_splice($running['plan'], (int) $running['pos'], 0, $plan);
            build_write_state($running);
            return array('ok' => true, 'msg' => '', 'total' => count($running['plan']), 'appended' => true);
        }

        $state = array(
            'mode'    => $mode === 'inc' ? 'inc' : 'full',
            'plan'    => $plan,
            'pos'     => 0,
            'files'   => $incomplete ? (array) $running['files'] : array(),
            'errors'  => $incomplete ? (array) $running['errors'] : array(),
            'fatal'   => $incomplete ? (int) $running['fatal'] : 0,
            'started' => microtime(true),
            'updated' => time(),
        );
        build_write_state($state);

        return array('ok' => true, 'msg' => '', 'total' => count($plan));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * 执行一批任务，到时间预算就返回，由下一次请求接着做。
 * @param float $budgetSec 本批最多占用的秒数
 */
function build_tick($budgetSec = 8.0)
{
    $lock = @fopen(CMS_DATA . '/build.lock', 'c');
    if (!$lock) {
        return array('ok' => false, 'finished' => false, 'busy' => true, 'pos' => 0, 'total' => 0,
                     'files' => 0, 'errors' => array('无法创建生成锁文件，请检查 data 目录权限'));
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return array('ok' => false, 'finished' => false, 'busy' => true, 'pos' => 0, 'total' => 0,
                     'files' => 0, 'errors' => array());
    }

    // 拿到锁之后再读状态，避免读到被别人替换掉的旧队列
    $state = build_read_state();
    if (!$state) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => true, 'finished' => true, 'pos' => 0, 'total' => 0,
                     'files' => 0, 'errors' => array(), 'msg' => '没有待执行的任务');
    }

    $GLOBALS['cms_static_mode'] = true;
    $files  = (array) $state['files'];
    $errors = (array) $state['errors'];
    $GLOBALS['build_file_seen'] = $files ? array_flip($files) : array();
    $fatal  = (int) $state['fatal'];
    $pos    = (int) $state['pos'];
    $total  = count($state['plan']);
    $t0     = microtime(true);

    try {
        while ($pos < $total) {
            try {
                build_exec_task($state['plan'][$pos], $files, $errors);
            } catch (Throwable $ex) {
                // 单个任务出错不中断整轮，但记下致命标记：本轮不再清理旧文件
                $fatal = 1;
                build_err($errors, '生成中断：' . $ex->getMessage());
                log_msg('build task error: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
            }
            $pos++;
            if ((microtime(true) - $t0) >= $budgetSec) {
                break;
            }
        }

        $state['pos']    = $pos;
        $state['files']  = $files;
        $state['errors'] = $errors;
        $state['fatal']  = $fatal;

        // 状态必须在放锁之前落盘。原实现先解锁再写状态，中间存在一个窗口：
        // 另一个请求的 build_start 可以在这时把增量任务插进队列并写入，
        // 随后被本次用旧 plan 覆盖，导致刚保存的文章页没有重建。
        $finished = ($pos >= $total);
        if ($finished) {
            build_finish($state);
        } else {
            build_write_state($state);
        }
    } finally {
        $GLOBALS['cms_static_mode'] = false;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return array(
        'ok'       => empty($errors),
        'finished' => $finished,
        'pos'      => $pos,
        'total'    => $total,
        'files'    => count($files),
        'errors'   => array_slice($errors, 0, 5),
        'ms'       => (int) round((microtime(true) - $t0) * 1000),
    );
}

/** 收尾：合并 manifest、必要时清理旧文件、写统计 */
function build_finish($state)
{
    $files  = array_values(array_unique((array) $state['files']));
    $errors = (array) $state['errors'];
    $ok     = empty($errors);
    $old    = build_read_manifest();

    if ($state['mode'] === 'full' && $ok) {
        // 整轮全量成功：清理上一轮遗留、本轮没有生成的文件
        build_cleanup($old, $files);
        build_write_manifest($files);
    } else {
        // 增量、或有任何错误：只做合并，绝不删除任何已存在的文件
        build_write_manifest(array_values(array_unique(array_merge($old, $files))));
    }

    $ms = (int) round((microtime(true) - (float) $state['started']) * 1000);
    set_setting('last_build', time());
    set_setting('last_build_files', count($files));
    set_setting('last_build_ms', $ms);
    set_setting('build_pending', '0');

    if (!$ok) {
        log_msg('build finished with ' . count($errors) . ' error(s): ' . implode(' | ', array_slice($errors, 0, 5)));
    }

    build_clear_state();
}

/**
 * 跑完整轮（带时间上限）。
 * 兼容原有调用方；超过上限时任务保留在状态文件里，可到后台继续。
 */
function build_all($budgetSec = 25.0)
{
    $st     = build_read_state();
    $resume = $st && $st['mode'] === 'full' && (int) $st['pos'] < count($st['plan']);

    if (!$resume) {
        $r = build_start('full', build_plan_all());
        if (!$r['ok']) {
            return array('ok' => false, 'files' => 0, 'errors' => array($r['msg']), 'ms' => 0, 'finished' => false);
        }
    }

    $t0  = microtime(true);
    $res = null;
    do {
        $res = build_tick(min(8.0, max(1.0, $budgetSec - (microtime(true) - $t0))));
        if (!empty($res['busy'])) {
            break;
        }
    } while (!$res['finished'] && (microtime(true) - $t0) < $budgetSec);

    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($res['finished']) {
        return array('ok' => $res['ok'], 'files' => $res['files'], 'errors' => $res['errors'],
                     'ms' => $ms, 'finished' => true);
    }

    $st = build_pending();
    $left = $st ? ($st['total'] - $st['pos']) : 0;
    $errs = $res['errors'];
    $errs[] = '内容较多，本轮生成尚未完成，还剩 ' . $left . ' 项，会在后台自动继续';

    return array('ok' => false, 'files' => $res['files'], 'errors' => $errs, 'ms' => $ms,
                 'finished' => false, 'partial' => true, 'left' => $left);
}

/** 保存/删除一条内容后的增量生成（快速返回，剩余部分由后台页面自动续跑） */
function build_incremental($opts = array(), $budgetSec = 4.0)
{
    $plan = build_plan_incremental($opts);
    $r    = build_start('inc', $plan);
    if (!$r['ok']) {
        return array('ok' => false, 'files' => 0, 'errors' => array($r['msg']), 'ms' => 0, 'finished' => false);
    }

    $t0  = microtime(true);
    $res = null;
    do {
        $res = build_tick(min(8.0, max(1.0, $budgetSec - (microtime(true) - $t0))));
        if (!empty($res['busy'])) {
            break;
        }
    } while (!$res['finished'] && (microtime(true) - $t0) < $budgetSec);

    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($res['finished']) {
        return array('ok' => $res['ok'], 'files' => $res['files'], 'errors' => $res['errors'],
                     'ms' => $ms, 'finished' => true);
    }

    // 没跑完不算失败：任务留在队列里，后台页面会自动接着跑
    $st = build_pending();
    return array('ok' => empty($res['errors']), 'files' => $res['files'], 'errors' => $res['errors'],
                 'ms' => $ms, 'finished' => false, 'partial' => true,
                 'left' => $st ? ($st['total'] - $st['pos']) : 0);
}

/* ------------------------------------------------------------------ *
 * manifest 与文件清理
 * ------------------------------------------------------------------ */

function build_read_manifest()
{
    $file = static_manifest_file();
    if (!is_file($file)) {
        return array();
    }
    $list = json_decode((string) @file_get_contents($file), true);
    return is_array($list) ? array_values($list) : array();
}

function build_write_manifest($list)
{
    @write_file_atomic(static_manifest_file(), json_encode(array_values($list)));
}

/**
 * 删除"上一轮有、本轮没有"的静态文件。
 * $old 必须显式传入：调用方要先读旧清单，否则会被即将写入的新清单覆盖。
 */
function build_cleanup($old, $current)
{
    if (!is_array($old) || !$old) {
        return;
    }
    $keep = array_flip((array) $current);
    foreach ($old as $rel) {
        if (isset($keep[$rel])) {
            continue;
        }
        $rel = ltrim((string) $rel, '/');
        // 清单文件在可写的 content/data 下，万一被改脏也不允许删到程序区
        if ($rel === '' || strpos($rel, '..') !== false || cms_is_system_rel($rel)) {
            continue;
        }
        $path = CMS_ROOT . '/' . $rel;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** 从静态清单里移除指定文件并删除它们（删除文章/单页时用） */
function static_forget($rels)
{
    $rels = array_values(array_filter(array_map(function ($r) {
        return ltrim((string) $r, '/');
    }, (array) $rels), 'strlen'));
    if (!$rels) {
        return 0;
    }
    $n = 0;
    foreach ($rels as $rel) {
        $rel = ltrim((string) $rel, '/');
        // 清单文件在可写的 content/data 下，万一被改脏也不允许删到程序区
        if ($rel === '' || strpos($rel, '..') !== false || cms_is_system_rel($rel)) {
            continue;
        }
        $path = CMS_ROOT . '/' . $rel;
        if (is_file($path) && @unlink($path)) {
            $n++;
        }
    }
    $list = build_read_manifest();
    if ($list) {
        $drop = array_flip($rels);
        $left = array();
        foreach ($list as $r) {
            if (!isset($drop[$r])) {
                $left[] = $r;
            }
        }
        build_write_manifest($left);
    }
    return $n;
}

/** 清空全部已生成静态文件 */
function static_clean()
{
    $n    = 0;
    $list = build_read_manifest();
    foreach ($list as $rel) {
        $rel = ltrim((string) $rel, '/');
        // 清单文件在可写的 content/data 下，万一被改脏也不允许删到程序区
        if ($rel === '' || strpos($rel, '..') !== false || cms_is_system_rel($rel)) {
            continue;
        }
        $path = CMS_ROOT . '/' . $rel;
        if (is_file($path) && @unlink($path)) {
            $n++;
        }
    }
    $file = static_manifest_file();
    if (is_file($file)) {
        @unlink($file);
    }
    build_clear_state();
    return $n;
}

function static_files_count()
{
    return count(build_read_manifest());
}

/* ------------------------------------------------------------------ *
 * sitemap / rss
 * ------------------------------------------------------------------ */

function build_sitemap($posts, $pagesList)
{
    $urls   = array();
    $urls[] = array('loc' => u_home(1), 'lastmod' => date('Y-m-d'), 'pri' => '1.0');

    $urls[] = array('loc' => site_url('archive.html'), 'lastmod' => date('Y-m-d'), 'pri' => '0.5');
    $urls[] = array('loc' => site_url('tags.html'), 'lastmod' => date('Y-m-d'), 'pri' => '0.5');
    $urls[] = array('loc' => site_url('links.html'), 'lastmod' => date('Y-m-d'), 'pri' => '0.3');
    foreach (fe_cats() as $c) {
        $urls[] = array('loc' => u_cat($c, 1), 'lastmod' => date('Y-m-d'), 'pri' => '0.8');
    }
    foreach ((array) $pagesList as $pg) {
        $urls[] = array(
            'loc'     => u_page($pg),
            'lastmod' => date('Y-m-d', $pg['updated_at'] ? $pg['updated_at'] : $pg['created_at']),
            'pri'     => '0.6',
        );
    }
    foreach (fe_tags_all() as $tagName => $cnt) {
        $urls[] = array('loc' => u_tag($tagName), 'lastmod' => date('Y-m-d'), 'pri' => '0.4');
    }
    foreach ((array) $posts as $p) {
        $urls[] = array(
            'loc'     => u_post($p),
            'lastmod' => date('Y-m-d', $p['updated_at'] ? $p['updated_at'] : $p['created_at']),
            'pri'     => '0.9',
        );
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= '  <url><loc>' . e($u['loc']) . '</loc><lastmod>' . $u['lastmod'] . '</lastmod>'
              . '<priority>' . $u['pri'] . '</priority></url>' . "\n";
    }
    $xml .= '</urlset>' . "\n";
    return $xml;
}

function build_rss($posts)
{
    $site = fe_site();
    $list = array_slice(array_reverse((array) $posts), 0, 20);

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<rss version="2.0"><channel>' . "\n"
         . '<title>' . e($site['name']) . '</title>' . "\n"
         . '<link>' . e(site_url()) . '</link>' . "\n"
         . '<description>' . e($site['desc'] !== '' ? $site['desc'] : $site['name']) . '</description>' . "\n"
         . '<language>zh-CN</language>' . "\n"
         . '<lastBuildDate>' . date('D, d M Y H:i:s') . ' +0800</lastBuildDate>' . "\n";

    foreach ($list as $p) {
        $xml .= '<item>' . "\n"
              . '<title>' . e($p['title']) . '</title>' . "\n"
              . '<link>' . e(u_post($p)) . '</link>' . "\n"
              . '<guid isPermaLink="true">' . e(u_post($p)) . '</guid>' . "\n"
              . '<pubDate>' . date('D, d M Y H:i:s', (int) $p['created_at']) . ' +0800</pubDate>' . "\n"
              . '<description>' . e($p['excerpt'] !== '' ? $p['excerpt'] : make_excerpt($p['content'], 200)) . '</description>' . "\n"
              . '</item>' . "\n";
    }
    $xml .= '</channel></rss>' . "\n";
    return $xml;
}
