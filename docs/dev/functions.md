# 函数手册

按功能分类的函数清单。写插件、改模板、读源码时当字典查。

::: tip 全部是全局函数
没有命名空间。靠前缀区分归属：`cms_` 公共 / `fe_` 前台 / `build_` 生成 / `db_` 数据库备份 / `update_` 更新 / `cms_tag_` 标签引擎。自己写插件时请加自己的前缀，避免撞名白屏。
:::

## 数据库 `includes/db.php`

| 函数 | 说明 |
| --- | --- |
| `q($sql, $params = [])` | 执行 SQL，返回 `PDOStatement` |
| `all($sql, $params)` | 取全部行（数组） |
| `one($sql, $params)` | 取第一行（数组或 null） |
| `val($sql, $params)` | 取第一行第一列 |
| `insert_row($table, $data)` | 插入，返回自增 ID |
| `update_row($table, $data, $where, $params)` | 更新，返回影响行数 |
| `delete_row($table, $where, $params)` | 删除 |
| `cms_table($name)` | 返回带前缀的表名 |

```php
$posts = all('SELECT * FROM {p}article WHERE status = ? ORDER BY id DESC LIMIT 10', array(1));
$name  = val('SELECT name FROM {p}category WHERE id = ?', array(2));
insert_row('flink', array('name' => '某某站', 'url' => 'https://a.com'));
```

`{p}` 会自动替换成表前缀（默认 `cms_`）。

## 配置 `includes/functions.php`

| 函数 | 说明 |
| --- | --- |
| `settings_all($refresh = false)` | 全部配置（数组，带静态缓存） |
| `setting($key, $default = '')` | 取一项，空字符串时返回默认值 |
| `set_setting($key, $value)` | 写一项（不存在则插入） |

```php
$perPage = (int) setting('per_page', 10);
set_setting('site_name', '我的站');
```

## 输入

| 函数 | 说明 |
| --- | --- |
| `is_post()` | 是否 POST 请求 |
| `get($key, $default)` | 原始 GET 值 |
| `post($key, $default)` | 原始 POST 值 |
| `get_str($key, $default)` | GET，去首尾空格，转字符串 |
| `post_str($key, $default)` | POST，同上 |
| `get_int($key, $default)` | GET，转整型 |
| `client_ip()` | 访客 IP（只在 `trust_proxy=1` 时采信 `X-Forwarded-For`） |

::: warning 原始值不安全
`get()` / `post()` 拿到的是**未过滤**的值，只适合往下传参。要输出到页面必须经 `e()`；要进 SQL 必须用预处理参数。
:::

## 输出

| 函数 | 说明 |
| --- | --- |
| `e($str)` | HTML 转义（`htmlspecialchars`，UTF-8，双引号也转） |
| `json_out($data, $code = 200)` | 输出 JSON 并结束 |
| `redirect($url)` | 302 跳转并结束 |
| `flash($msg, $type)` | 存一条一次性提示 |
| `flashes()` | 取出并清空所有提示 |
| `str_limit($str, $len, $suffix)` | 截断（先去 HTML） |
| `format_bytes($bytes)` | `1536` → `1.5 KB` |

## 会话与安全

| 函数 | 说明 |
| --- | --- |
| `cms_session_start()` | 按需启动会话（重复调用安全） |
| `csrf_token()` | 生成/取当前令牌 |
| `csrf_field()` | 输出 `<input type="hidden" name="_token">` |
| `csrf_verify()` | 校验令牌，不符直接 403 并结束 |
| `clean_html($html)` | 净化 HTML：去 `<script>`、`on*`、危险协议 |
| `cms_url_public_check($url)` | 是否公网地址，返回空串表示通过 |

```php
csrf_verify();                                   // 后台每个 POST 的第一行
echo csrf_field();                               // 表单里
if (($err = cms_url_public_check($url)) !== '') { echo $err; }
```

## URL

| 函数 | 说明 |
| --- | --- |
| `site_url($path = '')` | 拼站点地址 |
| `u_home()` | 首页地址 |
| `u_post($post)` | 文章地址 |
| `u_cat($cat)` | 栏目地址 |
| `u_page($page)` | 单页地址 |
| `u_tag($name)` | 标签地址 |
| `u_search($kw)` | 搜索地址 |
| `cat_dir_of($catId)` | 取栏目目录名 |
| `post_static_rel($post)` | 文章静态文件相对路径 |
| `cat_static_url($catId)` | 栏目静态地址 |
| `tag_slug($name)` | 标签的 URL 片段（名字 MD5 前 10 位） |
| `detect_site_url()` | 自动探测站点地址（安装时用） |
| `cms_static_mode()` | 当前是否静态模式（有没有 `index.html`） |

