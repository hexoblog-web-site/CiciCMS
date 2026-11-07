# 文件说明

逐文件说明。想改哪里、哪里可能有坑，看这一页。

> 全部 PHP 代码约 **10,800 行**（含安装向导），其中最大的 `includes/updater.php` 1191 行。

## 入口文件

| 文件 | 行数 | 职责 |
| --- | --- | --- |
| `index.php` | 61 | 前台入口。检查有没有对应的静态文件，有就交给服务器；没有就走动态渲染（搜索、404、预览） |
| `api.php` | 40 | 浏览量计数接口。只接受 POST，按文章 ID +1，防刷按会话去重 |
| `config.php` | 生成 | 数据库配置。安装时写入，之后只读 |
| `admin/index.php` | 115 | 后台单入口路由，`?p=页面名` 分发 |
| `install/index.php` | 635 | 安装向导（环境检测 / 配置 / 建表 / 生成首页） |

### `admin/index.php` 的路由规则

```php
$allow = array('dashboard','article','category','page','media','flink',
               'build','clean','data','theme','setting','plugin','update');
```

- 白名单里的 → 核心页面
- 不在白名单 → 查插件注册的后台页
- 都不是 → 回仪表盘

::: warning 插件 ID 不能抢占核心路由
插件 ID 叫 `setting` 是无效的，永远进不到插件页。这是故意的——防止插件把核心页面顶掉。
:::

里面还有一条特殊分支：`?p=build&action=tick`，是**分批生成**的接口。前端每完成一批就再调一次，每次给 `build_tick(8.0)` 一个 8 秒预算，避免撞上 `max_execution_time`。这个接口同样校验 CSRF，不通过返回 403。

## 引导与基础

### `includes/bootstrap.php`（197 行）

**职责**：所有请求的起点。

顺序：定义常量 → 读配置 → 注册错误处理 → 连库 → 按需开会话 → 结构升级 → 加载插件。

**注意点**

- 会话是**按需启动**的（`cms_session_start()`），前台静态请求不建 session 文件。虚拟主机上访客多了不会堆出几十万个空会话
- 错误统一捕获，日志写 `content/data/logs/YYYY-MM-DD.log`，生产环境不向浏览器暴露堆栈
- `cms_migrate_dirs()` 会补齐 `content/` 下缺失的子目录，升级后不会因为少个目录报错

### `includes/db.php`（104 行）

**职责**：PDO 薄封装，全库都通过它访问数据库。

| 函数 | 作用 |
| --- | --- |
| `q($sql, $params)` | 执行，返回 PDOStatement |
| `all($sql, $params)` | 取全部行 |
| `one($sql, $params)` | 取一行 |
| `val($sql, $params)` | 取一个值 |
| `insert_row($table, $data)` | 插入，返回自增 ID |
| `update_row($table, $data, $where, $params)` | 更新 |
| `delete_row($table, $where, $params)` | 删除 |
| `cms_table($name)` | 拼表前缀 |

**关键设计**

```php
PDO::ATTR_EMULATE_PREPARES => false
```

关掉模拟预处理，SQL 和参数真的分两次发给 MySQL，注入面小得多。

`{p}` 是表前缀占位符，`q()` 会自动替换成 `cms_`。

::: warning 表名和字段名不能参数化
`insert_row` / `update_row` 的字段名是直接拼进 SQL 的。所以这些函数只接受**代码里写死的字段名**，不能把用户输入当字段名传进来。
:::

### `includes/functions.php`（921 行）

**职责**：公共设施。什么都在里面，是最大的一块。

