<?php
/**
 * 极简 Markdown → HTML
 *
 * 只服务于导入器，不作为编辑器使用。覆盖博客里真正会用到的语法：
 * 标题、段落、粗斜体、行内代码、围栏代码块、列表、引用、表格、分割线、链接、图片。
 *
 * 两条原则：
 *  1. 默认转义：正文里的 < > & 先变实体，避免导入的 md 里夹带脚本；
 *     只有自己识别出来的标签才输出，代码块走 CiciCMS 的 code_block_html()，
 *     和后台手写的代码块长得完全一样。
 *  2. 不追求完整规范：遇到不认识的结构就当普通段落，宁可少渲染，不可渲染错。
 */

/** 内联格式：转义 → 代码/图片/链接/强调 → 还原代码占位 */
function hexo_md_inline($text)
{
    $text = htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');

    // 行内代码先挖出来，避免里面的 * _ [ ] 被当成格式标记
    $codes = array();
    $text  = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$codes) {
        $key = "\x02IC" . count($codes) . "\x03";
        $codes[$key] = '<code>' . $m[1] . '</code>';
        return $key;
    }, $text);

    // 图片在链接之前处理，否则 ![a](b) 会被链接规则先吃掉
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
        $alt = $m[1] !== '' ? $m[1] : '';
        $ttl = isset($m[3]) && $m[3] !== '' ? ' title="' . $m[3] . '"' : '';
        return '<img src="' . $m[2] . '" alt="' . $alt . '"' . $ttl . ' loading="lazy">';
    }, $text);

    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
        $ttl = isset($m[3]) && $m[3] !== '' ? ' title="' . $m[3] . '"' : '';
        $ext = preg_match('#^https?://#i', $m[2]) ? ' target="_blank" rel="noopener nofollow"' : '';
        return '<a href="' . $m[2] . '"' . $ttl . $ext . '>' . $m[1] . '</a>';
    }, $text);

    // 裸地址（Hexo 里很常见）
    $text = preg_replace('#(^|[\s(])(https?://[^\s<)]+)#i', '$1<a href="$2" target="_blank" rel="noopener nofollow">$2</a>', $text);

    $text = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__([^_\n]+)__/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);
    $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/', '<em>$1</em>', $text);
    $text = preg_replace('/~~([^~\n]+)~~/', '<del>$1</del>', $text);
    $text = str_replace('  ', '<br>', $text);

    return $codes ? strtr($text, $codes) : $text;
}

/** 判断是不是表格分隔行：|---|:--:|---| */
function hexo_md_is_sep($line)
{
    return preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $line) === 1;
}

/** 拆分表格行成单元格 */
function hexo_md_cells($line)
{
    $line = trim($line);
    $line = trim($line, '|');
    return array_map('trim', explode('|', $line));
}

/**
 * 主转换入口
 *
 * @return array array('html' => HTML, 'summary' => 摘要候选文本)
 */
