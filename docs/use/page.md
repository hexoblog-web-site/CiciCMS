# 单页

单页是"不属于任何栏目、也不需要列表"的独立页面：关于我们、联系方式、隐私政策、广告合作。

![单页管理](/images/admin/06-page.png)

## 和文章的区别

| | 文章 | 单页 |
| --- | --- | --- |
| 属于栏目 | 是 | 否 |
| 出现在列表里 | 是 | 否 |
| 有标签 | 有 | 无 |
| 有封面 / 附件 | 有 | 无 |
| 有摘要 | 有 | 无 |
| 网址 | `/<栏目>/<id>.html` | `/<别名>.html` |
| 排序 | 按时间 | 可手动排序 |

## 新建一个单页

| 字段 | 说明 | 例子 |
| --- | --- | --- |
| 标题 | 页面标题 | 关于我们 |
| 别名 | 网址名，英文 | `about` |
| 内容 | 正文，支持 HTML | 一段介绍 |
| 状态 | 已发布 / 草稿 | — |
| 排序 | 数字小的在前 | — |

别名填 `about`，生成的地址就是：

```
/about.html
```

## 内容怎么写

和文章正文一样，纯 textarea，支持 HTML、支持代码围栏。

「关于我们」这种页面通常需要一点排版，直接写 HTML 就行：

```html
<p>本站是一个记录闽江沿岸风物与旧事的个人站点，从 2026 年开始写。</p>

<h2>联系</h2>
<ul>
  <li>邮箱：hi@example.com</li>
  <li>微信：example</li>
</ul>
```

::: tip 想让单页长得像落地页
单页用的模板是 `content/themes/你的主题/page.html`。想给某个单页做特殊版式，复制一份 `page.html` 改成 `page-about.html`，然后在模板里按 `post.slug` 判断引入。
:::

## 模板里怎么用

单页和文章共用大部分字段，模板里都叫 `post.*`：

```html
{cici:position}
<h1>{cici:post.title}</h1>
<div class="meta">更新于 {cici:post.updated_at|date:"Y-m-d"}</div>
<div class="body">{cici:post.content}</div>
```

## 常见用法

**页脚里的"关于 / 联系 / 隐私"**
建三个单页，然后在模板 `footer.html` 里写死链接：

```html
<a href="{cici:url}about.html">关于</a>
<a href="{cici:url}contact.html">联系</a>
<a href="{cici:url}privacy.html">隐私</a>
```

**"网站声明"这类不放进导航的页**
建好后不挂到导航，只在需要的地方链过去。

**改版时想临时下线一个单页**
状态改成"草稿"，重新生成。前台那个 `.html` 会被清掉。

::: warning 删单页会删掉对应的静态文件
但不会反过来影响别的页面。删之前想清楚地址有没有被人收藏。
:::

## 下一步

[媒体信息](/use/media)