| 分组 | 主要函数 |
| --- | --- |
| 输入 | `is_post` `post` `get` `post_str` `get_str` `get_int` `client_ip` |
| 输出 | `e` `json_out` `redirect` `flash` `flashes` |
| 会话 | `cms_session_start` |
| 配置 | `settings_all` `setting` `set_setting` |
| URL | `site_url` `u_home` `u_post` `u_cat` `u_page` `u_tag` `u_search` `cat_dir_of` `post_static_rel` `tag_slug` |
| 主题 | `theme_name` `theme_path` `theme_url` `render` `render_php_file` |
| 上传 | `upload_path` `upload_rel` `upload_url` `cms_url_public_check` |
| 安全 | `csrf_token` `csrf_field` `csrf_verify` `clean_html` |
| 文本 | `str_limit` `make_excerpt` `make_slug` `unique_slug` `format_date` `human_date` `format_bytes` `normalize_tags` |
| 文件 | `ensure_dir` `write_file_atomic` `log_msg` |
| 后台 | `admin_url` `admin_pager_html` `cms_permission_audit` |

**三个值得单独看的**

`write_file_atomic()` —— 先写临时文件再 `rename`，保证不会读到半个文件：

```php
function write_file_atomic($path, $data)
{
    $tmp = $path . '.tmp' . getmypid();
    if (file_put_contents($tmp, $data) === false) { return false; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}
```

`cms_url_public_check()` —— 判断一个 URL 是不是公网地址。用 `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` 挡掉内网和保留段，图片抓取和 Hexo 导入共用。

`cms_permission_audit()` —— 后台「系统设置 → 目录权限自检」的数据源，按三区规则逐项检查写/执行权限。

## 内容处理

### `includes/content.php`（306 行）

**职责**：正文从"你在编辑器里写的东西"变成"前台能显示的 HTML"。

| 函数 | 作用 |
| --- | --- |
| `render_content($src)` | 主入口：代码围栏 → HTML |
| `code_block_html()` | 单个代码块转成 `<pre><code>` |
| `code_lang_map()` | 语言标识映射（php / sql / js / py …） |
| `clean_html($html)` | 净化：去 `<script>`、`on*`、危险协议 |
| `content_plain($html)` | 提取纯文本（先摘掉代码块） |
| `make_excerpt`（在 functions） | 自动摘要 |

**处理顺序很关键**

```
原文 → render_content()（围栏转代码块）→ clean_html()（净化）→ 入库 content
  └→ 原文同时存进 content_src
```

::: danger 顺序反了会出事
如果先净化再转围栏，代码块里的 `<script>` 会被当脚本清掉，教程就没法演示了。

现在的顺序里，代码块**先被整体转义**成 `&lt;script&gt;`，所以净化阶段碰不到它——教程里能正常显示 `<script>`，而真正的脚本仍然被拦。
:::

**两个字段各管什么**

| 字段 | 存什么 | 谁用 |
| --- | --- | --- |
| `content` | 渲染后的 HTML | 前台直接输出 |
| `content_src` | 原始围栏文本 | 二次编辑时回填到 textarea |

没有 `content_src` 的话，编辑时看到的就是一堆 `<pre><code>`，改起来很痛苦。

## 前台

### `includes/frontend.php`（408 行）

**职责**：查数据 + 渲染九种页面。

| 分组 | 函数 |
| --- | --- |
| 数据 | `fe_posts` `fe_count` `fe_cats` `fe_cat_by_dir` `fe_cat_by_id` `fe_cat_counts` `fe_tags_all` `fe_find_post` `fe_find_page` |
| 变量 | `fe_base_vars` `fe_pager` `pager_numbers` |
| 渲染 | `fe_render_home` `fe_render_category` `fe_render_article` `fe_render_page` `fe_render_tag` `fe_render_tags_index` `fe_render_search` `fe_render_404` `fe_render_archive` `fe_render_links` |
| 归档 | `fe_archive_groups` |

**渲染的套路**：每个 `fe_render_xxx()` 都做三件事——查数据、组装变量数组、调 `render('模板名', $vars)`。

静态生成和站内搜索**共用同一套 `fe_render_xxx()` 渲染函数**，所以输出口径始终一致。

## 静态生成

### `includes/builder.php`（764 行）

**职责**：把数据库变成一堆文件。核心是"算清单 → 逐个写 → 按清单清理"。

