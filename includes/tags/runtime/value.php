<?php
/**
 * HtmlCms 标签引擎 · 运行时
 * 变量求值与作用域：把 $post.title / $item.id 这类路径解析成真实值。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/value.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


function cms_scope()
{
    return isset($GLOBALS['__cms_scope']) && is_array($GLOBALS['__cms_scope'])
        ? $GLOBALS['__cms_scope']
        : array();
}

function cms_loop_push($row, $i = 0)
{
    if (!isset($GLOBALS['__cms_loop']) || !is_array($GLOBALS['__cms_loop'])) {
        $GLOBALS['__cms_loop'] = array();
    }
    $row = cms_row_expand($row);
    if (is_array($row)) {
        $row['index'] = (int) $i + 1;
    }
    $GLOBALS['__cms_loop'][] = $row;
}

function cms_loop_pop()
{
    if (!empty($GLOBALS['__cms_loop'])) {
        array_pop($GLOBALS['__cms_loop']);
    }
}

function cms_loop_top()
{
    if (empty($GLOBALS['__cms_loop'])) {
        return null;
    }
    $n = count($GLOBALS['__cms_loop']);
    return $GLOBALS['__cms_loop'][$n - 1];
}

/** 给数据行补上模板常用的虚拟字段与 dedecms 别名 */
function cms_row_expand($row)
{
    if (!is_array($row)) {
        return $row;
    }

    // ---------- 文档行（文章 / 单页） ----------
    if (isset($row['id'], $row['title'], $row['slug'])) {
        $isPost = array_key_exists('cat_id', $row);

        if (!isset($row['url'])) {
            $row['url'] = $isPost ? u_post($row) : u_page($row);
        }
        $ts = isset($row['created_at']) ? (int) $row['created_at'] : 0;
        if (!isset($row['date'])) {
            $row['date'] = format_date($ts);
        }
        if (!isset($row['datetime'])) {
            $row['datetime'] = format_date($ts, 'Y-m-d H:i');
        }
        if (!isset($row['year'])) {
            $row['year'] = date('Y', $ts > 0 ? $ts : time());
        }
        if (!isset($row['month'])) {
            $row['month'] = date('m', $ts > 0 ? $ts : time());
        }
        if (!isset($row['day'])) {
            $row['day'] = date('d', $ts > 0 ? $ts : time());
        }
        if (!isset($row['summary'])) {
            $row['summary'] = isset($row['excerpt']) && $row['excerpt'] !== ''
                ? $row['excerpt']
                : make_excerpt(isset($row['content']) ? $row['content'] : '', 120);
        }
        if (!isset($row['cat_url']) && !empty($row['cat_dir'])) {
            $row['cat_url'] = u_cat($row['cat_dir'], 1);
        }
        if (!isset($row['cat_name'])) {
            $row['cat_name'] = '';
        }
        if (!isset($row['tag_list'])) {
            $row['tag_list'] = array();
            if (!empty($row['tags'])) {
                foreach (explode(',', $row['tags']) as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $row['tag_list'][] = array('name' => $t, 'title' => $t, 'url' => u_tag($t));
                    }
                }
            }
        }
        if (!isset($row['flag_list'])) {
            $row['flag_list'] = array();
            if (!empty($row['flag'])) {
                $flagMap = cms_flag_map();
                foreach (explode(',', $row['flag']) as $f) {
                    $f = trim($f);
                    if ($f !== '') {
                        $row['flag_list'][] = array(
                            'name'  => $f,
                            'title' => isset($flagMap[$f]) ? $flagMap[$f] : $f,
                            'label' => isset($flagMap[$f]) ? $flagMap[$f] : $f,
                            'value' => $f,
                        );
                    }
                }
            }
        }
        if (!isset($row['cat_list'])) {
            $row['cat_list'] = $row['tag_list']; // 历史变量名兼容
        }

        // dedecms 字段别名：老模板复制过来即可用
        $alias = array(
            'arcurl'      => 'url',
            'litpic'      => 'cover',
            'picname'     => 'cover',
            'pubdate'     => 'created_at',
            'senddate'    => 'created_at',
            'click'       => 'views',
            'description' => 'summary',
            'body'        => 'content',
            'typename'    => 'cat_name',
            'typeid'      => 'cat_id',
            'typeurl'     => 'cat_url',
            'typelink'    => 'cat_url',
        );
        foreach ($alias as $k => $src) {
            if (!array_key_exists($k, $row)) {
                $row[$k] = array_key_exists($src, $row) ? $row[$src] : '';
            }
        }
        if (!isset($row['fulltitle'])) {
            $row['fulltitle'] = $row['title'];
        }
        if (!isset($row['writer'])) {
            $row['writer'] = '';
        }
        if (!isset($row['source'])) {
            $row['source'] = '';
        }
        return $row;
    }

    // ---------- 分类行 ----------
    if (isset($row['id'], $row['name'], $row['dirname'])) {
        if (!isset($row['url'])) {
            $row['url'] = u_cat($row['dirname'], 1);
        }
        $row = array_merge(array(
            'typename'     => $row['name'],
            'title'        => $row['name'],
            'typelink'     => $row['url'],
            'typeurl'      => $row['url'],
            'typeid'       => $row['id'],
            'description'  => isset($row['description']) ? $row['description'] : '',
            'active'       => 0,
            'current'      => 0,
            'currentstyle' => '',
            'class'        => '',
        ), $row);
        return $row;
    }

    return $row;
}

