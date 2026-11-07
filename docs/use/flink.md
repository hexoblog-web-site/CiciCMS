# 友情链接

友情链接不挂在栏目下，统一输出到一个独立页面：`/links.html`。

![友情链接](/images/admin/08-flink.png)

## 字段

| 字段 | 说明 |
| --- | --- |
| 名称 | 对方站点名 |
| 网址 | 完整地址，带 `https://` |
| Logo | 对方的图标，可上传或填外链。留空则只显示文字 |
| 说明 | 一句话介绍，`type="all"` 时会显示 |
| 排序 | 数字小的在前 |
| 显示 | 关掉就不输出 |
| 新窗口 | 打开时是否 `target="_blank"` |

## 输出成什么样

默认主题的 `/links.html` 是卡片网格：有 Logo 的显示图标，没 Logo 的只显示名字和说明。

模板里这样循环：

```html
{cici:flink type="all" limit="50"}
  <a class="link-card" href="{cici:item.url}" target="_blank" rel="noopener">
    {cici:if $item.logo}
      <img src="{cici:item.logo}" alt="{cici:item.name}" loading="lazy">
    {/cici:if}
    <span class="name">{cici:item.name}</span>
    <span class="desc">{cici:item.desc}</span>
  </a>
{/cici:flink}
```

`type` 有三个值：

| 值 | 效果 |
| --- | --- |
| `logo` | 只输出有 Logo 的 |
| `text` | 只输出文字链接 |
| `all` | 全部（默认） |

::: tip rel="noopener" 别省
外链加 `target="_blank"` 时一定要带 `rel="noopener"`，否则对方页面能通过 `window.opener` 操纵你的标签页。默认主题已经加了。
:::

## 首页侧栏也放友链

```html
{cici:flink type="logo" limit="12"}
  <a href="{cici:item.url}" title="{cici:item.name}">
    <img src="{cici:item.logo}" alt="{cici:item.name}">
  </a>
{/cici:flink}
```

## 后台的"显示"开关怎么用

不想删又不想显示的时候用。比如某个友站暂时打不开了，先把「显示」关掉，等恢复了再打开，不用重新填一遍信息。

## 换友情链接的礼仪（顺带一提）

- 先在自己站上挂好对方，再去找对方换
- 定期检查：对方把你撤了，你也撤掉
- 不和相关站点换（医疗、博彩、灰色产业）——会拖累你自己的站

## 下一步

[生成静态](/use/build)
