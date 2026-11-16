-- CiciCMS 新模型（nebula）建表语句
-- 目标：把 7 张表换成 nebula 模型的 7 张表，字段与语义一一对应。
-- 兼容：MySQL 5.6+（不依赖 JSON 类型与窗口函数；JSON 一律存 TEXT）
-- 前缀：与安装时一致，示例用 cms_，实际请整体替换
--
-- 与现有库的差别（详见《CiciCMS-模型接入方案.md》）：
--   1. article + page 合并成 contents，用 type 区分
--   2. 标签从 article.tags 逗号串改成 tags + relationships 两张表
--   3. config → settings，attachment → media
--   4. 时间戳 int 全部改 DATETIME，状态 tinyint 改 ENUM
--
-- 这份 DDL 只建新表，不动老表。迁移脚本在 migrate-nebula.sql。

SET NAMES utf8mb4;

/* ---------- 用户 ---------- */

CREATE TABLE IF NOT EXISTS `cms_users` (
  `id`       int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50)  NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'password_hash 产出',
  `nickname` varchar(50)  NOT NULL DEFAULT '' COMMENT '前台署名',
  `role`     enum('admin','editor','author') NOT NULL DEFAULT 'author',
  `email`    varchar(120) NOT NULL,
  `status`   tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1 启用 / 0 停用；停用不删行',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------- 栏目 ---------- */

CREATE TABLE IF NOT EXISTS `cms_categories` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `name`        varchar(50)  NOT NULL,
  `slug`        varchar(100) NOT NULL COMMENT '栏目页 URL',
  `cover`       varchar(255) NOT NULL DEFAULT '' COMMENT '栏目封面（原 category.banner）',
  `description` varchar(255) NOT NULL DEFAULT '',
  `keywords`    varchar(255) NOT NULL DEFAULT '',
  `deleted_at`  datetime     DEFAULT NULL COMMENT '非空即在回收站',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`),
  KEY `deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------- 内容（文章 / 单页 / 修订） ---------- */

CREATE TABLE IF NOT EXISTS `cms_contents` (
  `id`           bigint unsigned NOT NULL AUTO_INCREMENT,
  `type`         enum('post','page','revision') NOT NULL DEFAULT 'post',
  `title`        varchar(200) NOT NULL,
  `slug`         varchar(200) DEFAULT NULL COMMENT '仅单页填写；文章留 NULL 走 /分类/ID.html',
  `content`      mediumtext   COMMENT 'Markdown / HTML 源文，渲染在构建时做',
  `excerpt`      varchar(500) DEFAULT NULL,
  `cover`        varchar(255) DEFAULT NULL,
  `status`       enum('publish','draft','pending','trash') NOT NULL DEFAULT 'draft',
  `author_id`    int unsigned NOT NULL DEFAULT 0,
  `parent_id`    bigint unsigned DEFAULT NULL COMMENT 'revision 指向原文',
  `template`     varchar(50)  DEFAULT NULL COMMENT '仅单页',
  `keywords`     varchar(255) DEFAULT NULL,
  `attach`       varchar(255) DEFAULT NULL COMMENT '下载附件路径',
  `options`      text         COMMENT 'JSON：inNav / navOrder / visible / hideTitle / wide',
  `views`        int unsigned NOT NULL DEFAULT 0,
  `published_at` datetime     NOT NULL,
  `updated_at`   datetime     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_slug` (`type`,`slug`),
  KEY `type_status` (`type`,`status`),
  KEY `status_published` (`status`,`published_at`),
  KEY `author_id` (`author_id`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------- 标签（随文章产生） ---------- */

CREATE TABLE IF NOT EXISTS `cms_tags` (
  `id`   int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50)  NOT NULL,
  `slug` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------- 内容 ↔ 标签 / 栏目 ---------- */

CREATE TABLE IF NOT EXISTS `cms_relationships` (
  `content_id` bigint unsigned NOT NULL,
  `type`       enum('tag','category') NOT NULL DEFAULT 'tag',
  `target_id`  int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`content_id`,`type`,`target_id`),
  KEY `type_target` (`type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------- 媒体 ---------- */

CREATE TABLE IF NOT EXISTS `cms_media` (
  `id`         bigint unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(200) NOT NULL,
  `path`       varchar(255) NOT NULL,
  `mime`       varchar(100) NOT NULL DEFAULT '',
  `size`       int unsigned NOT NULL DEFAULT 0,
  `width`      smallint unsigned DEFAULT NULL,
  `height`     smallint unsigned DEFAULT NULL,
  `alt`        varchar(200) DEFAULT NULL,
  `content_id` bigint unsigned DEFAULT NULL COMMENT '哪篇文章上传的；NULL 为游离附件',
  `deleted_at` datetime     DEFAULT NULL COMMENT '非空即在回收站',
  `created_at` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `path` (`path`),
  KEY `content_id` (`content_id`),
  KEY `deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/* ---------- 配置 ---------- */

CREATE TABLE IF NOT EXISTS `cms_settings` (
  `name`  varchar(64) NOT NULL,
  `value` text,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*
 * 友链表不在 nebula 模型里。
 * 现有 flink 是可用功能，接入时有两个选择：
 *   A. 保留 cms_flink（模型 7 张 + 友链 1 张）——推荐，删除功能代价大于收益
 *   B. 砍掉友链，模板 footer 里的「友情链接」一并去掉
 */
