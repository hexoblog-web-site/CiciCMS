---
layout: home
hero:
  name: CiciCMS
  text: 装完就能写文章的静态化 CMS
  tagline: 不用命令行、不用 Composer、不用配伪静态。后台按国内站长的习惯做，前台输出纯静态 HTML。
  image:
    src: /images/logo.svg
    alt: CiciCMS
  actions:
    - theme: brand
      text: 五步装好
      link: /guide/install
    - theme: alt
      text: 先看它解决什么问题
      link: /why/
    - theme: alt
      text: 后台长什么样
      link: /use/
features:
  - icon:
      src: /images/icons/static.svg
      alt: 纯静态输出
    title: 输出纯静态，虚拟主机也能扛
    details: 首页、栏目、文章、标签、归档、友链、404、sitemap、RSS 全部生成 .html。不需要伪静态规则，不需要 Redis，访客多的时候不吃数据库。
  - icon:
      src: /images/icons/layers.svg
      alt: 代码与数据分离
    title: 代码和数据分开放
    details: 升级只覆盖 includes/ 和 admin/，content/ 里的主题、图片、插件、备份一个都不会动。这是整个程序最核心的一条设计。
  - icon:
      src: /images/icons/box.svg
      alt: 零依赖
    title: 零依赖
    details: 部署只需要 PHP 和 MySQL。没有 vendor/、没有 node_modules、前台不加载任何第三方脚本（代码高亮是可选插件，关掉就零请求）。
  - icon:
      src: /images/icons/shield.svg
      alt: 安全设计
    title: 按长期运营的要求做安全
    details: SQL 全部预处理、POST 全部校验 CSRF、上传按真实图片类型判断、登录 15 分钟限速、写盘原子替换、后台恢复要手打确认词。
  - icon:
      src: /images/icons/pen.svg
      alt: 编辑器
    title: 编辑器就是一块干净的输入框
    details: 纯 textarea，不做任何字符替换，你粘贴的代码原样保留。代码块围栏（```php）保存时自动变成深色代码面板。
  - icon:
      src: /images/icons/refresh.svg
      alt: 在线升级
    title: 升级不用登 FTP
    details: 后台和 GitHub 仓库比提交，一键拉下来覆盖程序文件。更新前自动备份，任何一步失败整批回滚。
---

## 它解决的是这几件事

<div class="table-wrap">

| 你以前遇到的问题 | CiciCMS 怎么处理 |
| --- | --- |
| 虚拟主机不给伪静态，链接全是 `?id=123` | 生成真 `.html`，`/栏目/5.html`，不需要 rewrite |
| 后台太复杂，发篇文章要找半天按钮 | 文章编辑页只有一条竖线：标题 → 属性 → 正文 → 摘要 → 发布 |
| 富文本编辑器把代码块吃掉、把空格转成 `&nbsp;` | 纯 textarea，原样保存；代码围栏自动转代码面板 |
| 升级要下 FTP 覆盖，怕把数据一起盖掉 | 三区权限分离，升级只碰代码目录，`content/` 永不覆盖 |
| 图片上传后被传了马，附件目录能执行 PHP | 后缀白名单 + 真实图片类型校验 + 目录禁止执行脚本 |
| 备份要靠 phpMyAdmin，恢复更是不敢碰 | 后台一键备份/下载/恢复，恢复前还会自动再备一次 |
| 传了一堆图，不知道哪些还在用 | 系统清理会扫出「没被任何内容引用」的图片和附件 |
| 站点被撞库，几十次失败也没人管 | 同 IP 15 分钟失败 8 次直接锁住 |
| 改模板改坏了，前台全白 | 每次保存前自动备份到 `content/data/backup/theme/` |

</div>

## 三分钟了解它

一句话记法：**代码区只执行不写入，数据区只写入不执行。**

所以升级永远只有一句话：覆盖 `includes/`、`admin/`、`install/`，`content/` 和 `config.php` 不要动。

::: warning 装完第一件事
进后台之前，**先删掉整个 `install/` 目录**。安装向导本身是能重装数据库的，留着它等于留一把钥匙在门上。
:::

## 从哪里开始

<div class="table-wrap">

| 你的身份 | 从这里开始 |
| --- | --- |
| 第一次接触，想先看看长什么样 | [后台截图一览](/use/) |
| 准备装了，想确认服务器够不够 | [环境要求](/guide/requirement) → [安装（图文）](/guide/install) |
| 已经装好，想发第一篇文章 | [发布第一篇](/guide/first-post) |
| 想改模板、换样式 | [五分钟改出一个主题](/theme/) |
| 想加功能、写插件 | [代码手册](/dev/) |
| 已经在用了，想确认安全配置对不对 | [权限模型](/ops/permission) → [安全加固清单](/ops/security) |
| 遇到报错不知道怎么办 | [常见问题](/faq) |

</div>

## 版本

当前文档对应 **CiciCMS 2.1.7**。程序以 MIT 许可证发布，可自由使用、修改、商用、闭源再分发。
