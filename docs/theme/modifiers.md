# 修饰器

修饰器就是"拿到值之后再加工一下"，写在标签后面，用 `|` 接。

## 基本写法

```html
{cici:post.title|limit:24}
{cici:post.created_at|date:"Y年m月d日"}
{cici:post.tags|replace:",":" · "}
{cici:post.writer|default:"佚名"}
{cici:post.content|raw}
```

也可以写在属性后面：

```html
{cici:field name="pubdate"|date:"Y-m-d"}
```

## 能链式接

```html
{cici:post.excerpt|strip|limit:60|default:"暂无摘要"}
```

顺序是从左到右：先去标签 → 再截断 → 空的话给默认值。

## 全部修饰器

| 修饰器 | 作用 | 例子 | 结果 |
| --- | --- | --- | --- |
| `raw` / `html` | 不转义，原样输出 | `{cici:post.excerpt\|raw}` | 保留 HTML |
| `esc` / `e` | 强制转义 | `{cici:post.title\|esc}` | `<` → `&lt;` |
| `date:"格式"` | 时间格式化 | `\|date:"Y-m-d"` | `2026-11-05` |
| `ago` | 相对时间 | `\|ago` | `3 天前` |
| `limit:字数` / `cut` | 截断（先去 HTML） | `\|limit:60` | 前 60 字 |
| `limit:字数:"后缀"` | 截断 + 省略号 | `\|limit:60:"…"` | 前 60 字 + … |
| `strip` | 去 HTML 标签 | `\|strip` | 纯文本 |
| `nl2br` | 换行转 `<br>` | `\|nl2br` | 保留换行 |
| `upper` / `lower` | 大小写 | `\|upper` | `ABC` |
| `default:"值"` | 空值兜底 | `\|default:"—"` | `—` |
| `replace:"旧":"新"` | 字符串替换 | `\|replace:",":"·"` | `a·b` |
| `int` | 取整 | `\|int` | `42` |
| `num` | 千分位 | `\|num` | `1,234` |
| `size` | 字节转人看的 | `\|size` | `1.4 MB` |
| `trim` | 去首尾空格 | `\|trim` | — |
| `urlencode` | URL 编码 | `\|urlencode` | `%E4%B8%AD` |

## 日期格式

`date` 用的是 PHP 的格式符：

| 格式 | 输出 |
| --- | --- |
| `Y-m-d` | 2026-11-05 |
| `Y年m月d日` | 2026年11月05日 |
| `m/d` | 11/05 |
| `H:i` | 14:30 |
| `Y-m-d H:i:s` | 2026-11-05 14:30:00 |

常用组合：

```html
<time datetime="{cici:post.datetime}">{cici:post.created_at|date:"Y-m-d"}</time>
```

::: tip 为什么 time 标签要写 datetime
`<time datetime="...">` 给机器读，显示的文字给人读。搜索引擎和浏览器扩展靠这个属性识别发布时间。
:::

## `ago` 相对时间

```html
{cici:post.created_at|ago}
```

输出：`刚刚` / `5 分钟前` / `3 小时前` / `2 天前` / `3 个月前` / 超过一年就显示具体日期。

适合"最近更新"这类场景；文章页建议还是用绝对日期，读者更好判断。

## `limit` 的细节

`limit` 会**先去掉 HTML 标签再截断**，所以不会把标签从中间截断导致版面崩掉。

```html
{cici:post.content|limit:100}       <!-- 100 字，无后缀 -->
{cici:post.content|limit:100:"…"}   <!-- 100 字 + 省略号 -->
```

::: warning 长度按字符算
中文一个字算一个，不是按字节。所以 `limit:100` 是 100 个汉字左右。
:::

## `size` 用在附件上

```html
{cici:post.attach_name}（{cici:post.attach_size|size}）
```

会输出成 `使用手册.pdf（1.4 MB）`。这是默认主题附件卡片的写法。

## 默认值兜底

```html
{cici:post.writer|default:"佚名"}
{cici:post.cover|default:"{cici:theme}/assets/placeholder.png"}
```

`default` 只在**空字符串 / null** 时生效，`0` 不算空。

## 什么会自动转义

| 字段 | 默认行为 |
| --- | --- |
| `content` / `body` / `footer` | 不转义（原样输出） |
| 其他所有字段 | 转义 |

所以正文里写 HTML 不用加 `|raw`，标题里想输出 HTML 才需要。

::: danger raw 是把双刃剑
`|raw` 表示"这段我担保是安全的"。用在管理员写的内容上没问题，用在访客能提交的内容上就是 XSS 漏洞。
:::

## 实战组合

**列表卡片摘要**

```html
<p>{cici:item.summary|strip|limit:80:"…"}</p>
```

**标签拆开显示**

```html
{cici:post.tags|replace:",":"</span><span>"}
```

**浏览量为 0 时显示"暂无"**

```html
{cici:post.views|default:"暂无"}
```

**附件大小 + 名字**

```html
{cici:post.attach_name|limit:20:"…"} · {cici:post.attach_size|size}
```

## 下一步

[PHP 模板（高级）](/theme/php)
