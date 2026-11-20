<?php
/**
 * Hexo 导入 · 图片本地化
 *
 * Markdown 里的图片大多指向原站（Hexo 的 source/images 或图床）。导入时如果能拿到
 * 原站地址，就把图片下载到本站 content/uploads/images/年/月日/ 下，正文里的地址同步
 * 换成本站地址，整篇内容才算真正搬过来。
 *
 * 只处理 http(s)：data: 直接跳过，file:// 之类的协议一律拒绝。
 */

/**
 * 规范用户填的图片地址前缀
 *
 * @param string $v 形如 https://old.example.com 或 https://old.example.com/blog
 * @return string 合法返回去尾斜杠的地址，否则返回空串
 */
function hexo_img_base($v)
{
    $v = trim((string) $v);
    if ($v === '' || !preg_match('#^https?://[^\s]+$#i', $v)) {
        return '';
    }
    return rtrim($v, '/');
}

/**
 * 把 img 的 src 解析成可下载的绝对地址
 *
 * @param string $src  正文里的原始地址
 * @param string $base 用户填的站址前缀，可为空
 * @return string 解析不出（相对地址 + 没填前缀）时返回空串
 */
function hexo_img_url($src, $base)
{
    $src = trim(html_entity_decode((string) $src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($src === '' || stripos($src, 'data:') === 0) {
        return '';
    }
    if (preg_match('#^https?://#i', $src)) {
        return $src;
    }
    if (strpos($src, '//') === 0) {
        return 'https:' . $src;
    }
    $base = hexo_img_base($base);
    if ($base === '') {
        return '';
    }
    return $base . ($src[0] === '/' ? '' : '/') . $src;
}

/**
 * 取出正文里所有 img 的 src（去重，保留首次出现顺序）
 *
 * @param string $html 已净化的正文 HTML
 * @return array
 */
function hexo_img_srcs($html)
{
    $out = array();
    if (!preg_match_all('#<img\b[^>]*\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', (string) $html, $m, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($m as $one) {
        if (isset($one[2]) && $one[2] !== '') {
            $v = $one[2];
        } elseif (isset($one[3]) && $one[3] !== '') {
            $v = $one[3];
        } elseif (isset($one[4])) {
            $v = $one[4];
        } else {
            continue;
        }
        $v = trim($v);
        if ($v === '' || stripos($v, 'data:') === 0) {
            continue;
        }
        $out[$v] = $v;
    }
    return array_values($out);
}

/**
 * 取出正文里所有指向 /uploads/ 的旧资源引用（图片 src + 附件 href）
 *
 * 逐篇工作台用：这些就是需要你逐张/逐个上传来"对应替换路径"的槽位。
 * 只收相对路径（旧站的 /uploads/...），已经带域名的外链和 data: 都跳过。
 * 返回 array(array('src'=>原路径,'kind'=>'image|file','label'=>展示名))
 */
function hexo_asset_refs($html)
{
    $out = array();
    $html = (string) $html;
    $seen = array();

    // 图片：img 的 src
    if (preg_match_all('#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $v) {
            $v = trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strpos($v, '/uploads/') === false || isset($seen[$v])) {
                continue;
            }
            if (preg_match('#^(https?:)?//#i', $v) || stripos($v, 'data:') === 0) {
                continue;
            }
            $seen[$v] = true;
            $out[] = array('src' => $v, 'kind' => 'image', 'label' => basename(parse_url($v, PHP_URL_PATH)));
        }
    }
    // 附件：a 的 href 指向 /uploads/ 且不是图片后缀
    if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
        foreach ($m[1] as $v) {
            $v = trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strpos($v, '/uploads/') === false || isset($seen[$v])) {
                continue;
            }
            if (preg_match('#^(https?:)?//#i', $v)) {
                continue;
            }
            $ext = strtolower(pathinfo((string) parse_url($v, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'svg'), true)) {
                continue; // 图片后缀交给 img 规则，避免重复
            }
            $seen[$v] = true;
            $out[] = array('src' => $v, 'kind' => 'file', 'label' => urldecode(basename((string) parse_url($v, PHP_URL_PATH))));
        }
    }
    return $out;
}

/**
 * 把 src 换成新地址；三种引号写法都要覆盖，否则会漏一半
 */
function hexo_img_replace($html, $src, $newUrl)
{
    $html = (string) $html;
    $html = str_replace('src="' . $src . '"', 'src="' . $newUrl . '"', $html);
    $html = str_replace("src='" . $src . "'", "src='" . $newUrl . "'", $html);
    $html = str_replace('src=' . $src . ' ', 'src="' . $newUrl . '" ', $html);
    $html = str_replace('src=' . $src . '>', 'src="' . $newUrl . '">', $html);
    return $html;
}

/**
 * 后缀：优先沿用原地址的写法（png 还是 png，jpeg 还是 jpeg），对不上再按真实类型定
 */
function hexo_img_ext($url, $mime)
{
    $map = array(
        'image/jpeg' => array('jpg', 'jpeg'),
        'image/png'  => array('png'),
        'image/gif'  => array('gif'),
        'image/webp' => array('webp'),
    );
    if (!isset($map[$mime])) {
        return '';
    }
    $path = (string) parse_url((string) $url, PHP_URL_PATH);
    $cand = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($cand, $map[$mime], true)) {
        return $cand;
    }
    return $map[$mime][0];
}

