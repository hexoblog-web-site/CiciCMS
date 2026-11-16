<?php
/**
 * CiciCMS 结构升级
 *
 * 目的：老版本站点更新程序文件后，数据库结构能自动补齐，不需要手工执行 SQL。
 * 做法：按 CMS_SCHEMA 版本写一次性锁文件，命中即跳过；每条变更都可重复执行。
 */

/** 当前数据结构版本，每次改表时 +1（唯一数据源，bootstrap 不再另设常量） */
function cms_schema_version()
{
    return 8;
}

/**
 * 执行结构升级。失败只记日志，不影响页面访问（避免升级把站点搞挂）。
 */
function cms_upgrade()
{
    $ver  = cms_schema_version();
    $lock = CMS_DATA . '/schema-' . $ver . '.lock';
    if (is_file($lock)) {
        return;
    }

    try {
        /* 1. 文章表增加推荐位字段（v2） */
        $col = all("SHOW COLUMNS FROM {p}article LIKE 'flag'");
        if (empty($col)) {
            q("ALTER TABLE {p}article ADD COLUMN `flag` varchar(60) NOT NULL DEFAULT '' AFTER `tags`");
            log_msg('schema: article.flag added');
        }

        /* 2. 友情链接表（v2） */
        q("CREATE TABLE IF NOT EXISTS `{p}flink` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT '',
  `logo` varchar(255) NOT NULL DEFAULT '',
  `desc` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `target` tinyint NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        /* 3. 文章表增加编辑源文（v3）：存 textarea 里的原始输入（含代码块围栏），
              content 仍存渲染后的 HTML，前台模板无需任何改动 */
        $col = all("SHOW COLUMNS FROM {p}article LIKE 'content_src'");
        if (empty($col)) {
            q("ALTER TABLE {p}article ADD COLUMN `content_src` mediumtext NULL AFTER `content`");
            log_msg('schema: article.content_src added');
        }

        /* 4. 附件路径统一（v4）：1.4 起附件放在 content/uploads/ 下，
              老数据存的 "uploads/2026/11/x.jpg" 补上 content/ 前缀。
              读取处（upload_rel）仍然兼容两种写法，这里只是把库里的值理顺。 */
        try {
            $n = (int) val("SELECT COUNT(*) FROM {p}attachment WHERE path LIKE 'uploads/%'");
            if ($n > 0) {
                q("UPDATE {p}attachment SET path = CONCAT('content/', path) WHERE path LIKE 'uploads/%'");
                log_msg('schema: attachment.path 已补全 content/ 前缀，共 ' . $n . ' 条');
            }
        } catch (Throwable $ex) {
            log_msg('schema: attachment.path 迁移跳过：' . $ex->getMessage());
        }

        /* 5. 栏目横幅（v5）：栏目列表页顶部背景图，存 content/uploads/... 或外链 */
        $col = all("SHOW COLUMNS FROM {p}category LIKE 'banner'");
        if (empty($col)) {
            q("ALTER TABLE {p}category ADD COLUMN `banner` varchar(255) NOT NULL DEFAULT '' AFTER `keywords`");
            log_msg('schema: category.banner added');
        }

        /* 6. 单页编辑源文（v6）：与文章一致，单页里的 ``` 代码块也要能渲染，
              并且二次编辑时还能拿到围栏原文而不是被渲染后的 HTML */
        $col = all("SHOW COLUMNS FROM {p}page LIKE 'content_src'");
        if (empty($col)) {
            q("ALTER TABLE {p}page ADD COLUMN `content_src` mediumtext NULL AFTER `content`");
            log_msg('schema: page.content_src added');
        }

        /* 7. 文章附件（v7）：下载文件、显示名、字节数、是否在文章页显示。
              attach_show 默认 0——上传了也不自动挂到文章底部，要手动勾选。 */
        $col = all("SHOW COLUMNS FROM {p}article LIKE 'attachment'");
        if (empty($col)) {
            q("ALTER TABLE {p}article ADD COLUMN `attachment` varchar(255) NOT NULL DEFAULT '' AFTER `content_src`");
            log_msg('schema: article.attachment added');
        }
        $col = all("SHOW COLUMNS FROM {p}article LIKE 'attach_name'");
        if (empty($col)) {
            q("ALTER TABLE {p}article ADD COLUMN `attach_name` varchar(120) NOT NULL DEFAULT '' AFTER `attachment`");
            log_msg('schema: article.attach_name added');
        }
        $col = all("SHOW COLUMNS FROM {p}article LIKE 'attach_size'");
        if (empty($col)) {
            q("ALTER TABLE {p}article ADD COLUMN `attach_size` int unsigned NOT NULL DEFAULT 0 AFTER `attach_name`");
            log_msg('schema: article.attach_size added');
        }
        $col = all("SHOW COLUMNS FROM {p}article LIKE 'attach_show'");
        if (empty($col)) {
            q("ALTER TABLE {p}article ADD COLUMN `attach_show` tinyint NOT NULL DEFAULT 0 AFTER `attach_size`");
            log_msg('schema: article.attach_show added');
        }

        /* 8. 附件元信息（v8）：
              · alt        构建 <img> 时的替代文本，没有它无障碍与图片搜索都吃亏
              · content_id 这张图被哪篇内容使用；0 = 还没被任何内容引用
              · deleted_at 媒体回收站：删除只打时间戳，文件留在磁盘，可以恢复 */
        $col = all("SHOW COLUMNS FROM {p}attachment LIKE 'alt'");
        if (empty($col)) {
            q("ALTER TABLE {p}attachment ADD COLUMN `alt` varchar(200) NOT NULL DEFAULT '' AFTER `name`");
            log_msg('schema: attachment.alt added');
        }
        $col = all("SHOW COLUMNS FROM {p}attachment LIKE 'content_id'");
        if (empty($col)) {
            q("ALTER TABLE {p}attachment ADD COLUMN `content_id` int unsigned NOT NULL DEFAULT 0 AFTER `size`");
            try {
                q("ALTER TABLE {p}attachment ADD KEY `content_id` (`content_id`)");
            } catch (Throwable $ex) {
                log_msg('schema: attachment.content_id 索引跳过：' . $ex->getMessage());
            }
            log_msg('schema: attachment.content_id added');
        }
        $col = all("SHOW COLUMNS FROM {p}attachment LIKE 'deleted_at'");
        if (empty($col)) {
            q("ALTER TABLE {p}attachment ADD COLUMN `deleted_at` int unsigned NOT NULL DEFAULT 0 AFTER `content_id`");
            try {
                q("ALTER TABLE {p}attachment ADD KEY `deleted_at` (`deleted_at`)");
            } catch (Throwable $ex) {
                log_msg('schema: attachment.deleted_at 索引跳过：' . $ex->getMessage());
            }
            log_msg('schema: attachment.deleted_at added');
        }

        /* 9. 正文源文回填（v8）：
              早期文章只有渲染后的 content，没有 content_src。这种行在后台编辑时
              拿到的是 HTML，一保存就把代码块围栏丢了（教程类文章会坏）。
              把 content 原样复制到 content_src，之后编辑就以源文为准。
              只补空值，不覆盖任何已有内容。 */
        try {
            $n = (int) val("SELECT COUNT(*) FROM {p}article
                            WHERE (content_src IS NULL OR content_src = '') AND content <> ''");
            if ($n > 0) {
                q("UPDATE {p}article SET content_src = content
                   WHERE (content_src IS NULL OR content_src = '') AND content <> ''");
                log_msg('schema: article.content_src 回填 ' . $n . ' 条');
            }
            $n = (int) val("SELECT COUNT(*) FROM {p}page
                            WHERE (content_src IS NULL OR content_src = '') AND content <> ''");
            if ($n > 0) {
                q("UPDATE {p}page SET content_src = content
                   WHERE (content_src IS NULL OR content_src = '') AND content <> ''");
                log_msg('schema: page.content_src 回填 ' . $n . ' 条');
            }
        } catch (Throwable $ex) {
            log_msg('schema: content_src 回填跳过：' . $ex->getMessage());
        }

        if (ensure_dir(CMS_DATA)) {
            @file_put_contents($lock, date('c') . " schema=" . $ver . "\n");
        }
    } catch (Throwable $ex) {
        // 数据库账号可能没有 ALTER 权限：记录下来，让站点继续跑
        log_msg('schema upgrade failed: ' . $ex->getMessage());
    }
}
