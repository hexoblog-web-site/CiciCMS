<?php
// 本文件只允许由后台入口 admin/index.php 载入，直接访问一律 404
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}

/** 数据系统：数据备份 / 数据恢复 / 数据导入 */

if (is_post()) {
    csrf_verify();
    $do = post_str('do');

    if ($do === 'backup') {
        $name = db_backup();
        if ($name) {
            flash('备份已生成：' . $name);
        } else {
            flash('备份失败，请检查 content/data/backup 目录权限', 'err');
        }
        redirect(admin_url('data'));
    }

    if ($do === 'backup_delete') {
        $name = post_str('file');
        if (db_backup_delete($name)) {
            flash('已删除备份：' . $name);
        } else {
            flash('删除失败，文件可能已经不存在', 'err');
        }
        redirect(admin_url('data'));
    }

    /* 从已有备份恢复：必须手打 RESTORE，防止误点 */
    if ($do === 'restore') {
        if (post_str('confirm') !== 'RESTORE') {
            flash('请输入 RESTORE 确认恢复，本次未执行', 'err');
            redirect(admin_url('data') . '#restore');
        }
        $path = db_backup_find(post_str('file'));
        if ($path === '') {
            flash('备份文件不存在', 'err');
            redirect(admin_url('data') . '#restore');
        }
        $info = array();
        $ok   = db_restore_file($path, $info);
        if ($ok) {
            flash('恢复完成：执行 ' . (int) $info['executed'] . ' 条语句'
                . ($info['skipped'] ? '，忽略 ' . (int) $info['skipped'] . ' 条非备份语句' : '')
                . ($info['backup'] !== '' ? '；恢复前的数据已备份为 ' . $info['backup'] : ''));
        } else {
            flash($info['error'] !== '' ? $info['error'] : '恢复失败', 'err');
        }
        redirect(admin_url('data') . '#restore');
    }

    /* 数据导入：上传 .sql 覆盖当前库 */
    if ($do === 'import') {
        if (post_str('confirm') !== 'RESTORE') {
            flash('请输入 RESTORE 确认导入，本次未执行', 'err');
            redirect(admin_url('data') . '#import');
        }
        $f = isset($_FILES['sql']) ? $_FILES['sql'] : null;
        if (!$f || (int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('没有收到 SQL 文件', 'err');
            redirect(admin_url('data') . '#import');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'sql') {
            flash('只接受 .sql 备份文件', 'err');
            redirect(admin_url('data') . '#import');
        }
        $info = array();
        $ok   = db_restore_file($f['tmp_name'], $info);
        @unlink($f['tmp_name']);
        if ($ok) {
            flash('导入完成：执行 ' . (int) $info['executed'] . ' 条语句'
                . ($info['backup'] !== '' ? '；导入前的数据已备份为 ' . $info['backup'] : ''));
        } else {
            flash($info['error'] !== '' ? $info['error'] : '导入失败', 'err');
        }
        redirect(admin_url('data') . '#import');
    }
}

$backups   = db_backup_list();
$backCount = count($backups);
$backSize  = 0;
foreach ($backups as $b) {
    $backSize += db_backup_size($b);
}
$backupOk = is_dir(db_backup_dir()) ? is_writable(db_backup_dir()) : is_writable(CMS_DATA);
$fmtSize  = function ($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
};
?>
<div class="edit-cols">
    <div>
        <div class="card" id="backup">
            <div class="card-head">
                <h2>数据备份</h2>
                <form method="post" action="<?php echo e(admin_url('data')); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="backup">
                    <button class="btn btn-sm btn-primary" type="submit">立即备份</button>
                </form>
            </div>
            <div class="card-body tight">
                <?php if (empty($backups)): ?>
                    <div class="empty">还没有备份文件，点右上角「立即备份」生成第一份</div>
                <?php else: ?>
                    <table class="table">
                        <tr>
                            <th>备份文件</th>
                            <th style="width:90px;white-space:nowrap">大小</th>
                            <th style="width:150px;white-space:nowrap">生成时间</th>
                            <th style="width:130px;text-align:right">操作</th>
                        </tr>
                        <?php foreach (array_slice($backups, 0, 20) as $b): $bt = (int) @filemtime(db_backup_dir() . '/' . $b); ?>
                            <tr>
                                <td><?php echo e($b); ?></td>
                                <td class="hint" style="white-space:nowrap"><?php echo e($fmtSize(db_backup_size($b))); ?></td>
                                <td class="hint" style="white-space:nowrap"><?php echo $bt ? e(date('Y-m-d H:i', $bt)) : '—'; ?></td>
                                <td class="actions-cell" style="white-space:nowrap;text-align:right">
                                    <a class="btn btn-sm" href="download.php?f=<?php echo e(urlencode($b)); ?>">下载</a>
                                    <form method="post" action="<?php echo e(admin_url('data')); ?>" style="display:inline"
                                          onsubmit="return confirm('确定删除这个备份文件？');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="do" value="backup_delete">
                                        <input type="hidden" name="file" value="<?php echo e($b); ?>">
                                        <button class="btn btn-sm" type="submit">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
            <div class="card-foot hint">
                备份文件保存在 content/data/backup，包含全部内容表，可直接用于迁移或恢复。
                <?php if ($backCount > 20): ?><br>共 <?php echo (int) $backCount; ?> 份，这里只列最近 20 份，旧的请下载后删除。<?php endif; ?>
            </div>
        </div>

        <div class="card" id="restore">
            <div class="card-head">
                <h2>数据恢复</h2>
                <span class="hint">用已有备份覆盖当前数据</span>
            </div>
            <div class="card-body">
                <div class="alert alert-warn">
                    恢复会用备份内容<strong>覆盖现有全部内容表</strong>，执行前会自动把当前数据再备份一次，选错文件也能退回去。
                </div>
                <?php if (empty($backups)): ?>
                    <div class="hint">还没有可用的备份文件，先到上面生成一份。</div>
                <?php else: ?>
                    <form method="post" action="<?php echo e(admin_url('data')); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="do" value="restore">
                        <div class="grid2">
                            <div class="form-row">
                                <label for="restore_file">选择备份</label>
                                <select class="input" id="restore_file" name="file">
                                    <?php foreach (array_slice($backups, 0, 30) as $b): ?>
                                        <option value="<?php echo e($b); ?>"><?php echo e($b); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-row">
                                <label for="restore_confirm">输入 RESTORE 确认</label>
                                <input class="input" id="restore_confirm" name="confirm" placeholder="RESTORE" autocomplete="off">
                            </div>
                        </div>
                        <button class="btn" type="submit">恢复这个备份</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" id="import">
            <div class="card-head">
                <h2>数据导入</h2>
                <span class="hint">上传 .sql 文件</span>
            </div>
            <div class="card-body">
                <p class="hint" style="margin-top:0;line-height:1.8">
                    用于换服务器、合并站点或从别处导回数据。只接受本程序生成的备份（文件头必须是 <code>CiciCMS 数据备份</code>），上限 64MB。
                </p>
                <form method="post" action="<?php echo e(admin_url('data')); ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="do" value="import">
                    <div class="grid2">
                        <div class="form-row">
                            <label for="import_sql">选择 SQL 文件</label>
                            <input class="input" type="file" id="import_sql" name="sql" accept=".sql" required>
                        </div>
                        <div class="form-row">
                            <label for="import_confirm">输入 RESTORE 确认</label>
                            <input class="input" id="import_confirm" name="confirm" placeholder="RESTORE" autocomplete="off">
                        </div>
                    </div>
                    <button class="btn" type="submit">上传并导入</button>
                </form>
            </div>
            <div class="card-foot hint">导入与恢复用的是同一套流程：先备份当前数据，再逐条执行，出错整体回滚。</div>
        </div>
    </div>

    <div class="edit-side">
        <div class="card">
            <div class="card-head"><h2>数据概况</h2></div>
            <div class="card-body">
                <div class="kv"><span>备份文件</span><span><?php echo (int) $backCount; ?> 个</span></div>
                <div class="kv"><span>占用空间</span><span><?php echo e($fmtSize($backSize)); ?></span></div>
                <div class="kv"><span>数据表</span><span><?php echo count(db_backup_tables()); ?> 张</span></div>
                <div class="kv"><span>备份目录</span><span><?php echo $backupOk ? '可写' : '不可写'; ?></span></div>
                <div class="kv"><span>最近备份</span><span><?php $last = $backCount ? (int) @filemtime(db_backup_dir() . '/' . $backups[0]) : 0; echo $last ? e(date('m-d H:i', $last)) : '—'; ?></span></div>
                <?php if (!$backupOk): ?>
                    <div class="alert alert-err" style="margin:12px 0 0">备份目录不可写，请先给 content/data/backup 写权限。</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="hint" style="line-height:1.9">
                    备份包含：配置、管理员、栏目、文章、单页、友情链接、附件共 7 张表。<br>
                    · 静态 HTML 不在备份里，恢复后重新生成一次即可<br>
                    · 上传的图片存在 content/uploads，迁移时单独打包<br>
                    · 备份文件名带随机后缀，无法靠猜地址下载
                </div>
            </div>
        </div>
    </div>
</div>