/**
 * 下载一张图片
 *
 * @param string $url
 * @param string $err 失败原因（出参）
 * @return array|false array('body','mime','ext')
 */
function hexo_img_download($url, &$err)
{
    $err = '';
    if (!preg_match('#^https?://#i', $url)) {
        $err = '不是 http(s) 地址';
        return false;
    }

    $max    = 5 * 1024 * 1024;
    $body   = '';
    $code   = 0;
    $final  = $url;

    /*
     * 逐跳校验再抓：导入包里的图片地址来自别人的文章，不能无条件相信。
     * 「公网地址 302 到 127.0.0.1」是最常见的绕法，所以自动跟随必须关掉，
     * 每一跳都重新过一次公网判定。
     */
    for ($hop = 0; $hop <= 3; $hop++) {
        $bad = CMS_url_public_check($url);
        if ($bad !== '') {
            $err = $bad;
            return false;
        }
        $r = hexo_img_fetch($url, $max, $err);
        if ($r === false) {
            return false;
        }
        $final = $url;
        $code  = (int) $r['code'];
        $body  = (string) $r['body'];

        if ($code >= 300 && $code < 400 && $r['location'] !== '') {
            $next = hexo_img_absolute($url, (string) $r['location']);
            if ($next === '') {
                $err = '重定向地址无法解析';
                return false;
            }
            $url = $next;
            continue;
        }
        break;
    }

    if ($code < 200 || $code >= 300) {
        $err = 'HTTP ' . $code;
        return false;
    }
    if ($body === '') {
        $err = '下载内容为空';
        return false;
    }

    $info = @getimagesizefromstring($body);
    if (!$info || empty($info['mime']) || strpos((string) $info['mime'], 'image/') !== 0) {
        $err = '返回的不是有效图片';
        return false;
    }

    $ext = hexo_img_ext($final, (string) $info['mime']);
    if ($ext === '') {
        $err = '不支持的格式（只收 jpg / png / gif / webp）';
        return false;
    }

    return array('body' => $body, 'mime' => (string) $info['mime'], 'ext' => $ext);
}

/**
 * 取一次地址，不跟随重定向
 *
 * @return array|false array('code','body','location')
 */
function hexo_img_fetch($url, $max, &$err)
{
    $err      = '';
    $body     = '';
    $tooBig   = false;
    $location = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $hdr = array('Accept: image/*');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CiciCMS-HexoImport/1.0)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$location) {
            if (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }
            return strlen($line);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, $max, &$tooBig) {
            if (strlen($body) + strlen($chunk) > $max) {
                $tooBig = true;
                return 0; // 返回 0 让 curl 中断，避免把大文件全读进内存
            }
            $body .= $chunk;
            return strlen($chunk);
        });

        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($tooBig) {
            $err = '文件超过 5MB';
            return false;
        }
        if ($ok === false) {
            $err = $cerr !== '' ? $cerr : '下载失败';
            return false;
        }
        return array('code' => $code, 'body' => $body, 'location' => $location);
    }

    // 没有 curl 的主机：用流读取，同样按 5MB 截断
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout'         => 20,
            'user_agent'      => 'Mozilla/5.0 (compatible; CiciCMS-HexoImport/1.0)',
            'follow_location' => 0,
            'ignore_errors'   => true,
        ),
    ));
    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) {
        $err = '无法打开地址（可能是证书或网络问题）';
        return false;
    }
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int) $m[1];
            } elseif (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }
    }
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) {
            break;
        }
        if (strlen($body) + strlen($chunk) > $max) {
            $tooBig = true;
            break;
        }
        $body .= $chunk;
    }
    fclose($fp);
    if ($tooBig) {
        $err = '文件超过 5MB';
        return false;
    }
    return array('code' => $code, 'body' => $body, 'location' => $location);
}

/** 把 Location 里的相对地址补成绝对地址 */
function hexo_img_absolute($base, $loc)
{
    $loc = trim((string) $loc);
    if ($loc === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $loc)) {
        return $loc;
    }
    $p = parse_url($base);
    if (!$p || empty($p['host'])) {
        return '';
    }
    $scheme = isset($p['scheme']) ? $p['scheme'] : 'http';
    if (strpos($loc, '//') === 0) {
        return $scheme . ':' . $loc;
    }
    $root = $scheme . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($loc, '/') === 0) {
        return $root . $loc;
    }
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', (string) $p['path']) : '/';
    return $root . $dir . $loc;
}

