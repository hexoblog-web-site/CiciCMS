<?php
/**
 * 正文渲染器：把后台 textarea 里写的代码块围栏转成可高亮的 HTML。
 *
 * 三条设计原则：
 *  1. 只处理 ``` 围栏，围栏之外的内容原样透传 —— 老文章是纯 HTML，一行都不用改
 *  2. 围栏内部的字节不做任何加工：不 trim、不压空白、不替换弯引号、不动全角
 *  3. 必须在 clean_html() 之前执行，让 <script> 先变成实体，
 *     否则净化函数会把教程里演示的代码当脚本删掉
 */

/**
 * 语言标识归一化表：把 js / sh / py 这类简写映射成规范的语言名。
 * 键一律小写；值为规范语言名，none 表示不加 language 标识。
 */
function code_lang_map()
{
    return array(
        'js'         => 'javascript',
        'javascript' => 'javascript',
        'node'       => 'javascript',
        'nodejs'     => 'javascript',
        'ts'         => 'typescript',
        'typescript' => 'typescript',
        'sh'         => 'bash',
        'shell'      => 'bash',
        'bash'       => 'bash',
        'zsh'        => 'bash',
        'console'    => 'bash',
        'terminal'   => 'bash',
        'cmd'        => 'bash',
        'py'         => 'python',
        'python'     => 'python',
        'python3'    => 'python',
        'php'        => 'php',
        'php5'       => 'php',
        'php7'       => 'php',
        'php8'       => 'php',
        'html'       => 'markup',
        'htm'        => 'markup',
        'xml'        => 'markup',
        'svg'        => 'markup',
        'vue'        => 'markup',
        'css'        => 'css',
        'scss'       => 'css',
        'less'       => 'css',
        'sql'        => 'sql',
        'mysql'      => 'sql',
        'mariadb'    => 'sql',
        'pgsql'      => 'sql',
        'postgres'   => 'sql',
        'sqlite'     => 'sql',
        'json'       => 'json',
        'jsonc'      => 'json',
        'yaml'       => 'yaml',
        'yml'        => 'yaml',
        'ini'        => 'ini',
        'conf'       => 'ini',
        'config'     => 'ini',
        'toml'       => 'ini',
        'nginx'      => 'nginx',
        'go'         => 'go',
        'golang'     => 'go',
        'diff'       => 'diff',
        'patch'      => 'diff',
        'text'       => 'none',
        'txt'        => 'none',
        'plain'      => 'none',
        'plaintext'  => 'none',
        ''           => 'none',
    );
}

/** 右上角显示的语言标签文字 */
function code_lang_label($norm)
{
    $map = array(
        'markup'     => 'HTML',
        'javascript' => 'JS',
        'typescript' => 'TS',
        'bash'       => 'BASH',
        'python'     => 'PY',
        'php'        => 'PHP',
        'css'        => 'CSS',
        'sql'        => 'SQL',
        'json'       => 'JSON',
        'yaml'       => 'YAML',
        'ini'        => 'INI',
        'nginx'      => 'NGINX',
        'go'         => 'GO',
        'diff'       => 'DIFF',
        'none'       => 'TEXT',
    );
    return isset($map[$norm]) ? $map[$norm] : strtoupper($norm);
}

/**
 * 生成一个代码块的完整 HTML。
 * $code 是围栏内的原始文本，这里统一转义成实体，浏览器渲染时还原，
 * 浏览器读 textContent 拿到的是原文，复制出来也是原文。
 */