::: tip 为什么标签用哈希
标签名可能是中文、可能带空格斜杠。用 `md5(名字)` 前 10 位当文件名，既避免编码问题，又保证唯一。代价是不能自定义标签路径。
:::

## 主题与渲染

| 函数 | 说明 |
| --- | --- |
| `theme_name()` | 当前主题名 |
| `theme_path($path = '')` | 主题的磁盘路径 |
| `theme_url($path = '')` | 主题的 URL |
| `render($tpl, $vars)` | 渲染标签模板（`.html`），返回 HTML |
| `render_php_file($file, $vars)` | 渲染 PHP 模板 |
| `cms_finalize_html($html)` | 跑 `html_final` 钩子 |

```php
$html = render('article', array('post' => $post, 'site' => $site));
```

同名 `.html` 和 `.php` 同时存在时，`render()` 优先 `.html`。

## 内容处理 `includes/content.php`

| 函数 | 说明 |
| --- | --- |
| `render_content($src)` | 围栏 → `<pre><code>`，返回 HTML |
| `content_source($html)` | 从 HTML 反推原文（兼容老数据） |
| `render_content_clean($src)` | 渲染 + 净化一步到位 |
| `content_plain($html)` | 提取纯文本（排除代码块） |
| `code_block_html($code, $lang)` | 单个代码块转 HTML |
| `code_lang_map()` | 语言标识映射表 |
| `code_lang_label($lang)` | 语言显示名 |
| `code_blocks_mask($html)` | 把代码块临时替换成占位符（处理摘要时用） |

## 文本工具 `includes/functions.php`

| 函数 | 说明 |
| --- | --- |
| `make_excerpt($html, $len = 120)` | 从正文自动生成摘要 |
| `make_slug($str)` | 生成 URL 友好字符串 |
| `unique_slug($slug, $table, $id)` | 保证 slug 不重复 |
| `normalize_tags($str)` | 标签去重、去空、统一分隔符 |
| `format_date($ts, $fmt)` | 时间格式化 |
| `human_date($ts)` | 相对时间（3 天前） |

## 文件

| 函数 | 说明 |
| --- | --- |
| `ensure_dir($dir)` | 目录不存在就建（递归） |
| `write_file_atomic($path, $data)` | 原子写：临时文件 + `rename` |
| `log_msg($msg, $level)` | 写日志到 `content/data/logs/` |
| `cms_is_system_rel($rel)` | 某个相对路径是不是系统区 |
| `cms_reserved_dirs()` | 保留目录名（不能当栏目别名） |
| `cms_system_dirs()` | 系统目录清单 |

::: danger 写文件一律走 write_file_atomic
直接 `file_put_contents($path, $data)` 在写入过程中被中断，会留下半个文件——前台就读到半截 HTML。
:::

## 上传

**图片** `includes/uploader.php`（类 `Cms_Image_Uploader`）

| 方法 | 说明 |
| --- | --- |
| `handle($file)` | 处理 `$_FILES` 项，返回数组或 false |
| `fromUrl($url)` | 按地址抓图（逐跳校验） |
| `fetchOnce($url)` | 抓一跳 |
| `absoluteUrl($base, $rel)` | 相对地址补全 |
| `urlCheck($url)` | 地址是否公网 |
| `error()` | 最近一次错误原因 |

**图片** `includes/upload.php`（函数式）

| 函数 | 说明 |
| --- | --- |
| `cms_upload_image($file)` | 上传图片 |
| `cms_upload_remote($url)` | 抓远程图 |
| `cms_upload_delete($path)` | 删图 + 删记录 |
| `cms_banner_clean($url)` | 校验横幅地址 |
| `cms_upload_exts()` | 允许的图片后缀 |
| `cms_upload_max_bytes()` | 大小上限（程序 5MB 与 php.ini 取小） |

**附件** `includes/fileupload.php`

