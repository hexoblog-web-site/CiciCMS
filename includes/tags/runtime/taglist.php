<?php
/**
 * CiciCMS 标签引擎 · 运行时
 * 标签聚合（标签云 / 热门标签）。
 *
 * 编译出来的 PHP 会直接调用这里的函数，因此每次请求都会加载。
 *
 * 文件：includes/tags/runtime/taglist.php
 */

if (!defined('CMS_TAG_NS')) {
    http_response_code(404);
    exit;
}


/**
 * 标签云。$o 可以是 limit 数字，也可以是数组：
 * limit / sort(count|name|rand) / current(只看当前文章标签)
 */
function cms_tag_taglist($o = array())
{
    if (!is_array($o)) {
        $o = array('limit' => $o);
    }
    $limit = cms_tag_int(isset($o['limit']) ? $o['limit'] : 50, 50, 1, 200);
    $sort  = strtolower((string) (isset($o['sort']) ? $o['sort'] : 'count'));

    if (!empty($o['current'])) {
        $scope = cms_scope();
        $post  = isset($scope['post']) ? $scope['post'] : null;
        $out   = array();
        if (is_array($post) && !empty($post['tags'])) {
            foreach (explode(',', $post['tags']) as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $out[] = array('name' => $t, 'title' => $t, 'count' => 0, 'url' => u_tag($t));
                }
            }
        }
        return array_slice($out, 0, $limit);
    }

    $tags = fe_tags_all();
    if ($sort === 'name') {
        ksort($tags, SORT_STRING);
    } elseif ($sort === 'rand') {
        $keys = array_keys($tags);
        shuffle($keys);
        $tmp = array();
        foreach ($keys as $k) {
            $tmp[$k] = $tags[$k];
        }
        $tags = $tmp;
    }

    $out = array();
    foreach ($tags as $name => $count) {
        $out[] = array(
            'name'  => (string) $name,
            'title' => (string) $name,
            'count' => (int) $count,
            'url'   => u_tag((string) $name),
        );
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
