# 数据表字典

七张表，都很简单。表前缀默认 `cms_`，安装时可改。

## 总览

| 表 | 一行是什么 | 大概多少行 |
| --- | --- | --- |
| `config` | 一个配置项 | 几十行 |
| `user` | 一个管理员 | 1 行 |
| `category` | 一个栏目 | 几个到几十 |
| `article` | 一篇文章 | 你写多少有多少 |
| `page` | 一个单页 | 几个 |
| `flink` | 一个友情链接 | 十几个 |
| `attachment` | 一张图片的记录 | 跟着上传量 |

**没有**评论表、会员表、标签表、浏览量明细表。

::: tip 标签为什么没有表
标签直接存成文章表里的一列字符串（`tags`，逗号分隔）。统计标签云的时候扫这一列算。

好处是简单——不用维护多对多关系、删文章不会留下孤儿记录。代价是标签没有自己的属性（不能给标签加描述、加封面）。
:::

## `config` —— 配置

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `k` | varchar(64) PK | 键名 |
| `v` | mediumtext | 值 |

键值对结构，什么配置都往里塞：

| 键 | 例子 | 说明 |
| --- | --- | --- |
| `site_name` | 我的站 | 站点名 |
| `site_url` | https://a.com | 站点地址 |
| `per_page` | 10 | 每页条数 |
| `timezone` | Asia/Shanghai | 时区 |
| `auto_build` | 1 | 保存后自动生成 |
| `trust_proxy` | 0 | 采信 X-Forwarded-For |
| `theme` | default | 当前主题 |
| `plugins_active` | `["highlight"]` | 启用的插件（JSON） |
| `schema_version` | 7 | 数据库结构版本 |
| `update_mail_pass` | 明文 | SMTP 授权码（敏感） |

::: danger 备份要当敏感文件
`update_mail_pass` 里存的是 SMTP 授权码**明文**。数据库备份文件泄露 = 邮箱被用。

这也是为什么 `content/data/backup/` 目录被 `.htaccess` 拒绝访问。
:::

## `user` —— 管理员

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK | — |
| `username` | varchar(50) UNIQUE | 登录名 |
| `password` | varchar(255) | `password_hash()` 的结果 |
| `email` | varchar(120) | 邮箱 |
| `last_login` | int | 上次登录时间戳 |
| `last_ip` | varchar(45) | 上次登录 IP（够放 IPv6） |
| `created_at` | int | 创建时间 |

**单管理员模型**：表里只有一行有效数据，没有角色字段。

想扩展多管理员？`id`、`username`、`password` 的结构已经支持多行，缺的是权限字段和后台的权限判断。你需要加：

1. `role` 字段（`admin` / `editor`）
2. 后台 `require_login()` 之后按角色拦菜单
3. 文章表的 `author_id` 已经存在，可以做"只能改自己的文章"

## `category` —— 栏目

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK | — |
| `name` | varchar(80) | 栏目标题 |
| `dirname` | varchar(60) UNIQUE | 别名，**就是网址目录** |
| `parent_id` | int | 父栏目（目前都是 0） |
| `sort` | int | 排序 |
| `description` | varchar(255) | 描述 |
| `keywords` | varchar(255) | 关键词 |
| `banner` | varchar(255) | 横幅地址 |

`dirname` 上有唯一索引 —— 这是"栏目别名不能重复"的底层保证。

`parent_id` 留着但后台没做多级界面。面包屑已经支持多级链路，真要做的话：

1. 后台栏目编辑加"上级栏目"下拉
2. `fe_cat_map()` 里递归组装
3. 生成器按层级拼目录

## `article` —— 文章

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK | — |
| `cat_id` | int | 所属栏目 |
| `title` | varchar(200) | 标题 |
| `slug` | varchar(120) UNIQUE | 英文路径 |
| `excerpt` | varchar(500) | 手工摘要 |
| `content` | mediumtext | **渲染后**的 HTML |
| `content_src` | mediumtext | **原始**围栏文本 |
| `attachment` | varchar(255) | 附件地址 |
| `attach_name` | varchar(120) | 附件显示名 |
| `attach_size` | int | 附件字节数 |
| `attach_show` | tinyint | 前台是否显示下载按钮 |
| `cover` | varchar(255) | 封面图 |
| `tags` | varchar(255) | 标签，逗号分隔 |
| `flag` | varchar(60) | 推荐位代号，可多个 |
| `author_id` | int | 作者 |
| `status` | tinyint | 1 已发布 / 0 草稿 |
| `is_top` | tinyint | 置顶 |
| `views` | int | 浏览量 |
| `created_at` | int | 发布时间戳 |
| `updated_at` | int | 更新时间戳 |