| 函数 | 说明 |
| --- | --- |
| `cms_file_upload($file)` | 上传附件 |
| `cms_file_exts()` | 允许的后缀 |
| `cms_file_deny_exts()` | 明确拒绝的后缀 |
| `cms_file_ext_ok($name)` | 后缀校验 |
| `cms_file_max_bytes()` | 大小上限（20MB 与 php.ini 取小） |
| `cms_file_guard_dir($dir)` | 写防护 `.htaccess` |
| `cms_file_delete($path)` | 删附件 |
| `cms_file_clean($dir)` | 清空目录 |

## 前台数据 `includes/frontend.php`

| 函数 | 说明 |
| --- | --- |
| `fe_per_page()` | 每页条数 |
| `fe_site()` | 站点信息数组 |
| `fe_cats()` | 所有栏目 |
| `fe_cat_map()` | 栏目 ID → 栏目 的映射 |
| `fe_cat_by_dir($dirname)` | 按别名取栏目 |
| `fe_cat_by_id($id)` | 按 ID 取栏目 |
| `fe_posts($cond)` | 查文章列表 |
| `fe_count($cond)` | 查总数 |
| `fe_cat_counts()` | 每个栏目的文章数 |
| `fe_tags_all()` | 所有标签及计数 |
| `fe_base_vars()` | 组装模板基础变量 |
| `fe_pager($page, $total, $perPage)` | 组装分页数据 |
| `pager_numbers($pager, $size)` | 生成页码数组 |
| `fe_find_post($slug)` | 按 slug 找文章 |
| `fe_find_page($slug)` | 按 slug 找单页 |

## 静态生成 `includes/builder.php`

| 函数 | 说明 |
| --- | --- |
| `build_all()` | 全量生成 |
| `build_incremental()` | 增量生成 |
| `build_plan_all()` | 算出全量要生成的任务列表 |
| `build_plan_incremental()` | 增量任务列表 |
| `build_start($tasks)` | 建立分批任务并返回总数 |
| `build_tick($seconds)` | 干 `$seconds` 秒，返回进度 |
| `build_pending()` | 还剩多少任务 |
| `build_finish()` | 收尾：写清单、清理、解锁 |
| `build_put($rel, $html)` | 写一个静态文件（原子 + 白名单） |
| `build_allowed_ext()` | 允许写的后缀 |
| `build_cleanup()` | 按清单清理残留 |
| `static_clean()` | 清空所有生成文件 |
| `static_forget($rel)` | 从清单里移除一项 |
| `static_files_count()` | 当前静态文件数 |
| `build_sitemap()` | 生成 `sitemap.xml` |
| `build_rss()` | 生成 `rss.xml` |
| `build_reserved_slugs()` | 保留 slug（不能当栏目别名） |

## 备份 `includes/backup.php`

| 函数 | 说明 |
| --- | --- |
| `db_backup()` | 导出全库 SQL |
| `db_backup_list()` | 列出备份文件 |
| `db_backup_find($name)` | 找一份备份（**白名单校验**） |
| `db_backup_delete($name)` | 删一份备份 |
| `db_backup_size()` | 备份总占用 |
| `db_restore_file($path)` | 执行恢复 |
| `db_sql_split($sql)` | 切分 SQL 语句 |
| `db_restore_statement_allowed($stmt)` | 语句白名单判断 |
| `db_backup_tables()` | 本程序的表清单 |

## 清理 `includes/clean.php`

| 函数 | 说明 |
| --- | --- |
| `cms_clean_scan()` | 扫描，返回结构体 |
| `cms_clean_refs()` | 建立引用索引 |
| `cms_clean_is_used($name, $refs)` | 是否被引用 |
| `cms_clean_delete_many($paths)` | 批量删除（重新核对） |
| `cms_clean_forget_record($id)` | 清失效记录 |
| `cms_clean_skip($name)` | 是否跳过该文件 |
| `cms_clean_is_image_name($name)` | 按后缀判断是不是图 |

## 插件 `includes/plugin.php`