| 分组 | 函数 |
| --- | --- |
| 计划 | `build_plan_all` `build_plan_incremental` `build_posts_all` `build_pages_all` |
| 执行 | `build_exec_task` `build_all` `build_incremental` `build_task` |
| 分批 | `build_start` `build_tick` `build_pending` `build_finish` |
| 状态 | `build_read_state` `build_write_state` `build_clear_state` |
| 清单 | `build_read_manifest` `build_write_manifest` `build_cleanup` `static_forget` `static_clean` |
| 写盘 | `build_put` `build_err` `build_allowed_ext` |
| 附加 | `build_sitemap` `build_rss` |

**几个关键设计**

1. **`build_allowed_ext()`** —— 只允许写 `.html .xml .txt .json`。根目录必须可写，这个白名单保证"就算被利用也写不进 `.php`"
2. **manifest（`content/data/static.json`）** —— 记录程序生成过的所有文件。清理时只删"清单里有、这轮没生成"的，绝不会误删你手动放的验证文件
3. **`build_tick($seconds)`** —— 给一个时间预算，干完就走，状态存盘。前端反复调用直到完成。文章上千篇也不怕超时
4. **`flock` 独占锁** —— 两个人同时点生成，第二个直接返回"正在生成中"
5. **渲染结果为空视为失败** —— 不会写出空白页把好页面覆盖掉

## 数据

### `includes/backup.php`（361 行）

**职责**：导出/导入/恢复。

| 函数 | 作用 |
| --- | --- |
| `db_backup()` | 导出全库 SQL |
| `db_backup_list` `db_backup_find` `db_backup_delete` `db_backup_size` | 管理备份文件 |
| `db_sql_split()` | 把 SQL 文本切成一条条语句 |
| `db_restore_statement_allowed()` | **语句白名单** |
| `db_restore_file()` | 执行恢复 |

**白名单逻辑**

只放行：`SET` / `INSERT` / `REPLACE` / `TRUNCATE` / `DELETE`，而且表名必须是本程序的表。

`TRUNCATE` 会被**改写成 `DELETE`**——因为 `TRUNCATE` 在 MySQL 里不能回滚，事务保护不了它。

整个恢复过程在事务里跑，任何一条出错全部回滚。

### `includes/upgrade.php`（116 行）

**职责**：数据库结构自动升级。

用 `config` 表存一个 `schema_version`，和代码里的目标版本比对，低于就跑对应的 `ALTER TABLE`。跑完写日志：

```
schema: page.content_src added
article.attachment added
```

短小但重要——没有它，每次升级用户都得手动执行 SQL。

## 上传

### `includes/uploader.php`（440 行）

**职责**：图片上传的**唯一实现**。类 `Cms_Image_Uploader`。

流程：校验 → 命名 → 落盘 → 登记 `attachment` 表。

| 方法 | 作用 |
| --- | --- |
| `handle()` / `save()` | 处理上传的文件 |
| `fromUrl()` | **按地址抓图**（逐跳校验 SSRF） |
| `fetchOnce()` | 抓一跳，不自动跟随重定向 |
| `absoluteUrl()` | 把相对地址补成绝对地址 |
| `urlCheck()` | 地址是否公网 |
| `fail()` | 记错误信息 |

**安全要点**

| 检查 | 做法 |
| --- | --- |
| 真实类型 | `getimagesize()` 读文件头，不看后缀 |
| 大小 | 5MB 上限 |
| 格式 | 只收 jpg / jpeg / png / gif / webp，SVG 和 BMP 拒 |
| 命名 | 8 位日期 + 16 位微秒时间戳，不保留原名 |
| 目录 | `content/uploads/images/年/月日/` |

::: warning 抓图必须逐跳校验
`fromUrl()` 原来是"检查首地址 → 开启自动跟随"。这有个洞：公网地址返回 `302 跳转到 127.0.0.1`，就绕过去了。

现在改成手动跟随，**每一跳都重新调 `cms_url_public_check()`**，最多 4 跳。`fetchOnce()` 内部 `CURLOPT_FOLLOWLOCATION = false`，自己解析 `Location` 头。
:::

### `includes/upload.php`（111 行）

