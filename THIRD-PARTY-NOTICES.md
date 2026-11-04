# 第三方组件声明

HtmlCms 本体代码版权归本项目作者所有，以 MIT 许可证发布（见 `LICENSE`）。

## 随程序分发

**无。**

程序运行时不含任何第三方代码、脚本、样式或字体文件。前台与后台均为原生 HTML / CSS / JS / PHP，无框架、无 UI 库、无图标字体、无 CDN 依赖，可离线部署在虚拟主机上。

## 已移除的组件

| 组件 | 曾用途 | 移除版本 |
| --- | --- | --- |
| PrismJS（MIT，Copyright (c) 2012 Lea Verou） | 前台代码块语法高亮 | 1.9.4 |

1.9.3 及更早版本在 `content/themes/default/assets/` 下分发 `prism.bundle.js` 与 `code.css`。自 1.9.4 起两个文件已删除，模板中的引用也已移除。

代码块改为纯文本呈现，保留 `<pre><code class="language-xx">` 结构，样式由主题的 `style.css` 提供。若你需要高亮，可自行挂载 Prism 或 highlight.js，内容无需改动。

> 如果你的站点由 1.9.3 或更早版本升级而来，重新生成静态页即可让页面不再引用这两个资源。

## 仅开发环境使用（不随程序分发）

| 组件 | 用途 | 许可证 |
| --- | --- | --- |
| VitePress | 文档站构建 | MIT |
| Mermaid | 文档图表 | MIT |

`docs/node_modules/` 已在 `.gitignore` 中排除，不会进入发布包或 Git 仓库。发布包中不含 `docs/` 目录。

## 未包含的第三方资源

- **字体**：仅使用系统字体栈（`-apple-system`、`Segoe UI`、`PingFang SC`、`Microsoft YaHei`、等宽字体回退），未打包任何 `woff/woff2/ttf` 文件，不涉及字体嵌入授权。
- **图标**：后台与前台图标均为项目内手写的 24px 线性 SVG（`admin/inc/header.php`、`dashboard.php`），未使用图标库字体或第三方图标包。
- **图片**：`docs/public/images/` 下全部为程序运行时的实拍截图，不含第三方素材。
- **前端框架**：无。后台与前台均为原生 HTML/CSS/JS，不依赖 jQuery、Bootstrap、Vue 等。
- **代码高亮**：无。代码块为纯文本呈现。

## 兼容性说明

程序中出现的 `DedeCMS`、`Z-Blog` 等名称，仅用于说明标签语法风格与字段命名的兼容关系，属描述性使用。
本项目与上述产品的著作权人无任何关联、合作或授权关系，也未包含其任何代码、模板或素材。
