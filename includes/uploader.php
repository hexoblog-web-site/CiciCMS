<?php
/**
 * 图片上传器（系统能力）
 *
 * 编辑器插图、媒体信息、栏目横幅，都是「把一张图收进来」这一件事，规则必须一致。
 * 所以校验、命名、落盘、登记全部收在这一个类里，调用方只关心「给我一个地址」。
 *
 * 不做界面，也不做权限判断：调用方必须先确认是管理员，再调这里。
 *
 * 统一规则
 *   · 只收 jpg / jpeg / png / gif / webp，SVG 与 BMP 一律拒绝（SVG 能带脚本）
 *   · 真实类型以 getimagesize 为准，不看后缀
 *   · 单张 ≤ 5MB
 *   · 落在 content/uploads/images/年/月日/ 下，文件名 = 8 位日期 + 16 位微秒时间戳
 *   · 落盘成功后写一条 attachment 记录，媒体信息可见，系统清理不会当无主文件删掉
 */

class Cms_Image_Uploader
{
    /** 最近一次失败原因 */
    protected $error = '';

    /** 是否登记 attachment 表 */
    protected $register = true;

    /** 支持的 mime → 允许的后缀（第一个是首选） */
    protected static $mimes = array(
        'image/jpeg' => array('jpg', 'jpeg'),
        'image/png'  => array('png'),
        'image/gif'  => array('gif'),
        'image/webp' => array('webp'),
    );

    /**
     * @param bool $register 是否登记到 attachment 表
     */
    public function __construct($register = true)
    {
        $this->register = (bool) $register;
    }

    /** 最近一次失败的原因；成功时为空串 */
    public function error()
    {
        return $this->error;
    }

    /* ---------------- 对外能力 ---------------- */

    /**
     * 收一个浏览器上传的文件（$_FILES 里的单个元素）
     *
     * @param array $file
     * @return array|false
     */
    public function receive($file)
    {
        $this->error = '';

        if (empty($file) || !is_array($file) || !isset($file['error'])) {
            return $this->fail('没有收到文件');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorMessage((int) $file['error']));
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return $this->fail('文件来源不合法');
        }

        $max = self::maxBytes();
        if ((int) $file['size'] > $max) {
            return $this->fail('单个文件不能超过 ' . format_bytes($max));
        }

        $orig = (string) $file['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        return $this->store($file['tmp_name'], $orig, $ext, true, (int) $file['size']);
    }

