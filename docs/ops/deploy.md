# 部署配置

程序是纯静态优先：请求直接命中磁盘上的 `.html`（或目录下的 `index.html`），**没有命中就是 404**，不存在"交给 PHP 再处理"的链路。所以**不需要任何伪静态/rewrite 规则**。

站内搜索这类请求是**直接访问入口**（`/index.php?s=关键词`），不依赖 URL 重写；除此之外前台没有其他动态地址。

## Apache

### 只要一件事：允许 `.htaccess` 生效

程序根目录已经带了 `.htaccess`，里面做了这些事：

```apache
DirectoryIndex index.html index.php     # 静态首页优先
ErrorDocument 404 /404.html             # 404 用生成好的静态页
Options -Indexes                        # 关目录列表（IfModule 包裹）
Require all denied                      # 挡住 config.php / *.log / *.sql / *.lock
ExpiresByType …                         # css/js/图片 缓存头
```

虚拟主机一般默认开着。如果是自己的服务器，确认 `httpd.conf` 里：

```apache
<Directory /www/wwwroot/example.com>
    AllowOverride All
    Require all granted
</Directory>
```

::: danger AllowOverride None 的后果
`.htaccess` 完全不生效，等于：

- `config.php` 可能被直接下载（里面有数据库密码）
- `includes/` 下的 PHP 可能被直接请求
- 目录列表可能被打开

看到 500 又找不到原因时，先确认这个设置。
:::

### 子目录部署

放在 `https://example.com/blog/` 下也能跑。装的时候「站点地址」填 `https://example.com/blog`（不带斜杠），程序会自动把 URL 前缀算对。

## Nginx

Nginx **不读 `.htaccess`**，所有规则必须写进配置文件。以下是一份可直接改用的模板：

```nginx
server {
    listen 80;
    server_name example.com;
    root /www/wwwroot/example.com;
    index index.html index.php;

    charset utf-8;

    # ① 纯静态优先：文件（或目录下的 index.html）存在就直接给，不存在就是 404
    #    注意这里是 =404，不是转给 index.php。
    #    动态入口（搜索 / 预览）直接访问 /index.php?... 即可，不靠 rewrite。
    location / {
        try_files $uri $uri/ =404;
    }

    # ② 挡住系统代码、配置、日志、备份
    location ~ ^/includes/            { deny all; }
    location ~ ^/admin/inc/           { deny all; }
    location ~ ^/config\.php$         { deny all; }
    location ~* \.(log|lock|sql|bak|ini)$ { deny all; }
    location ~ ^/content/data/        { deny all; }

    # ③ 内容区里的 PHP 一律不能被执行
    location ~* ^/content/(uploads|themes|plugins)/.*\.(php|phtml|phar|pht|inc|php[0-9])$ {
        deny all;
    }
    location ~* ^/content/plugins/.*\.(json|md)$ {
        deny all;
    }

    # ④ PHP 交给 FPM（必须放在上面几条 deny 之后）
    location ~ \.php$ {
        include        fastcgi_params;
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 120s;
    }

    # ⑤ 404 用生成好的静态页
    error_page 404 /404.html;
}
```

::: danger 顺序不能乱
Nginx 的正则 location 是**按出现顺序**匹配的。

如果把 `location ~ \.php$` 写在 ③ 前面，`/content/plugins/x/admin.php` 会先命中它，被交给 PHP-FPM **直接执行**——那就等于把插件目录的门敞开了。

**③ 必须在 ④ 之前。**
:::

::: warning 第 ③ 条不能省
Apache 用户因为 `content/plugins/.htaccess` 和 `content/themes` 的保护，挡住了直接请求。**Nginx 不读 `.htaccess`**，少了 ③ 就等于：

- 别人能直接访问 `https://你的站/content/plugins/插件/admin.php`
- 别人能直接访问主题里的 `.php` 模板

实测这类"抄了 Apache 配置就以为万事大吉"的站，是 Nginx 上最常见的翻车点。
:::

### 装完之后

装好并确认能跑，把安装目录也挡掉（或者直接删）：

```nginx
location ~ ^/install/ { deny all; }
```

## 其他服务器

| 服务器 | 要做什么 |
| --- | --- |
| **IIS / Windows** | 默认文档加 `index.html`；`404` 指向 `/404.html`；`.htaccess` 无效，需要自己配 URL 重写或直接依赖"静态优先" |
| **宝塔面板** | 直接选 LNMP/LAMP，把站点根目录指到程序目录；宝塔会自动加 PHP 处理块，**你需要手动补 ②③ 两条** |
| **CDN** | 整站可回源到站点根目录；缓存规则给 `.html` 设短一点（比如 10 分钟），否则更新后要手动刷 |

## 缓存建议

| 类型 | 建议缓存 |
| --- | --- |
| `.html` | 10 分钟 – 1 小时（太长了更新看不到） |
| `.css` `.js` | 7 天 |
| 图片 | 30 天（文件名带时间戳，不会撞） |
| `.xml`（sitemap/rss） | 1 小时 |

程序自带的 `.htaccess` 已经给 css/js 设了 7 天、图片 30 天。HTML 故意没设——静态站最怕"更新了但用户看到旧的"。

## 部署检查清单

部署完，从上往下核一遍：

- [ ] 根目录能直接看到 `index.php`、`includes/`、`admin/`、`content/`
- [ ] 访问首页显示的是静态页（响应头里没有 `X-Powered-By: PHP`）
- [ ] 访问 `https://你的站/config.php` → **403 或 404**，不能下载
- [ ] 访问 `https://你的站/includes/db.php` → **403 或 404**
- [ ] 访问 `https://你的站/content/plugins/highlight/plugin.php` → **403 或 404**
- [ ] 访问 `https://你的站/content/data/logs/` → **403 或 404**
- [ ] 访问一个不存在的地址 → 显示自己的 404 页，不是服务器默认页
- [ ] 后台「系统设置 → 目录权限自检」没有黄色项
- [ ] `install/` 已删除
- [ ] `config.php` 权限 644

::: tip 一条命令快速验一遍
```bash
for p in config.php includes/db.php content/plugins/highlight/plugin.php content/data/logs/; do
  echo -n "$p -> "; curl -s -o /dev/null -w "%{http_code}\n" "https://你的站/$p"
done
```

期望输出全是 `403` 或 `404`。出现 `200` 就是漏了。
:::

## 下一步

[权限模型](/ops/permission) —— 这套设计为什么这么分
