<?php
/**
 * 代码高亮插件 · 后台说明页
 * 由 admin/pages/plugin.php 载入，此时后台头部已经输出。
 */
if (!defined('CMS_ADMIN')) {
    http_response_code(404);
    exit;
}
?>

<div class="card">
    <div class="card-head">
        <h2>代码高亮</h2>
        <span class="badge badge-green">运行中</span>
    </div>
    <div class="card-body">
        <p class="hint" style="margin-top:0;line-height:1.9">
            插件在页面输出到 <code>&lt;/body&gt;</code> 之前挂载 Prism，并只在检测到 <code>class="language-"</code> 时才加载资源。
            改动正文后重新生成静态页即可生效。
        </p>
        <div class="kv"><span>支持的语言</span><span>PHP、SQL、JS、TS、HTML、CSS、JSON、YAML、Bash、Python、Go、Nginx、INI、Diff、纯文本</span></div>
        <div class="kv"><span>资源体积</span><span>CSS 约 2KB，JS 约 43KB（仅含代码块的页面加载）</span></div>
        <div class="kv"><span>编写方式</span><span>正文里用三个反引号围栏，后面写语言名</span></div>
    </div>
    <div class="card-foot hint">
        许可证：PrismJS 为 MIT，Copyright (c) 2012 Lea Verou，声明保留在 <code>assets/prism.bundle.js</code> 顶部。
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>写法示例</h2></div>
    <div class="card-body">
        <pre style="background:#161A22;color:#DDE1E8;padding:14px 16px;border-radius:14px;overflow:auto;font-size:13px;line-height:1.7">```php
&lt;?php
$tpl = cms_tag_compile($source);
echo render($tpl, array('post' =&gt; $post));</pre>
        <p class="hint" style="line-height:1.9">
            围栏语言名支持简写：<code>js</code>、<code>py</code>、<code>sh</code>、<code>yml</code> 都能识别。
        </p>
    </div>
</div>
