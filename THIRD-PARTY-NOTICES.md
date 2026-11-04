# 第三方组件声明

HtmlCms 本体代码版权归本项目作者所有，以 MIT 许可证发布（见 `LICENSE`）。
下表列出随程序分发或在开发中使用的第三方组件及其许可证。

## 随程序分发

| 组件 | 用途 | 位置 | 许可证 |
| --- | --- | --- | --- |
| PrismJS | 前台代码块语法高亮 | `content/themes/default/assets/prism.bundle.js` | MIT |

### PrismJS

https://prismjs.com

```
MIT LICENSE

Copyright (c) 2012 Lea Verou

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

打包文件 `prism.bundle.js` 顶部已保留上述版权与许可声明。二次分发时请勿删除。
`content/themes/default/assets/code.css` 为本项目自行编写的高亮主题样式，不属于 PrismJS 官方主题。

## 仅开发环境使用（不随程序分发）

| 组件 | 用途 | 许可证 |
| --- | --- | --- |
| VitePress | 文档站构建 | MIT |
| Mermaid | 文档图表 | MIT |

`docs/node_modules/` 已在 `.gitignore` 中排除，不会进入发布包或 Git 仓库。

## 未包含的第三方资源

- **字体**：仅使用系统字体栈（`-apple-system`、`Segoe UI`、`PingFang SC`、`Microsoft YaHei`、等宽字体回退），未打包任何 `woff/woff2/ttf` 文件，不涉及字体嵌入授权。
- **图标**：后台与前台图标均为项目内手写的 24px 线性 SVG（`admin/inc/header.php`、`dashboard.php`），未使用图标库字体或第三方图标包。
- **图片**：`docs/public/images/` 下全部为程序运行时的实拍截图，不含第三方素材。
- **前端框架**：无。后台与前台均为原生 HTML/CSS/JS，不依赖 jQuery、Bootstrap、Vue 等。

## 兼容性说明

程序中出现的 `DedeCMS`、`Z-Blog` 等名称，仅用于说明标签语法风格与字段命名的兼容关系，属描述性使用。
本项目与上述产品的著作权人无任何关联、合作或授权关系，也未包含其任何代码、模板或素材。
