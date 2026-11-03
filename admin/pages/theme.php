<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 模板管理：切换模板 / 在线编辑模板文件 */

$themesDir = CMS_THEMES;
$themes    = array();
foreach ((array) glob($themesDir . '/*', GLOB_ONLYDIR) as $d) {
    // PHP 模板主题以 index.php 为入口，标签模板主题以 index.html 为入口
    if (is_file($d . '/index.php') || is_file($d . '/index.html')) {
        $themes[] = basename($d);
    }
}

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'switch') {
        $t = post_str('theme');
        if (in_array($t, $themes, true)) {
            set_setting('theme', $t);
            if (setting('auto_build', '1') === '1') {
                build_all(8.0);
            }
            flash('模板已切换为「' . $t . '」');
        } else {
            flash('模板不存在', 'err');
        }
        redirect(admin_url('theme'));
    }

    if ($do === 'savefile') {
        $file    = post_str('file');
        $content = (string) post_str('content');
        if (!preg_match('/^[A-Za-z0-9_\-]+\.(php|css|js|html|txt)$/', $file)) {
            flash('文件名不合法', 'err');
            redirect(admin_url('theme'));
        }
        $path     = theme_path($file);
        $realBase = realpath($themesDir);
        $realFile = realpath($path);
        if (!$realBase || !$realFile || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
            flash('文件不在模板目录内', 'err');
            redirect(admin_url('theme'));
        }

        ensure_dir(CMS_DATA . '/backup/theme');
        @copy($path, CMS_DATA . '/backup/theme/' . theme_name() . '-' . $file . '.' . date('YmdHis') . '.bak');

        if (write_file_atomic($path, $content)) {
            if (setting('auto_build', '1') === '1') {
                build_all(8.0);
            }
            flash('模板文件已保存（原文件已备份到 content/data/backup/theme）');
        } else {
            flash('保存失败：文件不可写，请检查模板目录权限', 'err');
        }
        redirect(admin_url('theme', array('file' => $file)));
    }
}

$files = array();
foreach ((array) glob(theme_path('*')) as $f) {
    if (is_file($f)) {
        $files[] = basename($f);
    }
}
sort($files);

$editFile = get_str('file', 'index.html');
if (!in_array($editFile, $files, true)) {
    $editFile = in_array('index.html', $files, true)
        ? 'index.html'
        : (in_array('index.php', $files, true) ? 'index.php' : (isset($files[0]) ? $files[0] : ''));
}
$isTagTheme = is_file(theme_path('index.html'));
$content = $editFile !== '' ? (string) @file_get_contents(theme_path($editFile)) : '';
?>
<div class="edit-cols">
    <div class="card">
        <div class="card-head">
            <h2>编辑模板文件<?php echo $editFile !== '' ? '：' . e($editFile) : ''; ?></h2>
            <?php if ($editFile !== ''): ?>
                <a class="btn btn-sm" target="_blank" href="<?php echo e(theme_url($editFile)); ?>">在浏览器查看</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($editFile === ''): ?>
                <div class="empty">当前模板目录没有可编辑文件</div>
            <?php else: ?>
                <form method="post" action="<?php echo e(admin_url('theme')); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="savefile">
                    <input type="hidden" name="file" value="<?php echo e($editFile); ?>">
                    <textarea class="input" name="content" style="min-height:520px" spellcheck="false"><?php echo e($content); ?></textarea>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">保存文件</button>
                        <span class="hint">
                            <?php if ($isTagTheme): ?>
                                模板是纯 HTML，{cici:xxx} 是调用系统数据的标签，照抄改字即可，不用写 PHP。
                            <?php else: ?>
                                当前模板含 PHP 文件，修改前请确认语法正确（出错会导致整站白屏）。
                            <?php endif; ?>
                            保存前会自动备份原文件。
                        </span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="edit-side">
        <div class="card">
            <div class="card-head"><h2>可用模板</h2></div>
            <div class="card-body">
                <?php foreach ($themes as $t): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--line)">
                        <span>
                            <?php echo e($t); ?>
                            <?php if (is_file($themesDir . '/' . $t . '/index.html')): ?>
                                <span class="badge badge-green">HTML 标签模板</span>
                            <?php else: ?>
                                <span class="badge badge-amber">PHP 模板</span>
                            <?php endif; ?>
                            <?php if ($t === theme_name()): ?><span class="badge badge-blue">使用中</span><?php endif; ?>
                        </span>
                        <?php if ($t !== theme_name()): ?>
                            <form method="post" action="<?php echo e(admin_url('theme')); ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="do" value="switch">
                                <input type="hidden" name="theme" value="<?php echo e($t); ?>">
                                <button class="btn btn-sm" type="submit">启用</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($themes)): ?>
                    <div class="hint">未检测到模板，请确认 content/themes/default 是否存在。</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>模板文件</h2></div>
            <div class="card-body tight">
                <table class="table">
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td><a href="<?php echo e(admin_url('theme', array('file' => $f))); ?>"><?php echo e($f); ?><?php echo $f === $editFile ? ' <span class="badge badge-blue">编辑中</span>' : ''; ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
</div>
