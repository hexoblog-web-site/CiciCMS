<?php
/**
 * 轻量接口：静态页面浏览量统计
 * 静态 HTML 无法计数，用前端 beacon 调用本接口
 *
 * 注意：静态站不需要会话，这里用 cookie 记住「这台浏览器 1 小时内看过这篇」，
 * 不启动 session —— 否则每个访客都会在服务器上留一个 session 文件。
 *
 * 计数规则（三层，任何一层拦下都不加）：
 *   1) 预取 / 预渲染请求不计数 —— 浏览器与安全软件会把页面里的地址先抓一遍，
 *      旧版用 GET 写库，这些请求全被当成真实阅读
 *   2) 同 IP 同文章 10 分钟只算一次 —— cookie 清掉也绕不过去
 *   3) cookie 1 小时 —— 同一浏览器多点几次不重复加
 *
 * 前端优先 POST + sendBeacon（不影响渲染、页面关闭也不丢）；
 * 老主题里 <img> 指向本接口的写法继续可用，只是同样受上面三层约束。
 */

require __DIR__ . '/includes/bootstrap.php';

/* 计数接口绝不能被 CDN / 浏览器缓存，否则数字不再增长 */
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

/* 参数既可能来自 POST（beacon），也可能来自 GET（老主题的 img 打点） */
$act = post_str('act');
if ($act === '') {
    $act = get_str('act');
}

/**
 * 这次请求是不是预取 / 预渲染？
 *
 * Chrome: Sec-Purpose: prefetch;prerender
 * Firefox: X-Moz: prefetch
 * 其余浏览器与安全软件：Purpose / X-Purpose
 */
function api_is_prefetch()
{
    foreach (array('HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ') as $h) {
        if (empty($_SERVER[$h])) {
            continue;
        }
        $v = strtolower((string) $_SERVER[$h]);
        if (strpos($v, 'prefetch') !== false || strpos($v, 'prerender') !== false || strpos($v, 'preview') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 文件计数器：同一键在 $window 秒内最多放行 $max 次
 *
 * 用 flock 包住读改写——和登录限速一样的道理，分开读改写的话并发全部放行。
 * 计数文件放在 content/data/cache 下，单个 JSON，定期自己清理过期键。
 *
 * @return bool true 放行
 */
function api_throttle($bucket, $key, $window, $max)
{
    $dir = CMS_DATA . '/cache';
    if (!ensure_dir($dir)) {
        return true; // 目录不可写时宁可放行，也不要把计数功能整个打死
    }
    $fp = @fopen($dir . '/api_guard.json', 'c+');
    if (!$fp) {
        return true;
    }
    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        return true;
    }

    $raw  = stream_get_contents($fp);
    $data = json_decode((string) $raw, true);
    $data = is_array($data) ? $data : array();
    $now  = time();

    if (!isset($data[$bucket]) || !is_array($data[$bucket])) {
        $data[$bucket] = array();
    }
    $list = isset($data[$bucket][$key]) && is_array($data[$bucket][$key]) ? $data[$bucket][$key] : array();

    $keep = array();
    foreach ($list as $t) {
        if ($now - (int) $t < $window) {
            $keep[] = (int) $t;
        }
    }

    $allow = count($keep) < $max;
    if ($allow) {
        $keep[] = $now;
    }
    $data[$bucket][$key] = $keep;

    /* 顺手清理：超过 2000 个键就丢掉整桶里已经过期的记录 */
    if (count($data[$bucket]) > 2000) {
        foreach ($data[$bucket] as $k => $v) {
            $alive = false;
            foreach ((array) $v as $t) {
                if ($now - (int) $t < $window) {
                    $alive = true;
                    break;
                }
            }
            if (!$alive) {
                unset($data[$bucket][$k]);
            }
        }
    }

    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, (string) json_encode($data));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);

    return $allow;
}

if ($act === 'view') {
    $id = (int) post_str('id', '0');
    if ($id < 1) {
        $id = get_int('id', 0);
    }
    if ($id < 1) {
        json_out(array('ok' => false));
    }

    // 只统计已发布的文章：草稿的浏览数不应对外可见
    $row = one('SELECT views FROM {p}article WHERE id = ? AND status = 1', array($id));
    if (!$row) {
        json_out(array('ok' => false));
    }

    // 1) 预取不计数：既不是人看的，写进去只会让数字虚高
    if (api_is_prefetch()) {
        json_out(array('ok' => true, 'views' => (int) $row['views'], 'counted' => false));
    }

    // 2) 服务端去重：同 IP 同文章 10 分钟一次（清 cookie 也绕不过）
    // 3) cookie 去重：同浏览器 1 小时一次
    $key    = 'CMS_v_' . $id;
    $last   = isset($_COOKIE[$key]) ? (int) $_COOKIE[$key] : 0;
    $counted = false;

    if (time() - $last > 3600 && api_throttle('view', md5(client_ip() . '|' . $id), 600, 1)) {
        q('UPDATE {p}article SET views = views + 1 WHERE id = ? AND status = 1', array($id));
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie($key, (string) time(), time() + 86400, '/', '', $secure, true);
        }
        $row['views'] = (int) $row['views'] + 1;
        $counted      = true;
    }

    json_out(array('ok' => true, 'views' => (int) $row['views'], 'counted' => $counted));
}

json_out(array('ok' => false, 'msg' => 'unknown action'));