**索引**：`PRIMARY(id)`、`UNIQUE(slug)`、`KEY(cat_id)`、`KEY(status)`、`KEY(created_at)`

::: tip 为什么同时存 content 和 content_src
| 字段 | 给谁用 |
| --- | --- |
| `content` | 前台直接输出，不用再渲染一遍 |
| `content_src` | 二次编辑时回填到 textarea |

只存一个的话：存原文 → 前台每次都要渲染（慢）；存 HTML → 编辑时看到一堆 `<pre><code>`（没法改）。

`content_src` 是后加的字段，老数据里是 `NULL`，程序会从 `content` 反推。
:::

**`flag` 的编码**：`c` 推荐、`h` 头条、`p` 图片、`f` 幻灯、`a` 特荐、`s` 滚动、`b` 加粗、`j` 图文。多个值拼在一起存。

## `page` —— 单页

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK | — |
| `title` | varchar(200) | 标题 |
| `slug` | varchar(120) UNIQUE | 别名，决定 `/别名.html` |
| `content` | mediumtext | 渲染后 HTML |
| `content_src` | mediumtext | 原始文本 |
| `status` | tinyint | 发布状态 |
| `sort` | int | 排序 |
| `created_at` | int | — |
| `updated_at` | int | — |

比文章少很多字段——单页不需要栏目、标签、封面、附件、摘要。

## `flink` —— 友情链接

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK | — |
| `name` | varchar(100) | 站点名 |
| `url` | varchar(255) | 网址 |
| `logo` | varchar(255) | Logo 地址 |
| `desc` | varchar(255) | 一句话说明 |
| `sort` | int | 排序 |
| `status` | tinyint | 是否显示 |
| `target` | tinyint | 是否新窗口 |
| `created_at` | int | — |

**索引**：`KEY(status)`、`KEY(sort)`

## `attachment` —— 图片记录

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int PK | — |
| `path` | varchar(255) | 相对路径 |
| `name` | varchar(200) | 原始文件名 |
| `mime` | varchar(80) | 真实 MIME |
| `size` | int | 字节数 |
| `created_at` | int | — |

::: warning 附件（pdf/zip）不进这张表
这张表是**图片画廊**的数据源。附件存在文章的 `attachment` 字段里，靠系统清理按文件名扫描来保证不被误删。
:::

## 表之间的关系

```
category (1) ──< (N) article
                        │
                        └── attachment（按文件名引用，不是外键）
user (1) ──────────< (N) article.author_id

config      独立，键值对
page        独立
flink       独立
```

**没有外键约束**。删栏目时靠程序判断"下面还有没有文章"，而不是数据库拦。

为什么不用外键：很多人用虚拟主机，MySQL 账号没权限建外键；而且备份恢复时外键顺序容易出问题。代价是脏数据要靠代码保证，所以每个删除入口都写了检查。

## 结构升级

`config` 表里的 `schema_version` 记录当前结构版本。程序启动时和代码里的目标版本比对，低于就跑 `includes/upgrade.php` 里的 `ALTER TABLE`。

当前版本：**7**

升级记录会写进日志：

```
schema: page.content_src added
schema: article.attachment added
schema: article.attach_show added
```

::: danger 升级前必须备份
结构升级是 `ALTER TABLE`，不可回滚（回滚代码不管用）。中断在半路会留下"加了一半字段"的表。

出问题时只能靠升级前那份数据库备份。
:::

## 常用查询

**某栏目下最新的 10 篇**

```sql
SELECT id, title, created_at FROM cms_article
WHERE cat_id = 2 AND status = 1
ORDER BY created_at DESC LIMIT 10;
```

**浏览量最高的 5 篇**

```sql
SELECT id, title, views FROM cms_article
WHERE status = 1 ORDER BY views DESC LIMIT 5;
```

**每个栏目有多少篇**

```sql
SELECT c.name, COUNT(a.id) AS n
FROM cms_category c
LEFT JOIN cms_article a ON a.cat_id = c.id AND a.status = 1
GROUP BY c.id ORDER BY c.sort;
```

**标签出现次数**（程序里是 PHP 算的，这里只是参考）

```sql
SELECT tags FROM cms_article WHERE status = 1 AND tags <> '';
```

## 下一步

[请求是怎么走完一圈的](/dev/flow)