function hexo_md_to_html($md)
{
    $md = str_replace(array("\r\n", "\r"), "\n", (string) $md);
    $md = preg_replace('/^\xEF\xBB\xBF/', '', $md);

    // Hexo 标签插件与注释：不解释，直接去掉，避免把 {% codeblock %} 之类漏到正文
    $md = preg_replace('/\{%[\s\S]*?%\}/', '', $md);
    $md = preg_replace('/\{#[\s\S]*?#\}/', '', $md);
    $md = str_replace('<!-- more -->', "\n<!--more-->\n", $md);

    $lines = explode("\n", $md);
    $out   = array();
    $i     = 0;
    $n     = count($lines);

    while ($i < $n) {
        $line = $lines[$i];

        // 空行
        if (trim($line) === '') {
            $i++;
            continue;
        }

        // 围栏代码块（``` 或 ~~~），与后台编辑器同款输出
        if (preg_match('/^[ \t]*(`{3,}|~{3,})[ \t]*([A-Za-z0-9_+#.\-]*)[ \t]*$/', $line, $m)) {
            $fence = $m[1];
            $lang  = $m[2];
            $mark  = $fence[0];
            $len   = strlen($fence);
            $buf   = array();
            $i++;
            while ($i < $n) {
                if (preg_match('/^[ \t]*(' . preg_quote($mark, '/') . '{' . $len . ',})[ \t]*$/', $lines[$i])) {
                    $i++;
                    break;
                }
                $buf[] = $lines[$i];
                $i++;
            }
            $out[] = code_block_html($lang, implode("\n", $buf));
            continue;
        }

        // 标题
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $lvl    = strlen($m[1]);
            $out[]  = '<h' . $lvl . '>' . hexo_md_inline(trim(preg_replace('/\s+#+\s*$/', '', $m[2]))) . '</h' . $lvl . '>';
            $i++;
            continue;
        }

        // 分割线
        if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
            $out[] = '<hr>';
            $i++;
            continue;
        }

        // 引用（连续 > 行）
        if (preg_match('/^\s*>\s?/', $line)) {
            $buf = array();
            while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) {
                $buf[] = $m[1];
                $i++;
            }
            $inner = hexo_md_to_html(implode("\n", $buf));
            $out[] = '<blockquote>' . $inner['html'] . '</blockquote>';
            continue;
        }

        // 表格：当前行含 |，下一行是分隔行
        if (strpos($line, '|') !== false && $i + 1 < $n && hexo_md_is_sep($lines[$i + 1])) {
            $head = hexo_md_cells($line);
            $i += 2;
            $body = array();
            while ($i < $n && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== '') {
                $body[] = hexo_md_cells($lines[$i]);
                $i++;
            }
            $html = '<table><thead><tr>';
            foreach ($head as $c) {
                $html .= '<th>' . hexo_md_inline($c) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($body as $row) {
                $html .= '<tr>';
                foreach ($head as $k => $_) {
                    $html .= '<td>' . hexo_md_inline(isset($row[$k]) ? $row[$k] : '') . '</td>';
                }
                $html .= '</tr>';
            }
            $out[] = $html . '</tbody></table>';
            continue;
        }

        // 列表（有序 / 无序，支持一级缩进嵌套）
        if (preg_match('/^\s*([-*+]|\d+\.)\s+/', $line)) {
            $ordered = preg_match('/^\s*\d+\./', $line) === 1;
            $tag     = $ordered ? 'ol' : 'ul';
            $items   = array();
            while ($i < $n && preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $lines[$i], $m)) {
                $items[] = $m[2];
                $i++;
                // 续行：缩进 2 空格以上且不是新的列表项
                while ($i < $n && trim($lines[$i]) !== ''
                       && preg_match('/^\s{2,}\S/', $lines[$i])
                       && !preg_match('/^\s*([-*+]|\d+\.)\s+/', $lines[$i])) {
                    $items[count($items) - 1] .= ' ' . trim($lines[$i]);
                    $i++;
                }
            }
            $html = '<' . $tag . '>';
            foreach ($items as $it) {
                $html .= '<li>' . hexo_md_inline($it) . '</li>';
            }
            $out[] = $html . '</' . $tag . '>';
            continue;
        }

        // 原始 HTML 块：整段透传，后面统一走 clean_html
        if (preg_match('/^\s*<([a-zA-Z][a-zA-Z0-9]*)/', $line)) {
            $buf = array();
            while ($i < $n && trim($lines[$i]) !== '') {
                $buf[] = $lines[$i];
                $i++;
            }
            $out[] = implode("\n", $buf);
            continue;
        }

        // 段落：连续非空行合并
        $buf = array();
        while ($i < $n && trim($lines[$i]) !== ''
               && !preg_match('/^(#{1,6}\s|```|~~~|>|\s*([-*+]|\d+\.)\s)/', $lines[$i])) {
            $buf[] = trim($lines[$i]);
            $i++;
        }
        if ($buf) {
            $out[] = '<p>' . hexo_md_inline(implode(' ', $buf)) . '</p>';
        } else {
            // 保险：万一上面都没匹配上，跳过这一行，绝不进入死循环
            $i++;
        }
    }

    return array('html' => implode("\n", $out));
}

/**
 * 解析 front-matter
 *
 * Hexo 的头部是 YAML，但实际用到的只有几种写法，这里手写一个小解析器：
 *   key: value
 *   key: [a, b]
 *   key:
 *     - a
 *     - b
 *
 * @return array array(meta, body)
 */
function hexo_parse_front_matter($raw)
{
    $raw  = str_replace(array("\r\n", "\r"), "\n", (string) $raw);
    $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $meta = array();
    $body = $raw;

    if (preg_match('/^---\n/', $raw)) {
        $end = strpos($raw, "\n---", 4);
        if ($end !== false) {
            $head = substr($raw, 4, $end - 4);
            $body = ltrim(substr($raw, $end + 4), "\n");
            $key  = null;
            foreach (explode("\n", $head) as $ln) {
                if (trim($ln) === '') {
                    continue;
                }
                // 列表项
                if (preg_match('/^\s*-\s+(.*)$/', $ln, $m) && $key !== null) {
                    $meta[$key][] = hexo_trim_val($m[1]);
                    continue;
                }
                if (preg_match('/^([A-Za-z0-9_\-]+)\s*:\s*(.*)$/', $ln, $m)) {
                    $key = strtolower($m[1]);
                    $val = trim($m[2]);
                    if ($val === '') {
                        $meta[$key] = array();
                    } elseif (preg_match('/^\[(.*)\]$/', $val, $mm)) {
                        $arr = array();
                        foreach (explode(',', $mm[1]) as $v) {
                            $v = hexo_trim_val($v);
                            if ($v !== '') {
                                $arr[] = $v;
                            }
                        }
                        $meta[$key] = $arr;
                    } else {
                        $meta[$key] = hexo_trim_val($val);
                    }
                }
            }
        }
    }

    return array($meta, $body);
}

/** 去掉 YAML 值两侧的引号 */
function hexo_trim_val($v)
{
    $v = trim((string) $v);
    if (strlen($v) >= 2) {
        $a = $v[0];
        $b = substr($v, -1);
        if (($a === '"' && $b === '"') || ($a === "'" && $b === "'")) {
            $v = substr($v, 1, -1);
        }
    }
    return $v;
}