function hexo_img_store($body, $ext, $mime, $origName)
{
    $dirRel = CMS_CONTENT_REL . '/uploads/images/' . date('Y') . '/' . date('md');
    if (!ensure_dir(CMS_ROOT . '/' . $dirRel)) {
        return false;
    }

    $name = hexo_img_name($ext);
    $abs  = CMS_ROOT . '/' . $dirRel . '/' . $name;
    $try  = 0;
    while (file_exists($abs) && $try < 5) {
        usleep(2);
        $name = hexo_img_name($ext);
        $abs  = CMS_ROOT . '/' . $dirRel . '/' . $name;
        $try++;
    }
    if (file_exists($abs)) {
        // 极端情况下微秒也撞车：退化成「日期 + 13 位毫秒 + 3 位随机」，位数保持一致
        $name = date('Ymd') . substr(hexo_img_usec(), 0, 13) . mt_rand(100, 999) . '.' . $ext;
        $abs  = CMS_ROOT . '/' . $dirRel . '/' . $name;
    }

    if (@file_put_contents($abs, $body) === false) {
        return false;
    }
    @chmod($abs, 0644);

    $rel = $dirRel . '/' . $name;
    try {
        insert_row('attachment', array(
            'path'       => $rel,
            'name'       => str_limit($origName !== '' ? $origName : $name, 80),
            'mime'       => $mime,
            'size'       => strlen($body),
            'created_at' => time(),
        ));
    } catch (Throwable $ex) {
        log_msg('hexo import attachment register failed: ' . $ex->getMessage());
    }

    return array('rel' => $rel, 'url' => upload_url($rel), 'size' => strlen($body), 'mime' => $mime);
}

/** 16 位微秒时间戳，和日期拼起来正好 24 位 */
function hexo_img_usec()
{
    return sprintf('%016.0f', microtime(true) * 1000000);
}

function hexo_img_name($ext)
{
    return date('Ymd') . hexo_img_usec() . '.' . $ext;
}

/**
 * 下载 + 落盘一步到位
 *
 * @param string $url
 * @param array  $cache 同一批次内复用，避免同一张图反复下载
 * @param string $err
 * @return array|false
 */
function hexo_img_localize($url, &$cache, &$err)
{
    $err = '';
    if (isset($cache[$url])) {
        if ($cache[$url] === false) {
            $err = '此前已尝试过并失败';
            return false;
        }
        return $cache[$url];
    }

    $got = hexo_img_download($url, $err);
    if (!$got) {
        $cache[$url] = false;
        return false;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    $orig = urldecode(basename($path));
    $saved = hexo_img_store($got['body'], $got['ext'], $got['mime'], $orig);
    if (!$saved) {
        $err = '写入 ' . CMS_CONTENT_REL . '/uploads/images 失败，检查目录权限';
        $cache[$url] = false;
        return false;
    }

    $cache[$url] = $saved;
    return $saved;
}

/**
 * 逐张上传：把用户在后台选的一张本地图片落到本站
 *
 * 虚拟主机友好：完全复用程序自带的 CMS_upload_image()，校验、按 年/月日/
 * 建目录、时间戳改名、登记 attachment 表都由它完成。每次请求只处理一张，
 * 不占内存、不吃执行时长，也没有 2M 以上整包上传的压力。
 *
 * @param array  $file $_FILES 里的单个条目（如 $_FILES['file']）
 * @param string $err  失败原因（出参）
 * @return array|false 成功返回 array('url','path','name')，失败返回 false
 */
function hexo_img_from_upload($file, &$err, $kind = 'image')
{
    $err = '';
    $ok = false;
    if ($kind === 'file') {
        // 附件：交给站内附件上传器（支持 zip/rar/pdf/doc 等）
        if (!function_exists('CMS_file_upload')) {
            $err = '站内附件上传器不可用，请检查安装完整性';
            return false;
        }
        $ok = CMS_file_upload($file, $err);
    } else {
        // 图片：交给站内图片上传器
        if (!function_exists('CMS_upload_image')) {
            $err = '站内图片上传器不可用，请检查安装完整性';
            return false;
        }
        $ok = CMS_upload_image($file, $err);
    }
    if (!$ok) {
        return false;
    }
    return array(
        'url'  => isset($ok['url']) ? (string) $ok['url'] : '',
        'path' => isset($ok['path']) ? (string) $ok['path'] : '',
        'name' => isset($ok['name']) ? (string) $ok['name'] : '',
        'kind' => $kind,
    );
}
