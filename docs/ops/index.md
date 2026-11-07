# 部署运维

装好之后长期要维护的几件事：**配好服务器、设对权限、堵住洞、管住地址**。

## 四件事，按顺序做

| 顺序 | 做什么 | 看哪页 | 花多久 |
| --- | --- | --- | --- |
| 1 | 配置 Web 服务器（静态优先 + 挡住敏感路径） | [部署配置](/ops/deploy) | 10 分钟 |
| 2 | 设对目录权限（三区模型） | [权限模型](/ops/permission) | 5 分钟 |
| 3 | 上线前逐项核对安全清单 | [安全加固清单](/ops/security) | 15 分钟 |
| 4 | 确认 URL 规则与 SEO 设置 | [URL 与 SEO](/ops/url) | 10 分钟 |

## 先看这一张表

大多数部署问题就出在这几行：

| 项目 | 正确值 | 错了会怎样 |
| --- | --- | --- |
| 站点根目录 | 755 可写 | 生成时报"写入失败" |
| `content/` | 755 可写 | 传不上图、缓存写不进、后台白屏 |
| `includes/` `admin/` | 555 只读 | 设成可写 = 少了防线；设成 111 = 整站 500 |
| `config.php` | 644 | 可能被直接下载，泄露数据库密码 |
| `install/` | **已删除** | 别人能重装你的站 |
| Apache | `AllowOverride All` | `.htaccess` 全部失效 |
| Nginx | 配了 `content/(uploads\|themes\|plugins)` 的 deny | 插件 PHP 可被直接执行 |

## 三条最容易漏的检查

装完站，浏览器里手动试这三个地址：

```
https://你的站/config.php
https://你的站/includes/db.php
https://你的站/content/plugins/highlight/plugin.php
```

**三个都必须是 403 或 404。** 出现 200 就说明漏了规则，回去补。

::: tip 一条命令批量验
```bash
for p in config.php includes/db.php content/plugins/highlight/plugin.php content/data/logs/; do
  echo -n "$p -> "; curl -s -o /dev/null -w "%{http_code}\n" "https://你的站/$p"
done
```
:::

## 日常维护节奏

| 频率 | 做什么 |
| --- | --- |
| 每次改内容后 | 「生成静态」（开了自动生成就不用） |
| 每周 | 看一眼 `content/data/logs/` 有没有新报错 |
| 每月 | 「数据系统」备份 + **下载到本地**；跑一次「系统清理」看看废图 |
| 有新版本时 | 「系统更新」检查 → 备份 → 更新 → 前台抽查 |
| 每季度 | 复查一次目录权限和上面的三个地址 |

## 出问题去哪查

| 现象 | 先看 |
| --- | --- |
| 前台 500 | `content/data/logs/` 当天日志 |
| 前台 404 | 静态文件在不在；栏目别名改过没有 |
| 生成失败 | 根目录权限；日志里是哪个模板 |
| 后台白屏 | `content/data/` 不可写；PHP 版本太低 |
| 更新失败 | 日志原因 + `content/data/backup/update/` 里的备份 |
| 图片裂 | `content/uploads/` 权限；用了外链被防盗链 |

## 下一步

从 [部署配置](/ops/deploy) 开始。
