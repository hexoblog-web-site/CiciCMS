# 标签速查

按"单标签 → 块标签 → 属性"的顺序排。写模板时对着抄。

## 单标签

| 标签 | 作用 |
| --- | --- |
| `{cici:include file="header"}` | 引入片段，继承当前变量 |
| `{cici:pager}` | 分页条 |
| `{cici:field name="title"}` | 按字段名取值，自动识别别名 |
| `{cici:global name="cfg_webname"}` | 系统变量，兼容 `cfg_*` 命名 |
| `{cici:position}` | 面包屑 |
| `{cici:prev}` / `{cici:next}` | 上下篇默认链接 |
| `{cici:url}` | 站点地址 |
| `{cici:theme}` | 主题目录地址 |
| `{cici:year}` | 当前年份 |

### field 的特殊用法

```html
{cici:field name="title"}
{cici:field name="pubdate"|date:"Y-m-d"}
{cici:field name="position"}     <!-- 直接输出面包屑 -->
```

## 块标签总表

| 标签 | 一句话 |
| --- | --- |
| `list` | 文章列表，最常用 |
| `nav` | 栏目导航 |
| `channelartlist` | 栏目 + 文章两重循环 |
| `sonchannel` | 子栏目（同 `nav type="son"`） |
| `type` | 单个栏目 |
| `tags` | 标签云 |
| `hotwords` | 热门标签 |
| `archive` | 归档 |
| `related` | 相关文章 |
| `prev` / `next` | 上下篇（块形态，可带 empty） |
| `flink` | 友情链接 |
| `loop` | 任意表自由循环 |
| `each` | 遍历数组 |
| `if` | 条件判断 |

所有块标签都支持 `{cici:empty}…{/cici:标签}` 兜底。

## `list` —— 文章列表

属性最多，也最常调。

| 属性 | 说明 | 例子 |
| --- | --- | --- |
| `type` | `article`（默认）/ `page` / `category` / `tag` | `type="page"` |
| `catid` | 分类 ID，支持多个 | `catid="1,2,3"`，别名 `typeid` |
| `catdir` | 按分类目录名取 | `catdir="tech"`，别名 `dirname` |
| `notcatid` | 排除分类 | `notcatid="5"`，别名 `notypeid` |
| `current` | 取当前栏目 | `current="1"` |
| `tag` | 按标签过滤 | `tag="PHP"` |
| `keyword` | 标题/摘要模糊匹配 | `keyword="教程"` |
| `flag` | 推荐位筛选 | `flag="c"` |
| `noflag` | 排除推荐位 | `noflag="s"` |
| `idlist` | 指定 ID 并按给定顺序 | `idlist="3,1,2"` |
| `notid` / `exclude` | 排除指定 ID | `notid="7,8"` |
| `subday` | 最近 N 天 | `subday="7"` |
| `limit` | 条数 | `limit="10"` |
| `row` | 同 `limit` | — |
| `offset` | 偏移 | `offset="5"`，或 `limit="5,10"`（偏移,条数） |
| `order` | `new` `hot` `top` `old` `updated` `rand` | `order="hot"` |
| `orderby` / `orderway` | 字段 + 方向 | `orderby="click" orderway="asc"` |
| `titlelen` | 截断标题 | `titlelen="30"` |
| `infolen` | 截断摘要 | `infolen="80"` |
| `col` | 多列输出 | `col="2"` |

::: tip order 的几个值都是什么意思
| 值 | 排序依据 |
| --- | --- |
| `new` | 发布时间倒序（默认） |
| `old` | 发布时间正序 |
| `hot` | 浏览量倒序 |
| `top` | 置顶优先，再按时间 |
| `updated` | 更新时间倒序 |
| `rand` | 随机（每次生成结果不同） |
:::

**推荐位代号**

| 代号 | 名称 | 代号 | 名称 |
| --- | --- | --- | --- |
| `c` | 推荐 | `a` | 特荐 |
| `h` | 头条 | `s` | 滚动 |
| `p` | 图片 | `b` | 加粗 |
| `f` | 幻灯 | `j` | 图文 |