**职责**：给后台页面用的函数式封装，比直接用类方便。

`cms_upload_image()`、`cms_upload_remote()`、`cms_banner_clean()`、`cms_upload_delete()`、`cms_upload_exts()`、`cms_upload_max_bytes()`。

### `includes/fileupload.php`（256 行）

**职责**：**附件**上传（pdf / zip / exe 这类），和图片是两套。

| 函数 | 作用 |
| --- | --- |
| `cms_file_exts()` | 允许的后缀 |
| `cms_file_deny_exts()` | 明确拒绝的后缀 |
| `cms_file_ext_ok()` | 校验后缀 |
| `cms_file_upload()` | 落盘 + 登记 |
| `cms_file_guard_dir()` | 在目录里写防护 `.htaccess` |
| `cms_file_clean` / `cms_file_delete` | 清理 |

**为什么单独一套**：图片走 `getimagesize()` 判断，附件没法这么判，只能靠后缀白名单 + 明确拒绝表（`php / phtml / phar / js / html / svg / ini / htaccess …`）。

**自动写的 `.htaccess`**：

```apache
<IfModule mod_autoindex.c>
Options -Indexes
</IfModule>
<IfModule mod_php.c>
php_flag engine off
</IfModule>
<FilesMatch "\.(php|phtml|...)$">
  <IfModule mod_authz_core.c>
  Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
  </IfModule>
</FilesMatch>
```

::: danger 每条指令都必须包 IfModule
裸写 `Options -Indexes` 在限制了 `AllowOverride Options` 的主机上会让**整个目录 500**，用户看到的是"附件全部打不开"，而且很难查出来。

真正重要的防线是后缀白名单，`.htaccess` 只是额外一层。所以宁可不生效，也不能报错。
:::

## 扩展

### `includes/plugin.php`（350 行）

**职责**：插件内核。只有两件事——钩子和加载。

| 分组 | 函数 |
| --- | --- |
| 注册钩子 | `cms_add_filter` `cms_add_action` `cms_hook_add` |
| 执行钩子 | `cms_apply_filters` `cms_do_action` |
| 扫描 | `cms_plugin_scan` `cms_plugin_active_ids` `cms_plugin_save_active` `cms_plugin_active` |
| 加载 | `cms_plugin_load` |
| 路径 | `cms_plugin_url` `cms_plugin_path` `cms_plugin_id_ok` |
| 后台页 | `cms_plugin_admin_pages` |
| 文档 | `cms_hook_docs` |

**加载时机**：`bootstrap.php` 里，在结构升级之后。

**故障隔离**：回调抛异常只写日志并跳过，不会把整站渲染搞挂。

