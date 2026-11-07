# 变量与字段

模板里能取到哪些值，全在这一页。

## 四类写法

| 写法 | 取什么 | 例子 |
| --- | --- | --- |
| `{cici:site.xxx}` | 站点信息 | `{cici:site.name}` |
| `{cici:post.xxx}` | 当前文章/单页 | `{cici:post.title}` |
| `{cici:cat.xxx}` | 当前栏目 | `{cici:cat.name}` |
| `{cici:item.xxx}` | 循环里的当前项 | `{cici:item.title}` |
| `{cici:xxx}` | 页面级变量 | `{cici:title}` |

## 站点信息

| 写法 | 内容 |
| --- | --- |
| `{cici:site.name}` | 网站名称 |
| `{cici:site.desc}` | 站点描述 |
| `{cici:site.keywords}` | 关键词 |
| `{cici:site.url}` | 站点地址 |
| `{cici:site.icp}` | 备案号 |
| `{cici:site.footer}` | 页脚 HTML（原样输出，不转义） |
| `{cici:site.per_page}` | 每页条数 |

## 页面级变量

| 写法 | 内容 |
| --- | --- |
| `{cici:title}` | 当前页的 `<title>` |
| `{cici:desc}` | 当前页的描述 |
| `{cici:keywords}` | 当前页的关键词 |
| `{cici:canonical}` | 规范地址（防重复收录） |
| `{cici:body_class}` | 用来给 `<body>` 加页面标识，如 `home` / `single` / `archive` |
| `{cici:kw}` | 搜索词（只有搜索页有值） |
| `{cici:url}` | 站点根地址 |
| `{cici:theme}` | 当前主题目录地址 |
| `{cici:year}` | 当前年份 |

::: tip body_class 很有用
它让你用一套 CSS 给不同页面写不同样式：

```css
body.home .card { … }
body.single .entry-content { … }
```
:::

## 文档字段（文章 / 单页） {#doc-fields}

### 真实字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | 数字 | 文章 ID |
| `cat_id` | 数字 | 所属栏目 ID |
| `title` | 文本 | 标题 |
| `slug` | 文本 | 英文路径 |
| `excerpt` | 文本 | 手工填的摘要 |
| `content` | HTML | 正文（已渲染） |
| `cover` | 地址 | 封面图 |
| `tags` | 文本 | 标签，逗号分隔 |
| `flag` | 文本 | 推荐位代号 |
| `views` | 数字 | 浏览量 |
| `created_at` | 时间戳 | 发布时间 |
| `updated_at` | 时间戳 | 更新时间 |
| `status` | 数字 | 1 已发布 / 0 草稿 |
| `is_top` | 数字 | 是否置顶 |
| `attachment` | 地址 | 附件地址 |
| `attach_name` | 文本 | 附件显示名 |
| `attach_size` | 数字 | 附件字节数 |
| `attach_show` | 0/1 | 是否在前台显示下载按钮 |

### 自动补的虚拟字段

这些不是数据库字段，是模板层现算的，用起来更方便：

| 字段 | 内容 | 等价于 |
| --- | --- | --- |
| `url` | 文章地址 | `/栏目/12.html` |
| `date` | 格式化日期 | `Y-m-d` |
| `datetime` | 完整时间 | `Y-m-d H:i:s` |
| `year` / `month` / `day` | 年/月/日 | — |
| `summary` | 摘要（没填就从正文截） | — |
| `cat_name` | 栏目名 | — |
| `cat_url` | 栏目地址 | — |
| `tag_list` | 标签数组（可 `each` 循环） | — |
| `flag_list` | 推荐位数组 | — |
| `index` | 循环里的序号（从 0 起） | — |

## 栏目字段

| 字段 | 说明 |
| --- | --- |
| `id` | 栏目 ID |
| `name` | 栏目标题 |
| `dirname` | 别名 |
| `description` | 描述 |
| `keywords` | 关键词 |
| `banner` | 横幅相对路径 |
| `banner_url` | 横幅完整地址 |
| `url` | 栏目地址 |
| `count` | 文章数 |

## 旧模板兼容别名

从别的 CMS 迁过来的模板，常见写法可以直接用：

| 别名 | 本站字段 | 别名 | 本站字段 |
| --- | --- | --- | --- |
| `arcurl` | `url` | `litpic` | `cover` |
| `pubdate` | `created_at` | `click` | `views` |
| `description` | `summary` | `body` | `content` |
| `typename` | `cat_name` | `typeurl` | `cat_url` |
| `typeid` | `cat_id` | `writer` | 空串（占位） |

所以这几种写法效果一样：

```html
<a href="{cici:post.arcurl}">{cici:post.title}</a>
<a href="{cici:post.url}">{cici:post.title}</a>
```

## 取值时的默认行为

| 情况 | 结果 |
| --- | --- |
| 字段不存在 | 空字符串，不报错 |
| 字段是 `null` | 空字符串 |
| 正文类字段（`content` / `body` / `footer`） | **原样输出，不转义** |
| 其他字段 | 默认转义（`<` 变 `&lt;`） |

::: danger 为什么要默认转义
如果标题里有人塞了 `<script>`，不转义就会在每个列表页执行。默认转义是最省事的防线。
:::

## 什么时候要 `|raw`

你确定这段内容就是 HTML、需要原样输出时：

```html
{cici:post.content}          <!-- 正文，本来就不转义 -->
{cici:post.excerpt|raw}      <!-- 摘要里手写了 HTML，需要放行 -->
```

::: warning raw 要谨慎
`|raw` 等于"我保证这段是安全的"。用在管理员自己写的内容上没问题；用在用户提交的内容上就是 XSS。
:::

## 数组怎么循环

`tag_list`、`flag_list` 这种是数组，用 `each`：

```html
{cici:each in="post.tag_list"}
  <a href="{cici:item.url}">{cici:item.name}</a>
{/cici:each}
```

## 下一步

[标签速查](/theme/tags)
