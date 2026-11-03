<?php
/**
 * HtmlCms 结构升级
 *
 * 目的：老版本站点更新程序文件后，数据库结构能自动补齐，不需要手工执行 SQL。
 * 做法：按 CMS_SCHEMA 版本写一次性锁文件，命中即跳过；每条变更都可重复执行。
 */

/** 当前数据结构版本，每次改表时 +1（唯一数据源，bootstrap 不再另设常量） */
function cms_schema_version()
{
    return 6;
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

        if (ensure_dir(CMS_DATA)) {
            @file_put_contents($lock, date('c') . " schema=" . $ver . "\n");
        }
    } catch (Throwable $ex) {
        // 数据库账号可能没有 ALTER 权限：记录下来，让站点继续跑
        log_msg('schema upgrade failed: ' . $ex->getMessage());
    }
}