    /**
     * 从网址抓一张图下来，存进本站
     *
     * 只走 http / https，拒绝内网与保留地址（避免被当成探测内网的工具）。
     *
     * @param string $url
     * @return array|false
     */
    public function fromUrl($url)
    {
        $this->error = '';

        $url = trim((string) $url);
        $bad = $this->urlCheck($url);
        if ($bad !== '') {
            return $this->fail($bad);
        }

        $body = '';
        $tooBig = false;
        $max = self::maxBytes();

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CiciCMS)');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: image/*'));
            if (defined('CURLOPT_PROTOCOLS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            if (defined('CURLOPT_REDIR_PROTOCOLS')) {
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, $max, &$tooBig) {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooBig = true;
                    return 0; // 中断传输，不把大文件读进内存
                }
                $body .= $chunk;
                return strlen($chunk);
            });

            $ok    = curl_exec($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr  = (string) curl_error($ch);
            $mimeH = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            if ($tooBig) {
                return $this->fail('图片超过 ' . format_bytes($max));
            }
            if ($ok === false) {
                return $this->fail($cerr !== '' ? '下载失败：' . $cerr : '下载失败');
            }
            if ($code < 200 || $code >= 300) {
                return $this->fail('原地址返回 HTTP ' . $code);
            }
        } else {
            $ctx = stream_context_create(array(
                'http' => array(
                    'timeout'         => 20,
                    'user_agent'      => 'Mozilla/5.0 (compatible; CiciCMS)',
                    'follow_location' => 1,
                    'max_redirects'   => 3,
                ),
            ));
            $fp = @fopen($url, 'rb', false, $ctx);
            if (!$fp) {
                return $this->fail('无法打开地址，可能是证书或网络问题');
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
                return $this->fail('图片超过 ' . format_bytes($max));
            }
        }

        if ($body === '') {
            return $this->fail('原地址返回了空内容');
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $orig = urldecode(basename($path));
        if ($orig === '' || strpos($orig, '.') === false) {
            $orig = 'remote';
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        // 先写临时文件再按统一流程校验，保证「本地 / 远程」走同一套判定
        $tmp = tempnam(sys_get_temp_dir(), 'cmsimg');
        if ($tmp === false || @file_put_contents($tmp, $body) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return $this->fail('临时文件写入失败');
        }

        $r = $this->store($tmp, $orig, $ext, false, strlen($body));
        @unlink($tmp);
        return $r;
    }

    /* ---------------- 内部实现 ---------------- */

    /**
     * 校验并落盘
     *
     * @param string $tmp     临时文件绝对路径
     * @param string $origName 原始文件名
     * @param string $ext      原后缀（可能为空或与真实类型不符）
     * @param bool   $isUpload 是否来自 move_uploaded_file
     * @param int    $size     字节数
     * @return array|false
     */
    protected function store($tmp, $origName, $ext, $isUpload, $size)
    {
        $info = @getimagesize($tmp);
        if (!$info || empty($info['mime']) || !isset(self::$mimes[$info['mime']])) {
            return $this->fail('不是有效图片，只收 jpg / png / gif / webp');
        }

        $mime   = (string) $info['mime'];
        $suffix = $this->pickExt($ext, $mime);
        if ($suffix === '') {
            return $this->fail('不支持的图片格式');
        }

        $dirRel = CMS_CONTENT_REL . '/uploads/images/' . date('Y') . '/' . date('md');
        if (!ensure_dir(CMS_ROOT . '/' . $dirRel)) {
            return $this->fail('创建上传目录失败，请检查 ' . CMS_CONTENT_REL . '/uploads 目录权限');
        }

        $abs  = '';
        $name = '';
        for ($try = 0; $try < 5; $try++) {
            $name = $this->makeName($suffix);
            $abs  = CMS_ROOT . '/' . $dirRel . '/' . $name;
            if (!file_exists($abs)) {
                break;
            }
            usleep(2);
        }
        if (file_exists($abs)) {
            // 微秒也撞车：退化成「日期 + 13 位毫秒 + 3 位随机」，位数保持一致
            $name = date('Ymd') . substr($this->usec(), 0, 13) . mt_rand(100, 999) . '.' . $suffix;
            $abs  = CMS_ROOT . '/' . $dirRel . '/' . $name;
        }

        $moved = $isUpload
            ? @move_uploaded_file($tmp, $abs)
            : @rename($tmp, $abs);

        if (!$moved) {
            // rename 跨分区会失败，退回复制
            $moved = !$isUpload && @copy($tmp, $abs);
        }
        if (!$moved) {
            return $this->fail('保存文件失败');
        }
        @chmod($abs, 0644);

        $rel = $dirRel . '/' . $name;

        if ($this->register) {
            try {
                insert_row('attachment', array(
                    'path'       => $rel,
                    'name'       => str_limit($origName !== '' ? basename($origName) : $name, 80),
                    'mime'       => $mime,
                    'size'       => (int) $size,
                    'created_at' => time(),
                ));
            } catch (Throwable $ex) {
                // 登记失败不该让上传白做，记日志即可
                log_msg('attachment register failed: ' . $ex->getMessage());
            }
        }

        return array(
            'path'   => $rel,
            'rel'    => upload_rel($rel),
            'url'    => upload_url($rel),
            'name'   => str_limit($origName !== '' ? basename($origName) : $name, 80),
            'mime'   => $mime,
            'size'   => (int) $size,
            'width'  => isset($info[0]) ? (int) $info[0] : 0,
            'height' => isset($info[1]) ? (int) $info[1] : 0,
        );
    }

    /**
     * 后缀：沿用自己的写法（.jpeg 还是 .jpeg），对不上真实类型时按类型定
     */
    protected function pickExt($ext, $mime)
    {
        $allow = isset(self::$mimes[$mime]) ? self::$mimes[$mime] : array();
        if (in_array($ext, $allow, true)) {
            return $ext;
        }
        return isset($allow[0]) ? $allow[0] : '';
    }

    /** 文件名：8 位日期 + 16 位微秒时间戳，共 24 位 */
    protected function makeName($ext)
    {
        return date('Ymd') . $this->usec() . '.' . $ext;
    }

    protected function usec()
    {
        return sprintf('%016.0f', microtime(true) * 1000000);
    }

    /**
     * 地址白名单：只放行能解析到公网的 http(s)
     *
     * 说明：先解析域名再判断，能挡住「填个 127.0.0.1 探内网」这类误用；
     * 挡不住 DNS 重绑定，但这是管理员自己的操作，风险与后台管理权限相当。
     *
     * @return string 不通过时返回原因，通过返回空串
     */
    protected function urlCheck($url)
    {
        if ($url === '') {
            return '请填写图片地址';
        }
        if (!preg_match('#^https?://#i', $url)) {
            return '只支持 http / https 地址';
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return '地址格式不正确';
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : (string) gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '无法解析该域名';
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return '不允许抓取内网或保留地址';
        }
        return '';
    }

    protected function uploadErrorMessage($code)
    {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            return '文件超过服务器允许的上传大小（当前上限 ' . format_bytes(self::maxBytes()) . '）';
        }
        if ($code === UPLOAD_ERR_NO_FILE) {
            return '没有选择文件';
        }
        if ($code === UPLOAD_ERR_PARTIAL) {
            return '文件只上传了一部分，请重试';
        }
        return '上传失败（错误码 ' . (int) $code . '）';
    }

    protected function fail($msg)
    {
        $this->error = $msg;
        return false;
    }

    /* ---------------- 规则常量 ---------------- */

    /** 允许的后缀 */
    public static function exts()
    {
        return array('jpg', 'jpeg', 'png', 'gif', 'webp');
    }

    /** 单张上限（字节） */
    public static function maxBytes()
    {
        return 5 * 1024 * 1024;
    }
}
