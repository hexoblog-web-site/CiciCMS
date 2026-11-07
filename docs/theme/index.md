# 五分钟改出一个主题

::: tip 好消息
默认主题是**纯 HTML**，里面没有一行 PHP。你只要会改 HTML 和 CSS，就能把它改成自己的样子。
:::

## 先看它长什么样

主题都在这里：

```
content/themes/default/
├── header.html     页头
├── footer.html     页脚
├── index.html      首页
├── list.html       栏目页 / 标签详情页
├── loop.html       列表循环片段
├── article.html    文章页
├── page.html       单页
├── tags.html       标签云
├── archive.html    归档
├── search.html     搜索结果
├── links.html      友链
├── 404.html        404
├── style.css       样式
└── assets/         主题自带的图片、字体（可选）
```

缺任何一个 `.html`，对应的页面就会 500。`assets/` 可以不要。

## 五分钟流程

### 1. 复制一份

把 `default/` 整个复制成 `mytheme/`（FTP 或者主机文件管理器都行）。

### 2. 启用

进后台「模板管理」，列表里多了一个 `mytheme`，点「启用」。

::: warning
启用之后要**点一次「生成静态 → 全量生成」**，前台才会变。启用只是"以后用这套"。
:::

### 3. 改字

打开 `index.html`，把「最新文章」「Copyright」这类文字改成你要的。

### 4. 改样式

打开 `style.css`，改颜色、字号、间距。想加字体就丢进 `assets/`。

### 5. 再生成一次

后台「生成静态」→ 全量生成。刷新前台。

**完了。** 就这五步。

## 最重要的规则：别删 `{cici:}` 标签

主题里长这样的东西是占位符：

```html
<h1>{cici:post.title}</h1>
<span>{cici:post.created_at|date:"Y年m月d日"}</span>
```

它们的规则：

| 你做什么 | 结果 |
| --- | --- |
| 改标签外面的文字 | ✅ 随便改 |
| 改标签外面的 HTML 结构、class | ✅ 随便改 |
| 移动标签的位置 | ✅ 可以 |
| 删掉标签 | ⚠️ 那块内容就没了（不报错，只是空） |
| 改标签名拼写 | ⚠️ 不解析，原样输出成文字 |
| 改 `{cici:` 的花括号 | ❌ 整段失效 |

::: tip 写错了不会白屏
这是标签模板相对 PHP 模板最大的好处：拼错了就是"原样显示出来"，你一眼能看到哪里不对，页面其他部分照常工作。PHP 模板一个语法错就是整站 500。
:::

## 三种标签形态

### 1. 单标签：直接输出一个值

```html
{cici:site.name}
{cici:post.title}
{cici:url}
{cici:theme}
{cici:year}
```

### 2. 带属性的单标签

```html
{cici:include file="header"}
{cici:field name="title"}
{cici:pager size="5"}
```

### 3. 成对的块标签：循环或判断

```html
{cici:list limit="10"}
  <a href="{cici:item.url}">{cici:item.title}</a>
{/cici:list}

{cici:if $post.cover}
  <img src="{cici:post.cover}">
{/cici:if}
```

块标签**必须成对闭合**，`{/cici:list}` 少了就整块不解析。

## 一个真实的例子

默认主题首页的列表部分，简化后是这样：

```html
{cici:list limit="10"}
<article class="card">
  <a href="{cici:item.url}">
    {cici:if $item.cover}
      <img src="{cici:item.cover}" alt="{cici:item.title}" loading="lazy">
    {/cici:if}
    <h2>{cici:item.title}</h2>
    <p>{cici:item.summary|limit:80}</p>
    <div class="meta">
      <time>{cici:item.created_at|date:"Y-m-d"}</time>
      <span>{cici:item.cat_name}</span>
    </div>
  </a>
</article>
{/cici:list}
```

`{cici:list}` 开始循环，循环里当前这一篇叫 `item`。所以 `{cici:item.title}` 是"这一篇的标题"。

## 想加自己的图片、字体

放 `content/themes/mytheme/assets/`，引用时用 `{cici:theme}` 拼：

```html
<link rel="stylesheet" href="{cici:theme}/style.css">
<img src="{cici:theme}/assets/logo.png" alt="logo">
```

`{cici:theme}` 会自动变成 `/content/themes/mytheme`，换域名、换目录都不用改。

## 拆分布局

`header.html` 和 `footer.html` 一般被 `include` 进每个页面：

```html
<!-- index.html 开头 -->
{cici:include file="header"}

<main>…</main>

{cici:include file="footer"}
```

::: tip include 会继承变量
`{cici:include file="header"}` 引入的片段能直接用当前页面的所有变量（`$site`、`$title` 等）。所以在 `header.html` 里写 `{cici:site.name}` 是有效的。
:::

## 改坏了怎么救

三层保险：

1. **后台在线编辑每次保存前自动备份** → `content/data/backup/theme/`
2. **再复制一份 `default/`** 当作参照，对比看哪不一样
3. 实在不行，把 `default/` 里对应的文件拷过去覆盖

## 下一步

- [页面与模板对照表](/theme/pages) —— 哪个页面用哪个模板
- [变量与字段](/theme/variables) —— 能取哪些值
- [标签速查](/theme/tags) —— 全部标签和属性