::: warning 这是全站唯一的"可写 + 可执行"目录
`content/plugins/<id>/plugin.php` 会被 `include` 执行。能往这里写文件、又能点"启用"的人，等于能执行任意 PHP。详见 [权限模型](/ops/permission#exceptions)。
:::

### `includes/zipreader.php`（211 行）

**职责**：类 `Cms_Zip_Reader`，自己实现 zip 解析（`stored` + `deflate` 两种压缩方式 + CRC 校验）。

**为什么不用 `ZipArchive`**：很多虚拟主机不装这个扩展。在线更新、Hexo 导入的 `.zip` 都靠它。

## 运维

### `includes/updater.php`（1191 行）

**职责**：在线更新，全项目最复杂的文件。

| 分组 | 函数 |
| --- | --- |
| 设置 | `update_repo` `update_branch` `update_auto_check` `update_interval` `update_cron_token` |
| 检查 | `update_check` `update_remote_info` `update_has_new` `update_available_cached` |
| 下载 | `update_http` `update_download` `update_install_zip` |
| 备份 | `update_backup_system` `update_copy_tree` `update_restore` `update_prune_backups` |
| 写盘 | `update_write_file` `update_path_allowed` `update_open_dirs` `update_close_dirs` |
| 应用 | `update_apply` `update_apply_locked` `update_rollback` |
| 通知 | `update_notify` `update_mail` `update_notify_applied` |
| 加锁 | `update_lock` `update_unlock` |

**几个必须理解的设计**

1. **`update_path_allowed()`** —— 白名单：只能写 `includes/ admin/ index.php api.php .htaccess README.md LICENSE`。`../`、绝对路径、`content/` 下的全部拒绝
2. **`update_writable_preflight()`** —— 动手前先确认所有目标目录真的可写、`chmod` 真的能生效。不能就整批拦下，不会写到一半
3. **`update_open_dirs()` / `close_dirs()`** —— 临时 `chmod` 成可写，结束（含回滚）再改回原样
4. **`update_has_new()` 的双重判断** —— 版本号更高 **或** 提交号变了。作者忘了改版本号也能识别

### `includes/mailer.php`（291 行）

**职责**：发信。两条路。

| 函数 | 作用 |
| --- | --- |
| `cms_mail_send()` | 统一入口，按设置分发 |
| `cms_mail_config()` | 读邮件设置 |
| `cms_smtp_send()` | SMTP 发信 |
| `cms_smtp_cmd()` | 发一条 SMTP 命令并读回复 |
| `cms_smtp_read()` / `cms_smtp_code()` | 读回复、解析状态码 |

自己实现 SMTP 客户端（支持 465 SSL 和 587 STARTTLS），不需要 Composer，也不需要 `openssl` 之外的扩展。

### `includes/clean.php`（338 行）

**职责**：系统清理。

| 函数 | 作用 |
| --- | --- |
| `cms_clean_scan()` | 扫描，返回 `files / total / used / unused / bytes` |
| `cms_clean_refs()` | 建立引用索引 |
| `cms_clean_is_used()` | 某个文件名是否被引用 |
| `cms_clean_index()` | 遍历上传目录 |
| `cms_clean_delete_many()` | 批量删除（重新核对 + 路径校验） |
| `cms_clean_forget_record()` | 清失效记录 |

**注意返回结构**：`cms_clean_scan()` 返回的是**结构体**不是列表。

```php
$s = cms_clean_scan();
$s['files'];   // 每个文件的详情
$s['total'];   // 总数
$s['used'];    // 被引用
$s['unused'];  // 未引用
$s['bytes'];   // 占用
```

### `includes/auth.php`（134 行）

**职责**：登录、会话、失败限速。

| 函数 | 作用 |
| --- | --- |
| `auth_login()` | 校验账号密码 |
| `auth_user()` / `auth_check()` / `require_login()` | 取当前用户 / 是否登录 / 强制登录 |
| `auth_logout()` | 退出（POST + CSRF） |
| `login_guard_check` `login_guard_fail` `login_guard_clear` | 限速：同 IP 15 分钟 8 次 |

限速状态存在文件里（`login_guard.json`），不占数据库。用文件是因为暴力破解打的就是数据库前面那一层，这时候不该再加数据库压力。

## 后台

### `admin/upload.php`（102 行）

编辑器插图接口，返回 JSON。

| 状态码 | 什么时候 |
| --- | --- |
| 405 | 不是 POST |
| 401 | 未登录 |
| 403 | CSRF 令牌不符 |
| 413 | 文件过大 |
| 200 | 成功，返回地址和尺寸 |

::: tip 全部返回 JSON，不跳转
AJAX 请求如果被跳成登录页，前端拿到一坨 HTML 会解析失败。所以这里即使未登录也返回 401 + JSON。
:::

### `admin/download.php`（34 行）

备份下载。文件名必须能在 `db_backup_find()` 的白名单里找到，否则 404。

```php
download.php?f=../../../config.php   →  404
```

### `admin/cron.php`（56 行）

计划任务入口。`?token=xxx` 校验，不对一律 404（不泄露"这里有个接口"）。用 `hash_equals()` 防时序攻击。

### `admin/pages/*.php`

十三个页面，结构统一：挡直接访问 → 处理 POST（先 `csrf_verify()`）→ 查数据 → 输出 HTML。

## 下一步

[函数手册](/dev/functions) —— 按功能分类的函数清单
