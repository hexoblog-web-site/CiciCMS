# 代码地图

想读源码、改功能、写插件，从这一页开始。目标不是把 1000 多个函数都背下来，而是**知道什么功能在哪个文件里**。

## 启动流程

任何入口（前台 `index.php`、后台 `admin/index.php`、`api.php`）第一件事都是加载同一个引导文件：

```
index.php ─┐
api.php   ─┼→ includes/bootstrap.php ─→ 所有模块按需加载
admin/*.php┘
```

`bootstrap.php` 做的事，按顺序：

1. 定义常量（根目录、内容目录、版本号…）
2. 读 `config.php`（数据库配置）
3. 注册错误处理、开异常捕获，日志写 `content/data/logs/`
4. 连数据库（PDO）
5. 按需启动会话（前台静态请求不建 session）
6. 跑数据库结构升级（版本号不一致时）
7. 加载插件（只加载启用的）

## 关键常量

| 常量 | 值 | 用途 |
| --- | --- | --- |
| `CMS_ROOT` | 站点根目录绝对路径 | 拼文件路径 |
| `CMS_INC` | `includes/` | — |
| `CMS_CONTENT` | `content/` | — |
| `CMS_CONTENT_REL` | `content` | 拼 URL 用 |
| `CMS_THEMES` | `content/themes` | — |
| `CMS_UPLOADS` | `content/uploads` | — |
| `CMS_DATA` | `content/data` | 日志、缓存、备份 |
| `CMS_PLUGINS` | `content/plugins` | — |
| `CMS_VERSION` | `2.1.9` | 版本比对 |
| `CMS_ADMIN` | `true`（仅后台） | 判断"是否在后台上下文" |
| `CMS_DEBUG` | `false` | 生产环境不暴露堆栈 |

::: tip 插件和模板里的第一行
```php
if (!defined('CMS_ROOT')) { exit; }
```
`CMS_ROOT` 没定义 = 不是被程序 include 进来的 = 有人在直接请求这个文件 → 直接退出。
:::

## 目录与职责

```
/
├── index.php            前台入口：仅站内搜索，其余 404
├── api.php              浏览量计数（POST）
├── config.php           安装时生成
│
├── includes/            【系统区】核心代码
│   ├── bootstrap.php    ★ 引导：常量、配置、会话、错误、插件加载
│   ├── db.php           PDO 薄封装：q / all / one / val / insert_row / update_row / delete_row
│   ├── functions.php    ★ 公共函数：URL、模板渲染、CSRF、文件、分页、权限自检
│   ├── content.php      正文渲染：代码围栏 → HTML、摘要提取、净化
│   ├── auth.php         登录、会话、失败限速
│   ├── frontend.php     ★ 前台数据查询 + 页面渲染（九种页面）
│   ├── builder.php      ★ 静态生成器（全量 / 增量 / manifest 清理 / sitemap / rss）
│   ├── upgrade.php      数据库结构自动升级
│   ├── backup.php       数据库备份与恢复
│   ├── uploader.php     ★ 图片上传器（唯一实现）
│   ├── upload.php       上传的函数式封装（媒体信息、栏目横幅）
│   ├── clean.php        系统清理：扫描未被引用的文件
│   ├── updater.php      ★ 在线更新（1191 行，最复杂的一个）
│   ├── zipreader.php    zip 读取（不依赖 ZipArchive）
│   ├── mailer.php       发信：mail() 和 SMTP
│   ├── plugin.php       ★ 插件内核：钩子、扫描、加载
│   ├── tags.php         标签引擎入口
│   └── tags/            标签引擎实现（见下）
│
├── admin/               【后台】
│   ├── index.php        单入口路由（?p=页面名）
│   ├── login.php        登录页
│   ├── upload.php       编辑器插图接口（JSON）
│   ├── download.php     备份下载（白名单）
│   ├── cron.php         计划任务入口（token）
│   ├── inc/             布局（头、尾、菜单）
│   ├── assets/          admin.css / admin.js
│   └── pages/           13 个页面，一个功能一个文件
│
├── install/             安装向导（装完删除）
│
└── content/             【内容区】
    ├── themes/default/  默认主题
    ├── uploads/         图片、附件
    ├── plugins/         插件
    └── data/            缓存、日志、备份、更新状态
```