function code_block_html($langRaw, $code)
{
    $map  = code_lang_map();
    $key  = strtolower(trim((string) $langRaw));
    $norm = isset($map[$key]) ? $map[$key] : preg_replace('/[^a-z0-9_+#\-]/', '', $key);

    $cls   = ($norm !== '' && $norm !== 'none') ? ' class="language-' . $norm . '"' : '';
    $label = code_lang_label($norm);
    $data  = $norm === '' ? 'none' : $norm;

    $code = str_replace(array("\r\n", "\r"), "\n", (string) $code);
    $body = htmlspecialchars($code, ENT_NOQUOTES, 'UTF-8');

    return '<div class="code-block" data-lang="' . htmlspecialchars($data, ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="code-head"><span class="code-lang">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<button class="code-copy" type="button" aria-label="复制代码">复制</button></div>'
        . '<pre' . $cls . '><code' . $cls . '>' . $body . '</code></pre>'
        . '</div>';
}

/**
 * 主入口：围栏文本 → HTML。
 * 没有围栏时直接返回原串，保证存量文章零影响。
 *
 * 支持四个及以上反引号包裹三个反引号的内容（用来在教程里演示围栏写法）：
 * 开围栏记下反引号个数，闭合时必须不少于这个数。
 */
function render_content($src)
{
    $src = str_replace(array("\r\n", "\r"), "\n", (string) $src);
    if ($src === '' || strpos($src, '```') === false) {
        return $src;
    }

    $lines = explode("\n", $src);
    $out   = array();
    $in    = false;
    $ticks = 3;
    $lang  = '';
    $buf   = array();

    foreach ($lines as $line) {
        if (!$in) {
            // 开围栏：行首（可带缩进）+ 三个以上反引号 + 可选语言名
            if (preg_match('/^[ \t]*(`{3,})[ \t]*([A-Za-z0-9_+#.\-]*)[ \t]*$/', $line, $m)) {
                $in    = true;
                $ticks = strlen($m[1]);
                $lang  = $m[2];
                $buf   = array();
            } else {
                $out[] = $line;
            }
            continue;
        }

        // 闭围栏：反引号数量不少于开围栏
        if (preg_match('/^[ \t]*(`{3,})[ \t]*$/', $line, $m2) && strlen($m2[1]) >= $ticks) {
            $out[] = code_block_html($lang, implode("\n", $buf));
            $in    = false;
            $lang  = '';
            $buf   = array();
            continue;
        }

        $buf[] = $line;
    }

    // 容错：围栏没写完也照样渲染，不把标记漏到前台
    if ($in) {
        $out[] = code_block_html($lang, implode("\n", $buf));
    }

    return implode("\n", $out);
}

/**
 * 取文章的编辑源文：优先 content_src（含围栏的原文），
 * 老文章没有该字段时回退到 content（存的就是 HTML）。
 */
function content_source($row)
{
    if (is_array($row) && isset($row['content_src']) && trim((string) $row['content_src']) !== '') {
        return (string) $row['content_src'];
    }
    return (is_array($row) && isset($row['content'])) ? (string) $row['content'] : '';
}

/**
 * 把每个代码块换成占位符，返回剩下的部分。
 * 代码块内部已经是转义过的实体，参与 clean_html 只会被误伤
 * （演示 XSS 的 onclick、javascript: 会被删掉），所以整体挖出来保护。
 */
function code_blocks_mask($html, &$blocks)
{
    $blocks = array();
    $startTag = '<div class="code-block"';
    $endTag   = '</code></pre></div>';

    $out = '';
    $pos = 0;

    while (($s = strpos($html, $startTag, $pos)) !== false) {
        $e = strpos($html, $endTag, $s);
        if ($e === false) {
            break;
        }
        $e += strlen($endTag);

        $out .= substr($html, $pos, $s - $pos);
        $key  = "\x02CB" . count($blocks) . "\x03";
        $blocks[$key] = substr($html, $s, $e - $s);
        $out .= $key;
        $pos  = $e;
    }

    $out .= substr($html, $pos);
    return $out;
}

/**
 * 渲染 + 净化：后台保存正文时用这个。
 *
 * 关键点：只有"围栏代码块"是本函数自己生成的，才允许原样保留；
 * 输入里手写的 <div class="code-block"> 一律按普通正文净化，
 * 否则攻击者可以手工拼一段假的代码块，把 <script> 夹带进去绕过过滤。
 */
function render_content_clean($src)
{
    $src = str_replace(array("\r\n", "\r"), "\n", (string) $src);
    if ($src === '') {
        return '';
    }

    $lines = explode("\n", $src);
    $out   = '';        // 最终 HTML
    $plain = array();   // 待净化的普通行
    $in    = false;
    $ticks = 3;
    $lang  = '';
    $buf   = array();

    $flush = function () use (&$plain, &$out) {
        if ($plain) {
            $out .= clean_html(implode("\n", $plain));
            $plain = array();
        }
    };

    foreach ($lines as $line) {
        if (!$in) {
            if (preg_match('/^[ \t]*(`{3,})[ \t]*([A-Za-z0-9_+#.\-]*)[ \t]*$/', $line, $m)) {
                $flush();
                $in    = true;
                $ticks = strlen($m[1]);
                $lang  = $m[2];
                $buf   = array();
            } else {
                $plain[] = $line;
            }
            continue;
        }

        if (preg_match('/^[ \t]*(`{3,})[ \t]*$/', $line, $m2) && strlen($m2[1]) >= $ticks) {
            $out .= code_block_html($lang, implode("\n", $buf));
            $in    = false;
            $lang  = '';
            $buf   = array();
            continue;
        }

        $buf[] = $line;
    }

    // 容错：围栏没写完也照样渲染，不把标记漏到前台
    if ($in) {
        $out .= code_block_html($lang, implode("\n", $buf));
    }
    $flush();

    // 插件可在此改写正文（自动内链、图片懒加载、代码块增强等）
    return (string) cms_apply_filters('content_html', $out, $src);
}

/**
 * 生成摘要用的纯文本：先摘掉代码块，避免摘要里出现一堆代码。
 */
function content_plain($html)
{
    $html = (string) $html;
    if (strpos($html, 'class="code-block"') !== false) {
        $blocks = array();
        $html = code_blocks_mask($html, $blocks);
        // 占位符直接抹掉，别让它漏进摘要
        if ($blocks) {
            $html = strtr($html, array_fill_keys(array_keys($blocks), ''));
        }
    }
    // strip_tags 会把 "<?php" / "<!DOCTYPE" 当成标签，从那里一直吞到下一个 ">"，
    // 正文里讲代码时摘要会莫名少半句。先转义这两个前缀，再正常去标签。
    $html = preg_replace('/<(?=[?!])/', '&lt;', $html);
    $text = strip_tags($html);
    // 与浏览器一致地解实体（ENT_HTML5 覆盖 &nbsp; &colon; 这类命名实体）
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
}
