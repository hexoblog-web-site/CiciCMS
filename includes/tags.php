<?php
/**
 * HtmlCms 模板标签引擎（.html 模板）
 *
 * 目标：模板写纯 HTML，不出现一行 PHP；改版不碰程序；做站时任何内容都能调出来。
 * 语法风格对齐 dedecms 的 {dede:xxx}，前缀为 {cici:xxx}。
 *
 * ── 单标签 ──────────────────────────────────────────────
 *   {cici:site.name}                点号取字段，自动转义
 *   {cici:post.title|limit:24}      取字段 + 修饰器
 *   {cici:post.content}             正文类字段原样输出（不转义）
 *   {cici:field name="arcurl"}      兼容 dedecms 写法，自动识别字段别名
 *   {cici:global name="cfg_webname"} 系统变量
 *   {cici:url} {cici:theme} {cici:year}
 *   {cici:include file="header"}    引入片段（继承当前变量）
 *   {cici:pager prev="上一页" size="2"}
 *
 *   修饰器可链式：|raw |esc |date:"Y-m-d" |limit:20 |limit:20:"..." |strip |nl2br
 *                |upper |lower |default:"—" |replace:"a":"b" |int |num |ago |urlencode |trim
 *
 * ── 块标签（都支持 {cici:empty} 兜底分支）─────────────────
 *   {cici:list ...}         文档列表
 *   {cici:page ...}         单页列表
 *   {cici:nav ...}          栏目导航
 *   {cici:type catid="1"}   单个栏目
 *   {cici:sonchannel catid="1"} 子栏目
 *   {cici:channelartlist row="4" limit="5"} 栏目 + 文档两重循环
 *   {cici:related limit="6"} 相关文档
 *   {cici:prev} {cici:next} 上一篇 / 下一篇
 *   {cici:position}         面包屑
 *   {cici:tags sort="count"} 标签云
 *   {cici:hotwords limit="12"} 热门标签
 *   {cici:flink type="text"} 友情链接
 *   {cici:loop table="article" ...} 白名单表自定义循环
 *   {cici:each in="post.tag_list"} 任意数组遍历
 *   {cici:if $a.b == "1"}   条件判断
 *
 * ── 文件结构 ────────────────────────────────────────────
 *   includes/tags.php            本文件：说明 + 加载顺序
 *   includes/tags/config.php     命名空间、字段别名、推荐位定义
 *   includes/tags/registry.php   标签注册表：一个文件 = 一个标签
 *   includes/tags/engine.php     扫描器与编译缓存
 *   includes/tags/context.php    编译期工具：属性、修饰器、路径、循环体
 *   includes/tags/single/*.php   单标签编译器，一个标签一个文件
 *   includes/tags/block/*.php    块标签编译器，一个标签一个文件
 *   includes/tags/fields/*.php   可选：字段级覆盖，如 title.php
 *   includes/tags/runtime/*.php  运行时渲染，编译出的 PHP 直接调用
 *
 * 加一个新标签 = 在 single/ 或 block/ 下加一个文件，返回一份定义数组。
 * 引擎、注册表、后台都不用改。写法见 single/url.php（最简单的一份）。
 *
 * 规则：
 *  1. 主题目录下同名 .html 优先于 .php，两种模板可混用、可互相 include。
 *  2. 除正文类字段外一律 HTML 转义，模板作者不需要写 e()。
 *  3. 编译结果缓存到 content/data/cache/tpl，源文件或引擎版本变化即自动重编。
 *  4. 标签写错（未闭合、未知标签）不会白屏，原样输出并记日志。
 *  5. 属性值可写 $item.id 之类变量，用于嵌套循环（如栏目内取该栏目文档）。
 */

/** 标签前缀，改这里即可整体换命名空间 */
define('CMS_TAG_NS', 'cici');

/* ── 编译期：定义与引擎 ───────────────────────────────── */

require CMS_INC . '/tags/config.php';
require CMS_INC . '/tags/registry.php';
require CMS_INC . '/tags/engine.php';
require CMS_INC . '/tags/context.php';

/* ── 运行期：编译出来的 PHP 会直接调用这些函数 ─────────── */

foreach (glob(CMS_INC . '/tags/runtime/*.php') as $__cms_tag_rt) {
    require_once $__cms_tag_rt;
}
unset($__cms_tag_rt);
