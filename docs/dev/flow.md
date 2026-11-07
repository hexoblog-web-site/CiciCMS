# 请求是怎么走完一圈的

四个最典型的流程，按"从请求到响应"的顺序拆开。读完之后，出问题知道该在哪一步查。

## 一、访客访问一个文章页

```
浏览器  GET /tech/12.html
   │
   ├─ Apache/Nginx 看磁盘上有没有这个文件
   │     有 → 直接返回（PHP 完全不参与）（99.9% 的情况）
   │     没有 → 404，显示生成好的 /404.html
   │
   └─ 另有极少数请求直接打入口 index.php
         └─ /index.php?s=关键词        站内搜索（前台唯一动态入口）
               ├─ 查库：按关键词匹配已发布文章
               ├─ fe_render_search() 组装变量
               ├─ render('模板名', $vars) 套模板
               └─ 输出 HTML（不写盘，只给这一次请求）
```

**关键点**

| 情况 | 谁在处理 | 数据库 |
| --- | --- | --- |
| 静态文件存在 | Web 服务器 | **不碰** |
| 静态文件不存在 | 404（静态页） | 不碰 |
| 站内搜索 | `index.php` | 查 |

旧的 `index.php?p=` / `?cat=` / `?single=` / `?tag=` 动态路由已全部移除，访问一律 404；
后台「预览」按钮直接指向已生成的静态页，草稿与未保存的内容则在后台弹窗里渲染，不产生公开动态地址。

::: tip 这就是"静态"的全部意义
第一种情况占 99.9%。这时候 PHP 根本没启动，数据库连接都没建立，所以：

- 数据库挂了，前台照常访问
- 访客再多，压力全在文件读取上，跟 Nginx 发图片一个量级
:::

## 二、后台保存一篇文章

```
POST /admin/index.php?p=article&do=save
   │
   ├─ bootstrap.php
   │    ├─ 常量 / 配置 / 连库
   │    ├─ cms_session_start()（后台必开）
   │    ├─ 结构升级检查
   │    └─ 加载启用的插件
   │
   ├─ require_login()            ← 没登录 → 跳登录页
   ├─ csrf_verify()              ← 令牌不符 → 403
   │
   ├─ 接收字段、校验（标题必填、slug 去重）
   ├─ render_content($src)       ← 围栏 → HTML
   ├─ clean_html($html)          ← 净化
   ├─ insert_row / update_row    ← 写 article，两个字段都存
   │
   ├─ cms_do_action('article_saved', $id, $data, $isNew)
   │
   └─ 如果 auto_build = 1
        └─ build_incremental()   ← 只重建受影响的页面
             └─ flash('已保存') → redirect 回列表
```

**受影响的页面有哪些**

改一篇文章，要重建的不止它自己：

| 页面 | 为什么 |
| --- | --- |
| 文章页 | 内容变了 |
| 所属栏目页 | 列表里那一项变了 |
| 首页 | 如果它出现在首页 |
| 归档页 | 按年月分组 |
| 标签页 | 如果标签变了 |
| `sitemap.xml` | 地址没变但更新时间变了 |

`build_incremental()` 就是算这个集合，而不是全量重来。

## 三、全量生成（分批执行）

文章多的时候一次请求做不完，会撞上 PHP 的 `max_execution_time`（常见 30 秒）。所以生成是**分批**的：

```
点击「全量生成」
   │
   ├─ POST 建任务：build_start($tasks)
   │     └─ 算出所有要生成的文件，存进状态文件
   │     └─ flock 加锁（第二次点会提示"正在生成"）
   │
   ├─ 前端循环请求 ?p=build&action=tick
   │     ├─ 每次给 build_tick(8.0) 一个 8 秒预算
   │     ├─ 干完一批就把进度写进状态文件
   │     ├─ 返回 {finished: false, done: 34, total: 120}
   │     └─ 前端更新进度条，再请求一次
   │
   └─ 全部做完：build_finish()
         ├─ 写清单 content/data/static.json
         ├─ build_cleanup() 删掉"上次有这次没有"的文件
         ├─ 解锁
         └─ cms_do_action('build_done', $files, $errors)
```

