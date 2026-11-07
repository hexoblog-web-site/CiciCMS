# PHP 模板（高级）

::: danger 先想清楚要不要用
90% 的改版需求，标签模板（`.html`）就够了。PHP 模板是给"确实需要写逻辑"的场景准备的：

- 要调第三方接口（天气、汇率、B 站追番）
- 要做复杂的数据组合，标签表达不了
- 要复用一段已有 PHP 代码

**代价**：一个语法错误就是整站 500，前台没有任何提示。没有 PHP 基础，别碰这一页。
:::

## 怎么用

主题目录里放同名 `.php` 文件即可：

```
content/themes/mytheme/article.html   ← 存在时优先用这个
content/themes/mytheme/article.php    ← 备用
```

**`.html` 优先。** 所以想切回 PHP 版，把 `.html` 改个名就行，不用删。

## 能用的变量

| 变量 | 内容 |
| --- | --- |
| `$site` | 站点信息：`name` `desc` `keywords` `url` `icp` `footer` `per_page` |
| `$cats` | 栏目列表 |
| `$posts` | 文章列表（首页 / 栏目 / 标签 / 搜索） |
| `$post` | 单篇文章或单页 |
| `$cat` | 当前栏目（栏目页）：`name` `dirname` `description` `keywords` `banner` `banner_url` |
| `$pager` | 分页：`page` `pages` `total` `prev` `next` `url` |
| `$tags` | 标签云（`name => count`） |
| `$title` `$desc` `$keywords` `$canonical` | SEO 字段 |

## 常用函数

| 函数 | 作用 |
| --- | --- |
| `e($str)` | HTML 转义输出 |
| `u_home()` | 首页地址 |
| `u_post($post)` | 文章地址 |
| `u_cat($cat)` | 栏目地址 |
| `u_page($page)` | 单页地址 |
| `u_tag($name)` | 标签地址 |
| `site_url($path)` | 拼站点地址 |
| `theme_url($path)` | 拼主题地址 |
| `format_date($ts, $fmt)` | 时间格式化 |
| `str_limit($str, $len)` | 截断 |
| `pager_numbers($pager)` | 分页页码数组 |

## 一个完整的例子

`article.php`：

```php
<?php if (!defined('CMS_ROOT')) { exit; } ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <title><?php echo e($title); ?></title>
  <meta name="description" content="<?php echo e($desc); ?>">
  <link rel="stylesheet" href="<?php echo theme_url('style.css'); ?>">
</head>
<body class="single">

<?php include __DIR__ . '/header.php'; ?>

<article>
  <h1><?php echo e($post['title']); ?></h1>
  <time datetime="<?php echo e($post['datetime']); ?>">
    <?php echo e(format_date($post['created_at'], 'Y-m-d')); ?>
  </time>

  <div class="entry-content">
    <?php echo $post['content']; ?>
  </div>

  <?php if (!empty($post['attach_show'])): ?>
    <a href="<?php echo e($post['attachment']); ?>" download>
      <?php echo e($post['attach_name']); ?>
      （<?php echo e($post['attach_size']); ?> 字节）
    </a>
  <?php endif; ?>
</article>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
```

## 三条硬性规矩

### 1. 第一行必须挡直接访问

```php
<?php if (!defined('CMS_ROOT')) { exit; } ?>
```

模板文件在 `content/themes/` 下，理论上可以被 URL 直接请求。加这一行，直接访问时什么都不输出。

::: warning Nginx 用户
`.htaccess` 在 Nginx 上不生效。除了这行防护，还要在 Nginx 配置里拦住 `content/themes/*.php`，见 [部署配置](/ops/deploy)。
:::

### 2. 输出一律 `e()`

```php
echo e($post['title']);          // ✅
echo $post['title'];             // ❌ 有 XSS 风险
```

**唯一的例外**是正文：`$post['content']` 已经是净化过的 HTML，原样输出。

### 3. 别在模板里写数据库查询

模板层只负责展示。要查数据，用 `{cici:loop}` 标签，或者把它做成插件在 `article_render` 钩子里塞进变量。

在模板里直接 `q('SELECT ...')` 会让"改个样式"变成"改程序"，下次升级容易出问题。

## 混用两种模板

一个主题里可以部分 `.html`、部分 `.php`，互不影响。

常见做法：

| 页面 | 用什么 | 理由 |
| --- | --- | --- |
| 首页、栏目、文章、归档 | `.html` | 结构固定，标签够用 |
| 一个特殊的落地页 | `.php` | 需要算点东西 |

## 调试技巧

PHP 模板出错时前台只显示 500，什么都不说。排查顺序：

1. 看 `content/data/logs/` 里当天的日志，会写具体错误和行号
2. 临时在文件开头加：

```php
<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
?>
```

3. 定位到行之后再删掉——**生产环境不要留着**，报错信息会暴露服务器路径

## 什么时候该改用标签模板

| 你在 PHP 模板里做的事 | 用标签怎么写 |
| --- | --- |
| 循环输出文章 | `{cici:list}` |
| 判断有没有封面 | `{cici:if $post.cover}` |
| 格式化日期 | `\|date:"Y-m-d"` |
| 截断摘要 | `\|limit:80` |
| 引入页头页脚 | `{cici:include file="header"}` |
| 分页 | `{cici:pager}` |

基本上，除了"真的需要计算"的场合，标签都能覆盖。

## 下一步

[代码手册](/dev/) —— 想读源码从这开始
