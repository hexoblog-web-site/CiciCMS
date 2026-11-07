# 页面与模板对照表

九种页面，各自用哪个模板、输出到哪个地址、能用哪些变量。

## 总表

| 页面 | 模板文件 | 输出地址 | 主要标签 |
| --- | --- | --- | --- |
| 首页 | `index.html` | `/index.html` | `list` `channelartlist` `pager` |
| 首页分页 | `index.html` | `/page/2/index.html` | 同上 |
| 栏目页 | `list.html` | `/栏目别名/index.html` | `position` `list` `pager` |
| 栏目分页 | `list.html` | `/栏目别名/page/2/index.html` | 同上 |
| 文章页 | `article.html` | `/栏目别名/12.html` | `position` `post.*` `prev` `next` `related` `tags` |
| 单页 | `page.html` | `/别名.html` | `position` `post.title` `post.content` |
| 标签云 | `tags.html` | `/tags.html` | `tags` |
| 标签详情 | `list.html` | `/tag/哈希.html` | `list` `pager` |
| 归档 | `archive.html` | `/archive.html` | `archive` |
| 搜索 | `search.html` | `/index.php?s=关键词`（动态） | `posts` `pager` `kw` |
| 友链 | `links.html` | `/links.html` | `flink` |
| 404 | `404.html` | `/404.html` | `site.*` |

::: tip 注意两处复用
- **标签详情页用的是 `list.html`**，不是单独的模板。靠 `$cat` 为空、`$posts` 是标签结果来区分
- **搜索页是动态的**，不生成静态文件，所以它必须存在 `index.php` 能跑的环境里
:::

## 各页面能用的变量

### 首页

| 变量 | 内容 |
| --- | --- |
| `$posts` | 当前页的文章列表 |
| `$pager` | 分页信息 |
| `$cats` | 所有栏目（配合 `channelartlist` 用） |
| `$site` | 站点信息 |

### 栏目页

在首页变量基础上多一个 `$cat`：

| 字段 | 说明 |
| --- | --- |
| `$cat.name` | 栏目标题 |
| `$cat.dirname` | 别名（网址目录） |
| `$cat.description` | 描述 |
| `$cat.keywords` | 关键词 |
| `$cat.banner` | 横幅相对路径 |
| `$cat.banner_url` | 横幅完整地址（可直接放进 `style`） |
| `$cat.url` | 栏目地址 |
| `$cat.count` | 栏目下文章数 |

### 文章页

多一个 `$post`（就是当前文章），字段见 [变量与字段](/theme/variables#doc-fields)。

### 单页

同样用 `$post`，但字段少（没有标签、封面、附件）。

### 搜索页

| 变量 | 内容 |
| --- | --- |
| `$kw` | 当前搜索词 |
| `$posts` | 搜索结果 |
| `$pager` | 分页 |

## 分页怎么写

```html
{cici:if $pager.pages > 1}
<nav class="pager">
  {cici:pager prev="上一页" next="下一页" size="5"}
</nav>
{/cici:if}
```

`pager` 的属性：

| 属性 | 说明 |
| --- | --- |
| `prev` / `next` | 上一页/下一页的文字 |
| `first` / `last` | 首页/末页的文字 |
| `size` | 中间显示几个页码 |
| `info="0"` | 关掉"第 2 页 / 共 8 页"那行 |
| `class` | 外层容器的 class |

::: warning 只有一页时别输出
`$pager.pages > 1` 判断不能省，否则单页的列表底下会出现一个没有意义的"1"。
:::

## 面包屑

```html
<nav class="crumb">{cici:position}</nav>
```

文章页会输出「首页 / 栏目 / 文章标题」，栏目页输出「首页 / 栏目」。想换分隔符：

```html
{cici:position sep="/"}
```

## 上下篇

```html
{cici:prev}
  <a href="{cici:item.url}">上一篇：{cici:item.title}</a>
{cici:empty}
  <span>已经是第一篇</span>
{/cici:prev}

{cici:next}
  <a href="{cici:item.url}">下一篇：{cici:item.title}</a>
{cici:empty}
  <span>已经是最后一篇</span>
{/cici:next}
```

`{cici:empty}` 是"没有数据时"的分支，所有块标签都支持。

::: tip 只想取同栏目
加 `samecat="1"`：

```html
{cici:prev samecat="1"}…{/cici:prev}
```

默认 `samecat="0"`，也就是跨栏目取全局上一篇/下一篇。
:::

## 相关文章

```html
{cici:related limit="5"}
  <li><a href="{cici:item.url}">{cici:item.title}</a></li>
{/cici:related}
```

逻辑是：**先取同栏目的，不够用同标签的补**。想只要同标签的，加 `bytag="1"`。

## 模板文件缺失会怎样

| 缺哪个 | 后果 |
| --- | --- |
| `index.html` | 首页 500；主题也不会被识别（必须至少有一个 index） |
| `article.html` | 所有文章页 500 |
| `list.html` | 栏目页和标签页 500 |
| `page.html` | 单页 500 |
| `header.html` / `footer.html` | 引用了它们的页面全 500 |
| `style.css` | 页面能打开，但没有样式 |

## 下一步

[变量与字段](/theme/variables)