## 标签引擎

模板里那些 `{cici:xxx}` 是这个目录在干活：

| 文件 | 职责 |
| --- | --- |
| `tags.php` | 入口，只管加载顺序 |
| `tags/registry.php` | 注册表：扫描下面三个目录，建立"标签名 → 实现"的映射 |
| `tags/engine.php` | 扫描器：把模板里的标签编译成 PHP 代码，带缓存 |
| `tags/context.php` | 编译期工具：解析属性、修饰器、路径 |
| `tags/config.php` | 推荐位映射、字段别名 |
| `tags/single/` | 单标签：`url` `theme` `year` `include` `pager` `field` `global` |
| `tags/block/` | 块标签：`list` `if` `nav` `tags` `archive` `flink` `each` `loop`… |
| `tags/runtime/` | 运行时函数：编译产物直接调用 |
| `tags/fields/` | 字段级覆盖（如 `fields/title.php`） |

::: tip 一个标签一个文件
想加一个 `{cici:weather}` 标签？在 `tags/single/` 下建 `weather.php`，注册表会自动扫到。不用改任何核心代码。
:::

编译后的模板缓存在 `content/data/cache/tpl/`，模板改动时按 `mtime` 自动失效。

## 十三行后台页面

`admin/pages/` 下一个文件就是一个菜单项，没有框架、没有 MVC：

| 文件 | 菜单 |
| --- | --- |
| `dashboard.php` | 仪表盘 |
| `article.php` | 文章管理（最大，27KB） |
| `category.php` | 栏目管理 |
| `page.php` | 单页管理 |
| `media.php` | 媒体信息 |
| `flink.php` | 友情链接 |
| `build.php` | 生成静态 |
| `clean.php` | 系统清理 |
| `data.php` | 数据系统 |
| `theme.php` | 模板管理 |
| `setting.php` | 系统设置 |
| `plugin.php` | 插件 |
| `update.php` | 系统更新（27KB） |

每个页面文件的结构都一样：

```php
<?php
if (!defined('CMS_ADMIN')) { http_response_code(404); exit; }

// 1. 处理 POST（先 csrf_verify()）
// 2. 查数据
// 3. 输出 HTML
```

## 建议的阅读顺序

按这个顺序读，每一步都建立在上一步之上：

| 顺序 | 文件 | 读完你会知道 |
| --- | --- | --- |
| 1 | `includes/bootstrap.php` | 程序怎么起来的 |
| 2 | `includes/db.php` | 数据库怎么用的（就 100 行） |
| 3 | `includes/functions.php` | 公共设施：URL 怎么拼、模板怎么渲染 |
| 4 | `index.php` → `includes/frontend.php` | 前台一个请求怎么变成页面 |
| 5 | `includes/builder.php` | 静态页是怎么写出来的 |
| 6 | `includes/content.php` | 正文怎么被处理 |
| 7 | `admin/index.php` → 任一 `admin/pages/*.php` | 后台怎么组织的 |
| 8 | `includes/tags/engine.php` | 标签模板怎么变成 PHP |
| 9 | `includes/plugin.php` | 钩子怎么工作 |
| 10 | `includes/updater.php` | 在线更新（最长，最后看） |

## 代码风格

读之前知道这几条，能省不少困惑：

| 约定 | 说明 |
| --- | --- |
| 不用命名空间 | 全部是全局函数，靠前缀区分（`cms_` / `build_` / `update_` / `fe_` / `db_`） |
| 不用类，除了三个 | `Cms_Image_Uploader`、`Cms_Zip_Reader`，还有 PDO 本身 |
| 数组用 `array()` | 兼容 PHP 5.4+ 的老写法，不是 `[]` |
| 缩进 4 空格 | 没有 PSR-12 强制，但整体一致 |
| 中文注释 | 关键逻辑都有注释，尤其是"为什么这么写" |
| 不用 Composer | 没有 `vendor/`，所有依赖自己写 |

## 下一步

- [文件说明](/dev/files) —— 每个文件具体干什么
- [函数手册](/dev/functions) —— 按功能分类的函数清单
- [请求是怎么走完一圈的](/dev/flow) —— 四个典型流程的时序
- [钩子一览](/dev/hooks) —— 写插件要知道的
- [数据表字典](/dev/database) —— 七张表的字段
