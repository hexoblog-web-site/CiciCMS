# Nebula 后台接入说明

## 入口

- 后台首页：`/admin/index.php`（已重定向到 `/admin/nebula/index.php`）
- 接口：`/admin/api.php`
- 老后台代码完整保留在 `admin/pages/`、`admin/inc/`、`admin/index-legacy.php`

## 工作原理

原型 `nebula-cms.html` 的界面代码（HTML/CSS/JS）**原样保留**，只把三个数据接口换掉：

| 原型 | 现在 |
|---|---|
| `load()` 读 localStorage | 同步 GET `api.php?r=state`，由 `includes/nebula-store.php` 把 7 张表组装成 `db` 对象 |
| `save()` 写 localStorage | debounce 260ms 后 POST `api.php?r=save`，增量写回 MySQL；关页时 `sendBeacon` 兜底 |
| `login()` 前端比对明文密码 | PHP 会话鉴权；未登录跳 `admin/login.php` |
| 一键更新（浏览器里模拟） | 轮询 `api.php?r=build`，由 `build_tick()` 真实写入静态文件 |

界面其余 4000 多行逻辑未改动。

## 字段映射

| 原型 db | MySQL 表 | 关键转换 |
|---|---|---|
| `posts` | `article` | `tags` 数组 ↔ 逗号串；`md` ↔ `content_src` + `content`（PHP 渲染） |
| `pages` | `page` | 展示开关（inNav/visible/wide…）存 `config.nebula_pages_meta` |
| `categories` | `category` | name/dirname/description/keywords/banner 都有真列 |
| `media` | `attachment` | file↔path、post↔content_id、trashedAt↔deleted_at |
| `settings` | `config` | site_name / site_description / site_keywords / logo 等 |
| `users` | `user` | 密码哈希不下发浏览器 |
| 其余（revisions/templates/backups/ftp/catTrash…） | `config.nebula_extra` | 整块 JSON，原样带回 |

`status` 映射：publish=1、draft=0、pending=2、trash=3。构建器只看 `status = 1`，语义不变。

## 安全阀

批量保存时若文章/单页/媒体数量一次下降超过 30%（且原数量 > 5），API 返回 409 拒绝保存。
这是防止前端数据没拉全就回写导致误删。确需批量删除时分多次操作。

## 与原型不同的地方

1. **账号**：原型三个演示账号可随意切换；现在只有 PHP 会话里的管理员，账号菜单已去掉切换入口。
2. **媒体**：没有上传区（按之前的要求）；图片在文章编辑器里上传，这里只展示与检索。
3. **模板管理**：原型里的模板是浏览器端导出器用的，与 PHP 构建器读的 `content/themes/` 不是同一套。后台保留了页面，但两边模板尚未打通。
4. **数据管理**：导出仍生成 db JSON；从 JSON 恢复尚未接 API，需要走老后台的数据恢复。
5. **一键更新**：「动态文件」模式目前也走同一个静态构建接口，没有真正输出 data.json + api.php。

## 回退

`admin/index.php` 顶部有两行：

```php
/* 新后台入口：删掉下面两行即回到老后台 */
redirect('nebula/index.php');
```

删掉即可回到老后台。老后台的 `admin/index-legacy.php` 是一份完整入口备份。

## 验证情况

- PHP：本机无 PHP 运行环境，未跑 `php -l`；用结构检查脚本核对了新增/改动文件的括号、字符串、注释配平（ALL OK）。
- JS：`node --check` 通过。
- 界面：用无头浏览器加载模拟数据，仪表盘、编辑器（中分屏）、媒体、站点设置、一键更新、标签页均正常渲染。
- **未做**：真实 PHP + MySQL 的端到端运行测试。上线前请在测试环境逐项点过：登录 → 改文章 → 保存 → 刷新确认 → 一键更新。