**为什么用 8 秒而不是"能跑多久跑多久"**

| 做法 | 问题 |
| --- | --- |
| 一次跑完 | 大站必超时，跑到一半被杀，状态丢了 |
| 每篇一个请求 | 请求数爆炸，开销比干活还大 |
| **8 秒一批** | 单次请求一定在超时线内，进度能保存，随时能中断 |

状态文件在 `content/data/build.json`，所以中途关掉浏览器再点一次，会**接着上次的进度**继续。

::: warning 每个文件写失败都只记错误，不中断整轮
一个模板渲染出空，不会让剩下 200 个文件都不生成。错误累计起来在结束时一起报，日志里能看到具体是哪个模板。
:::

## 四、在线更新

```
点击「立即更新」
   │
   ├─ update_lock()                      ← 加锁，防并发
   ├─ update_writable_preflight()        ← 检查所有目标目录可写
   │     不能写 → 直接拦下，一个字都不改
   │
   ├─ update_open_dirs()                 ← 临时 chmod 放开权限
   ├─ update_backup_system()             ← 备份到 content/data/backup/update/<时间>-<版本>/
   │
   ├─ update_download()                  ← 从 codeload 拉 zip
   ├─ Cms_Zip_Reader 解包
   │     ├─ 确认包含 bootstrap.php / admin/index.php / index.php
   │     ├─ 读出版本号，比当前低 → 拒绝
   │     └─ 逐个文件：update_path_allowed() 白名单检查
   │           ├─ includes/**  admin/**  index.php  api.php  .htaccess  README  LICENSE  → 允许
   │           └─ 其它（含 ../ 绝对路径 content/**）→ 跳过
   │
   ├─ update_write_file()                ← 写临时名 → rename 覆盖
   │     任何一个失败 → 整批 update_rollback()
   │
   ├─ update_clear_cache()               ← 清模板缓存
   ├─ update_close_dirs()                ← 权限改回原样（成功或失败都执行）
   └─ update_unlock()
```

**为什么必须先 preflight**

想象一下：更新到第 30 个文件时发现 `admin/` 不可写。前 29 个已经覆盖了，程序半新半旧，前台可能直接 500。

所以动手前先把所有目标目录试一遍 `chmod`，全通过才开始。这是"要么全成，要么不动"的保证。

**回滚做了什么**

```
update_restore($backupName)
   ├─ 把备份里的文件写回去
   ├─ update_prune_extra()  ← 删掉"更新时新增、备份里没有"的文件
   ├─ 清模板缓存
   └─ 权限恢复
```

第二步容易被忽略：如果不删，回到旧版之后还留着新版才有的文件，可能出现"旧代码 + 新文件"的奇怪状态。

## 一张总表

| 流程 | 起点 | 关键函数 | 会写盘吗 |
| --- | --- | --- | --- |
| 访问静态页 | Web 服务器 | — | 否 |
| 访问动态页 | `index.php` | `fe_render_*` | 否 |
| 保存文章 | `admin/pages/article.php` | `render_content` `build_incremental` | 数据库 + 静态页 |
| 全量生成 | `admin/pages/build.php` | `build_start` `build_tick` `build_finish` | 静态页 + 状态 |
| 在线更新 | `admin/pages/update.php` | `update_apply` `update_install_zip` | 程序文件 + 备份 |

## 出问题该看哪里

| 现象 | 先看 |
| --- | --- |
| 前台 500 | `content/data/logs/` 当天日志 |
| 前台 404 | 静态文件在不在；栏目别名对不对 |
| 生成报"写入失败" | 根目录权限 |
| 生成卡在某个百分比 | 那个模板文件（日志里有名字） |
| 后台 POST 403 | CSRF 令牌过期，刷新页面重试 |
| 更新失败 | 日志里的原因 + `update/` 备份目录 |

## 下一步

[部署配置](/ops/deploy)