| 函数 | 说明 |
| --- | --- |
| `cms_add_filter($hook, $cb, $prio = 10)` | 注册过滤器 |
| `cms_add_action($hook, $cb, $prio = 10)` | 注册动作 |
| `cms_apply_filters($hook, $value, ...)` | 执行过滤器，返回处理后值 |
| `cms_do_action($hook, ...)` | 执行动作 |
| `cms_plugin_scan()` | 扫描插件目录 |
| `cms_plugin_load()` | 加载已启用的插件 |
| `cms_plugin_active($id)` | 某插件是否启用 |
| `cms_plugin_save_active($ids)` | 保存启用列表 |
| `cms_plugin_active_ids()` | 当前启用的 ID 列表 |
| `cms_plugin_url($id, $path = '')` | 插件资源 URL |
| `cms_plugin_path($id, $path = '')` | 插件磁盘路径 |
| `cms_plugin_id_ok($id)` | ID 合法性 |
| `cms_plugin_admin_pages()` | 已注册的后台页 |
| `cms_hook_docs()` | 钩子文档数据（后台展示用） |

## 更新 `includes/updater.php`

| 函数 | 说明 |
| --- | --- |
| `update_check($force = false)` | 检查更新 |
| `update_remote_info()` | 取远端提交信息 |
| `update_has_new()` | 是否有新版 |
| `update_available_cached()` | 从缓存读"有没有新版" |
| `update_apply()` | 执行更新（加锁） |
| `update_install_zip($zipPath)` | 安装 zip 包 |
| `update_rollback($name)` | 回滚到某份备份 |
| `update_backups()` | 列出可用备份 |
| `update_backup_system()` | 备份系统区 |
| `update_write_file($rel, $data)` | 写一个文件（白名单 + 原子） |
| `update_path_allowed($rel)` | 路径是否允许覆盖 |
| `update_writable_preflight()` | 动手前检查权限 |
| `update_open_dirs()` / `update_close_dirs()` | 临时放开 / 恢复目录权限 |
| `update_prune_backups()` | 只留最近 3 份 |
| `update_notify($info)` | 发通知邮件 |
| `update_cron_token()` | 取/生成 cron 令牌 |
| `update_cron_url()` | 拼 cron 地址 |
| `update_lock()` / `update_unlock()` | 并发锁 |

## 邮件 `includes/mailer.php`

| 函数 | 说明 |
| --- | --- |
| `cms_mail_send($to, $subject, $body)` | 统一发信入口 |
| `cms_mail_config()` | 读邮件设置 |
| `cms_mail_addr($addr)` | 规范化邮箱地址 |
| `cms_smtp_send(...)` | SMTP 发信 |
| `cms_smtp_cmd($fp, $cmd)` | 发命令并读回复 |
| `cms_smtp_read($fp)` / `cms_smtp_code($line)` | 读回复 / 解析状态码 |

## 登录 `includes/auth.php`

| 函数 | 说明 |
| --- | --- |
| `auth_login($user, $pass)` | 校验并登录 |
| `auth_user()` | 当前用户 |
| `auth_check()` | 是否已登录 |
| `require_login()` | 未登录则跳登录页 |
| `auth_logout()` | 退出 |
| `login_guard_check()` | 是否被锁 |
| `login_guard_fail()` | 记一次失败 |
| `login_guard_clear()` | 清失败记录 |
| `login_guard_file()` | 限速状态文件路径 |

## 后台 UI `includes/functions.php`

| 函数 | 说明 |
| --- | --- |
| `admin_url($p = '')` | 拼后台地址 |
| `admin_pager_html($pager, $base)` | 生成后台分页 HTML |
| `cms_permission_audit()` | 目录权限自检数据 |
| `is_ajax()` | 是否 AJAX 请求 |

## 标签引擎 `includes/tags/`

编译期工具，一般不需要直接调用，加自定义标签时才用：

| 函数 | 说明 |
| --- | --- |
| `cms_tag_registry()` | 标签注册表 |
| `cms_tag_compile($tpl)` | 把模板编译成 PHP |
| `cms_tag_parse($tpl)` | 解析标签结构 |
| `cms_tag_locate($name)` | 找标签实现文件 |
| `cms_tag_is_block($name)` | 是不是块标签 |
| `cms_tag_attrs($str)` | 解析属性串 |
| `cms_tag_filters_code($filters)` | 修饰器转 PHP 代码 |
| `cms_tag_where($attrs)` | `list` 标签的 where 条件生成 |
| `cms_flag_map()` | 推荐位代号映射 |
| `cms_tag_field_alias()` | 字段别名表 |

## 下一步

[钩子一览](/dev/hooks) · [数据表字典](/dev/database)