### 循环里能取什么

```html
{cici:list limit="10"}
  <a href="{cici:item.url}">{cici:item.title}</a>
  <span>{cici:item.date}</span>
  <span>{cici:item.cat_name}</span>
  <span>{cici:item.views|num}</span>
  <span>{cici:item.index}</span>       <!-- 序号，从 0 起 -->
{/cici:list}
```

截断后完整值还在：`item.fulltitle`、`item.fullsummary`。

## `nav` —— 栏目导航

| 属性 | 说明 |
| --- | --- |
| `type` | `top` 顶级 / `son` 子栏目 / `self` 当前及其同级 |
| `catid` | 指定栏目 |
| `limit` | 条数 |
| `currentstyle` | 当前项的 class 名，默认 `active` |

```html
{cici:nav type="top"}
  <a href="{cici:item.url}" class="{cici:item.currentstyle}">{cici:item.name}</a>
{/cici:nav}
```

## `channelartlist` —— 栏目 + 文章

首页做"每个栏目展示几篇"用这个：

```html
{cici:channelartlist row="4"}
  <section>
    <h2>{cici:item.name}</h2>
    {cici:list catid="$item.id" limit="5"}
      <a href="{cici:item.url}">{cici:item.title}</a>
    {/cici:list}
  </section>
{/cici:channelartlist}
```

::: tip 属性值可以写变量
`catid="$item.id"` 里的 `$item` 指外层循环的当前栏目。嵌套循环全靠这个。
:::

## `tags` —— 标签云

| 属性 | 说明 |
| --- | --- |
| `limit` | 显示几个 |
| `sort` | `count` 按篇数 / `name` 按名字 / `rand` 随机 |
| `type="current"` | 只显示当前文章的标签 |

```html
{cici:tags limit="30" sort="count"}
  <a href="{cici:item.url}">{cici:item.name}<em>{cici:item.count}</em></a>
{/cici:tags}
```

## `archive` —— 归档

```html
{cici:archive limit="10"}
  <h3>{cici:item.year} 年（{cici:item.count} 篇）</h3>
  {cici:each in="item.posts"}
    <a href="{cici:item.url}">{cici:item.title}</a>
  {/cici:each}
{/cici:archive}
```

能取 `item.year` / `item.label` / `item.count` / `item.months`。

## `related` —— 相关文章

| 属性 | 说明 |
| --- | --- |
| `limit` | 条数 |
| `bytag="0"` | 默认先同栏目、不足用标签补；设成 `1` 只按标签 |

## `flink` —— 友链

| 属性 | 说明 |
| --- | --- |
| `type` | `logo` / `text` / `all` |
| `limit` | 条数 |

## `if` —— 条件判断

支持：`$变量`、`== != > < >= <=`、`&&`、`||`、`!`、括号。

```html
{cici:if $post.attach_show}
  <a href="{cici:post.attachment}" download>{cici:post.attach_name}</a>
{/cici:if}

{cici:if $pager.pages > 1 && $post.views > 100}
  <span>热门</span>
{/cici:if}
```

::: warning 变量要带 `$`
`{cici:if $post.cover}` 对，`{cici:if post.cover}` 错。输出时才不用 `$`。
:::

## `loop` —— 任意表循环

需要"模板里做点自定义查询"时用。只允许白名单表：`article` / `category` / `page` / `flink` / `attachment`。

```html
{cici:loop table="article" where="cat_id=2" order="views desc" limit="5"}
  <a href="{cici:item.url}">{cici:item.title}</a>
{/cici:loop}
```

::: danger 别把用户输入拼进 where
`where` 是原样拼进 SQL 的。只写你自己在模板里固定的条件，绝不要用 `$kw`、`$post.slug` 这类可变值。
:::

## `each` —— 遍历数组

```html
{cici:each in="post.tag_list"}
  <a href="{cici:item.url}">{cici:item.name}</a>
{/cici:each}
```

## 下一步

[修饰器](/theme/modifiers)
