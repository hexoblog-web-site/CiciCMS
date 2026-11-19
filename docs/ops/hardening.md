# 生产环境加固清单（Nginx / PHP）

这一页是 `deploy.md`、`security.md`、`permission.md` 的补充：把**代码区只读**、**安全响应头**、**PHP 参数**三件事集中到一处，照着做即可。所有配置都是部署层，**不需要改程序源码**。

> 环境基线：Ubuntu 24.04 + Nginx 1.26 + PHP 8.3-FPM + MySQL 8.0，站点目录 `/www/wwwroot/www`，PHP-FPM 与 Nginx 同用 `www` 用户。其他环境按实际情况改路径。

## 一、代码区只读（最重要）

程序按「代码区只执行不写、内容区只写不执行」设计。系统区若可写，后台一旦被攻破就能被植入 webshell。

```bash
WWW=/www/wwwroot/www

# 系统区：只去写位，保留 r-x（切勿 chmod 111 / --x，否则 glob() 扫不到标签文件导致白屏）
chmod -R a-w "$WWW/includes" "$WWW/admin" "$WWW/install"

# 内容区：必须可写
chmod -R u+w "$WWW/content"

# 配置文件：装完只读（程序仅在安装时写一次）
chmod 444 "$WWW/config.php"
```

核对（后台「系统设置 → 目录权限自检」应全绿）：

```bash
ls -ld "$WWW/includes" "$WWW/admin" "$WWW/content"
ls -l  "$WWW/config.php"
```

**升级时必须先解开写锁**，否则覆盖会失败：

```bash
chmod -R u+w "$WWW/includes" "$WWW/admin"
# ... 覆盖 includes/ admin/ install/ 三个目录（content/ 与 config.php 不要动）...
chmod -R a-w "$WWW/includes" "$WWW/admin"   # 改回只读
```

> 关于 `config.php` 权限：官方基线是 **644**，上面给的是更严格的 **444**。若你的主机面板需要重写配置（如某些自动续期/迁移脚本），保持 444 会失败，此时退回 644 即可。两者都不会影响站点正常访问。

## 二、Nginx 安全响应头

在站点的 `server { }`（443 那段）里加入以下响应头：

```nginx
add_header X-Content-Type-Options    "nosniff" always;
add_header X-Frame-Options           "SAMEORIGIN" always;
add_header Referrer-Policy           "strict-origin-when-cross-origin" always;
add_header Permissions-Policy        "geolocation=(), microphone=(), camera=()" always;
# HSTS（仅当全站 HTTPS 稳定后再开；本程序站点已是全 HTTPS）
add_header Strict-Transport-Security "max-age=31536000" always;
```

检查：

```bash
curl -sI https://你的域名/ | grep -iE 'x-content|x-frame|referrer|permissions|strict-transport'
```

## 三、Nginx 压缩与缓存（可选，性能优化）

```nginx
# 静态资源缓存，HTML 短缓存保证更新可见
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?|ttf)$ {
    expires 7d;
    add_header Cache-Control "public, immutable";
}
location ~* \.xml$ { expires 1h; }
```

> HTML **不要**设长缓存，否则发文章后用户看到旧页面；程序自身生成静态页，靠短缓存 + CDN 刷新即可。

## 四、PHP-FPM 加固

编辑 `/etc/php/8.3/fpm/php.ini`（或对应版本）：

```ini
expose_php              = Off
allow_url_fopen         = Off      ; 程序自带 updater 用 curl，不依赖此项；若插件用到远程图再开
allow_url_include       = Off
disable_functions       = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,eval
upload_max_filesize     = 8M
post_max_size           = 16M
max_execution_time      = 60
session.cookie_httponly = 1
session.cookie_secure   = 1
session.use_strict_mode = 1
```

改完重载：

```bash
php -t  # 若有 php-fpm 配置测试命令则执行
systemctl restart php8.3-fpm
```

> 说明：后台会话 Cookie 在**程序代码层**已强制 `secure + HttpOnly + SameSite=Lax`（实测 `Set-Cookie: ...; secure; HttpOnly; SameSite=Lax`），所以上面的 session 项主要是给其他 PHP 应用兜底。`disable_functions` 里若含 `curl_exec` 会影响自带更新器抓 GitHub——**要用在线更新就别禁 curl_exec**。

## 五、自动备份（强烈建议）

程序后台「数据系统」有一键备份，但需手动点。用 cron 每天自动备份数据库 + 内容区：

```bash
# /etc/cron.d/cici-backup
0 3 * * * root /usr/bin/mysqldump -ucicicms -p'你的密码' cicicms | gzip > /www/wwwroot/www/content/data/backup/db-$(date +\%F).sql.gz
0 3 * * * root tar czf /www/wwwroot/www/content/data/backup/content-$(date +\%F).tar.gz -C /www/wwwroot/www content
# 保留最近 14 天
0 4 * * * root find /www/wwwroot/www/content/data/backup -name 'db-*.sql.gz' -mtime +14 -delete
```

> 备份目录在 `content/` 下，Nginx 已配置 `location ~ ^/content/data/ { deny all; }`，外部无法下载。**密码写进 cron 文件要把权限设为 600。**

## 六、数据完整性巡检

程序在「后台 → 数据系统」顶部提供**只读巡检卡片**，检查四类问题：

- 文章 `cat_id` 悬空（指向不存在的栏目）
- 附件 `content_id` 悬空（指向不存在的文章）
- 跨表 slug 冲突（文章与单页同名）
- 空 slug 记录（异常落库）

程序未使用物理外键（便于迁移到虚拟主机），这些引用关系由应用层维护。建议每月看一次巡检结果；发现悬空记录到后台对应页面修正即可。

## 核对清单

- [ ] `includes/` `admin/` `install/` 只读（555 / a-w）
- [ ] `config.php` 644 或 444
- [ ] `content/` 可写
- [ ] 安全响应头齐全
- [ ] `disable_functions` 已设（按需保留 curl_exec）
- [ ] cron 自动备份已配置且密码文件 600
- [ ] 「数据系统 → 数据完整性巡检」无异常
