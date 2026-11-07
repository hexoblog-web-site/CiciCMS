# 钩子一览

插件要介入程序，只有两个动作：**注册钩子**、**等程序调用**。

## 两类钩子

| 类型 | 特点 | 用途 |
| --- | --- | --- |
| **filter（过滤器）** | 接管一个值，**必须 return** | 改内容、加代码、改文案 |
| **action（动作）** | 到点做事，不关心返回值 | 记日志、发通知、清缓存 |

判断标准很简单：**你要不要改变程序原本的输出？** 要 → filter；不要 → action。

## 全部 13 个钩子

| 钩子 | 类型 | 时机 | 参数 |
| --- | --- | --- | --- |
| `html_head` | filter | `</head>` 之前 | `$html` |
| `html_foot` | filter | `</body>` 之前 | `$html` |
| `html_final` | filter | 整页 HTML 写盘前 | `$html, $context` |
| `content_html` | filter | 正文渲染完成、入库前 | `$html, $src` |
| `article_render` | filter | 内容页渲染前 | `$post` |
| `article_saved` | action | 文章新增或更新后 | `$id, $data, $isNew` |
| `build_start` | action | 静态生成开始 | `$total` |
| `build_done` | action | 静态生成结束 | `$files, $errors` |
| `admin_menu` | filter | 后台左侧菜单 | `$nav` |
| `admin_head` | action | 后台 `</head>` 之前 | — |
| `admin_foot` | action | 后台 `</body>` 之前 | — |
| `setting_saved` | action | 系统设置保存后 | `$keys` |
| `search_query` | filter | 前台搜索词 | `$keyword` |

::: tip head / foot / final 两条链路都走
`html_head` / `html_foot` / `html_final` 在**静态生成**和**站内搜索**两条链路上都会执行。

这意味着插件只需要写一次——生成出来的 HTML 和搜索结果页口径一致，不会出现"搜索页有、生成后没有"的情况。
:::

## 怎么写

### filter：改一段输出

```php
<?php
// content/plugins/demo/plugin.php
if (!defined('CMS_ROOT')) { exit; }

cms_add_filter('html_foot', function ($html) {
    return $html . '<script src="' . cms_plugin_url('demo', 'assets/demo.js') . '" defer></script>';
});
```

**必须 return。** 忘了 return 就等于把这个钩子的值变成了 `null`，页面可能整段消失。

### action：到点做事

```php
cms_add_action('article_saved', function ($id, $data, $isNew) {
    log_msg('文章已保存：' . $id . ($isNew ? '（新建）' : '（更新）'));
});
```

不需要 return。

### 优先级

第三个参数，数字小的先执行，默认 10：

```php
cms_add_filter('content_html', 'my_first', 5);    // 先跑
cms_add_filter('content_html', 'my_later', 20);   // 后跑
```

### 用命名函数而不是闭包

闭包写起来快，但**没法注销**，也不方便调试。钩子多的时候建议用命名函数：

```php
function demo_foot($html) {
    return $html . '<script src="' . cms_plugin_url('demo', 'assets/demo.js') . '"></script>';
}
cms_add_filter('html_foot', 'demo_foot');
```

## 一个完整的插件

```
content/plugins/read-time/
├── plugin.json
└── plugin.php
```

**plugin.json**

```json
{
  "id": "read-time",
  "name": "预计阅读时间",
  "version": "1.0.0",
  "description": "在文章标题下显示「约 3 分钟读完」",
  "author": "你自己"
}
```

**plugin.php**

```php
<?php
if (!defined('CMS_ROOT')) { exit; }

// 正文渲染完后，在文章页插入阅读时间
cms_add_filter('html_final', function ($html, $context) {
    if (empty($context['type']) || $context['type'] !== 'article') {
        return $html;
    }
    if (empty($context['post']['content'])) {
        return $html;
    }
    $text  = content_plain($context['post']['content']);
    $chars = mb_strlen($text, 'UTF-8');
    $min   = max(1, (int) ceil($chars / 400));   // 按每分钟 400 字算
    $badge = '<p class="read-time">约 ' . $min . ' 分钟读完</p>';
    return str_replace('</h1>', '</h1>' . $badge, $html);
}, 20);
```

装上、启用、重新生成静态页，就生效了。

::: warning 别用字符串替换改结构
上面用 `str_replace('</h1>', ...)` 只是演示。生产环境里更稳的做法是 `html_head` 加一段 CSS、或者改模板。字符串替换容易在意外的地方命中。
:::

## 加一个后台页面

`plugin.json` 里声明 `admin`：

```json
{
  "id": "demo",
  "name": "示例插件",
  "version": "1.0.0",
  "description": "演示后台页面",
  "author": "CiciCMS",
  "admin": { "file": "admin.php", "title": "示例插件", "sub": "说明文字" }
}
```

`admin.php`：

```php
<?php
if (!defined('CMS_ADMIN')) { http_response_code(404); exit; }

if (is_post()) {
    csrf_verify();
    set_setting('demo_word', post_str('word'));
    flash('已保存');
    redirect(admin_url('demo'));
}

$word = setting('demo_word', '你好');
?>
<div class="card">
    <div class="card-head"><h2>示例插件</h2></div>
    <div class="card-body">
        <form method="post" action="<?php echo admin_url('demo'); ?>">
            <?php echo csrf_field(); ?>
            <input class="input" type="text" name="word" value="<?php echo e($word); ?>">
            <button class="btn" type="submit">保存</button>
        </form>
    </div>
</div>
```

这个页面在后台布局**内部**执行，所以可以直接用 `.card` `.table` `.btn` `.input` 这些现成样式，也能调所有核心函数（`all()` `one()` `insert_row()` `csrf_field()` …）。

### 挂到左侧菜单

```php
cms_add_filter('admin_menu', function ($nav) {
    $nav['demo'] = array('示例插件', '<path d="M12 3.5v10"/><path d="M8 8.5l4-4 4 4"/>');
    return $nav;
});
```

格式是 `key => array(标题, SVG 内联图标)`。

::: warning key 必须等于插件 ID
因为它同时就是路由参数：菜单点了跳 `?p=demo`。写错就对不上。

图标可以省略，缺省用默认方块，不会把侧栏撑坏。
:::

## 五条必须遵守的约定

| 约定 | 后果 |
| --- | --- |
| 插件代码和核心**共享全局作用域** | 函数名、常量必须加前缀。两个插件同名函数会直接白屏 |
| 插件页的 POST 由插件自己处理 | 核心只在插件**列表页**处理自己的启用/停用，不抢你的 `do` |
| 只能在 `plugin.json` 里声明**一个**后台文件 | 路由比对的是扫描结果，`..` 和插件目录外的路径一律拒绝 |
| 插件目录里 `.php` 不能被 URL 直接请求 | Apache 靠 `.htaccess`，**Nginx 要自己配规则** |
| 启用/停用后要重新生成静态页 | 已经生成的 HTML 还是旧样子 |

## 调试插件的办法

| 现象 | 查什么 |
| --- | --- |
| 启用后整站白屏 | 有语法错误。看 `content/data/logs/` |
| 菜单里没有我的项 | `admin_menu` 的 `key` 和插件 ID 不一致 |
| 点菜单 404 | `plugin.json` 里 `admin.file` 没声明，或者文件名写错 |
| 钩子没效果 | 钩子名拼错；或者这个钩子在静态生成链路里没被触发 |
| 前台看不到变化 | 忘了重新生成静态页 |

## 下一步

[数据表字典](/dev/database) · [请求是怎么走完一圈的](/dev/flow)