/**
 * 标签取值：{cici:site.name} / {cici:post.title} / {cici:item.url}
 * 找不到返回 null，绝不报错。
 */
function cms_raw($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }
    $parts = explode('.', $path);
    $root  = array_shift($parts);

    $val   = null;
    $found = false;

    // 循环项：item / row 指向栈顶；post 在没有 $post 时也回退到栈顶
    if ($root === 'item' || $root === 'row') {
        $it = cms_loop_top();
        if ($it !== null) {
            $val = $it;
            $found = true;
        }
    }

    if (!$found) {
        $scope = cms_scope();
        if (array_key_exists($root, $scope)) {
            $val = $scope[$root];
            $found = true;
        }
    }

    if (!$found && ($root === 'post' || $root === 'page')) {
        $it = cms_loop_top();
        if ($it !== null) {
            $val = $it;
            $found = true;
        }
    }

    if (!$found) {
        return null;
    }

    $val = cms_row_expand($val);

    foreach ($parts as $k) {
        if (is_array($val) && array_key_exists($k, $val)) {
            $val = $val[$k];
        } elseif (is_object($val) && isset($val->$k)) {
            $val = $val->$k;
        } else {
            return null;
        }
    }
    return $val;
}

/** 标签取值统一转成字符串，数组/对象一律当空 */
function cms_val($path)
{
    return cms_val_str(cms_raw($path));
}

function cms_val_str($val)
{
    if (is_bool($val)) {
        return $val ? '1' : '';
    }
    if ($val === null || is_array($val) || is_object($val)) {
        return '';
    }
    return (string) $val;
}

/** {cici:each} 用：取出数组，标量元素自动包装 */
function cms_tag_array($path)
{
    $val = cms_raw($path);
    if (!is_array($val)) {
        return array();
    }
    $out = array();
    foreach ($val as $k => $v) {
        if (is_array($v)) {
            $out[$k] = $v;
            continue;
        }
        // 关联数组（如 标签名 => 篇数）：键当名称，值当计数
        if (!is_int($k) && $k !== '') {
            $out[$k] = array(
                'name'  => (string) $k,
                'title' => (string) $k,
                'count' => (string) $v,
                'value' => (string) $v,
                'url'   => function_exists('u_tag') ? u_tag((string) $k) : '',
            );
        } else {
            $out[$k] = array(
                'name'  => (string) $v,
                'title' => (string) $v,
                'count' => '',
                'value' => (string) $v,
            );
        }
    }
    return $out;
}
