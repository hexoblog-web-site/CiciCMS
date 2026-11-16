<?php
/**
 * 发布签名工具（开发者本地运行，不进站点）
 *
 * 用途：给一个版本生成 update-manifest.json，并用 Ed25519 私钥签名。
 * 更新器（includes/updater.php）用内置公钥验签，验不过就拒绝安装 ——
 * 这样即使 GitHub 账号被拿走、有人推了恶意提交，服务器也不会执行未签名的代码。
 *
 * 用法：
 *   php tools/sign-release.php keygen
 *       生成一对密钥。公钥填进 includes/updater.php 的 update_public_key()，
 *       私钥自己收好（不要提交进仓库，不要放到服务器上）。
 *
 *   php tools/sign-release.php hash [版本号]
 *       只算当前目录代码的「内容哈希」和各文件哈希，用于核对发布物。
 *
 *   php tools/sign-release.php sign --key=<私钥文件> [--root=<仓库根>] [--note=说明]
 *       生成 update-manifest.json 并写进仓库根目录，随版本一起提交。
 *       私钥也可以放在环境变量 CICICMS_SIGN_KEY（64 位 hex）。
 *
 * 规范载荷与 update_sign_payload() 必须完全一致，改一处就要改两处。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "只能在命令行运行\n");
    exit(1);
}

$argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$cmd  = isset($argv[1]) ? $argv[1] : '';

function sig_opt($argv, $name, $default = '')
{
    foreach ($argv as $a) {
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

function sig_root($argv)
{
    $root = sig_opt($argv, 'root', dirname(__DIR__));
    return rtrim(str_replace('\\', '/', $root), '/');
}

/** 版本号取自 includes/bootstrap.php */
function sig_version($root)
{
    $file = $root . '/includes/bootstrap.php';
    if (!is_file($file)) {
        return '';
    }
    $src = (string) file_get_contents($file);
    if (preg_match("/define\\(\\s*'CMS_VERSION'\\s*,\\s*'([0-9][0-9A-Za-z._-]*)'\\s*\\)/", $src, $m)) {
        return $m[1];
    }
    return '';
}

/** 参与发布清单的相对路径（与 update_path_allowed() 对齐） */
function sig_allowed($rel)
{
    $rel = str_replace('\\', '/', ltrim((string) $rel, '/'));
    if ($rel === '' || strpos($rel, '..') !== false) {
        return false;
    }
    if ($rel === 'update-manifest.json') {
        return false;
    }
    if (in_array($rel, array('index.php', 'api.php', '.htaccess', 'README.md', 'LICENSE'), true)) {
        return true;
    }
    return strpos($rel, 'includes/') === 0 || strpos($rel, 'admin/') === 0;
}

/** 收集所有要纳入清单的文件 */
function sig_collect($root)
{
    $out = array();

    foreach (array('index.php', 'api.php', '.htaccess', 'README.md', 'LICENSE') as $f) {
        if (is_file($root . '/' . $f)) {
            $out[$f] = $root . '/' . $f;
        }
    }

    foreach (array('includes', 'admin') as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $abs = str_replace('\\', '/', $f->getPathname());
            $rel = ltrim(substr($abs, strlen($root)), '/');
            if (sig_allowed($rel)) {
                $out[$rel] = $abs;
            }
        }
    }

    ksort($out);
    return $out;
}

function sig_payload($version, $generatedAt, $files)
{
    ksort($files);
    return json_encode(array(
        'version'      => (string) $version,
        'generated_at' => (string) $generatedAt,
        'files'        => $files,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

switch ($cmd) {
    case 'keygen':
        if (!function_exists('sodium_crypto_sign_keypair')) {
            fwrite(STDERR, "需要 sodium 扩展（PHP 7.2+ 通常自带）\n");
            exit(2);
        }
        $kp  = sodium_crypto_sign_keypair();
        $pub = bin2hex(sodium_crypto_sign_publickey($kp));
        $sec = bin2hex(sodium_crypto_sign_secretkey($kp));
        echo "公钥（填进 includes/updater.php 的 update_public_key）：\n" . $pub . "\n\n";
        echo "私钥（自己收好，不要提交，不要放服务器）：\n" . $sec . "\n\n";
        echo "建议：把私钥存成 ~/.cicicms-update.key，权限 600，用 --key 指过来。\n";
        break;

    case 'hash':
        $root = sig_root($argv);
        $ver  = isset($argv[2]) && $argv[2] !== '' ? $argv[2] : sig_version($root);
        $files = sig_collect($root);
        $lines = array();
        foreach ($files as $rel => $abs) {
            $lines[$rel] = hash_file('sha256', $abs);
        }
        echo '版本：' . ($ver !== '' ? $ver : '未读到') . "\n";
        echo '文件数：' . count($files) . "\n";
        echo '内容哈希：' . hash('sha256', sig_payload($ver, '', $lines)) . "\n";
        echo "\n每个文件的 sha256：\n";
        foreach ($lines as $rel => $h) {
            echo '  ' . $h . '  ' . $rel . "\n";
        }
        break;

    case 'sign':
        if (!function_exists('sodium_crypto_sign_detached')) {
            fwrite(STDERR, "需要 sodium 扩展（PHP 7.2+ 通常自带）\n");
            exit(2);
        }
        $root = sig_root($argv);
        $ver  = sig_version($root);
        if ($ver === '') {
            fwrite(STDERR, "读不到版本号：请确认 --root 指向仓库根，且 includes/bootstrap.php 里有 CMS_VERSION\n");
            exit(2);
        }

        $keyHex = sig_opt($argv, 'key');
        if ($keyHex !== '' && is_file($keyHex)) {
            $keyHex = trim((string) file_get_contents($keyHex));
        }
        if ($keyHex === '') {
            $keyHex = trim((string) getenv('CICICMS_SIGN_KEY'));
        }
        $sec = $keyHex !== '' ? @hex2bin($keyHex) : false;
        if ($sec === false || strlen($sec) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            fwrite(STDERR, "私钥无效：用 --key=<文件> 或环境变量 CICICMS_SIGN_KEY 提供 128 位 hex 私钥\n");
            exit(2);
        }

        $files = array();
        foreach (sig_collect($root) as $rel => $abs) {
            $files[$rel] = hash_file('sha256', $abs);
        }
        ksort($files);
        $at      = date('c');
        $payload = sig_payload($ver, $at, $files);
        $sig     = bin2hex(sodium_crypto_sign_detached($payload, $sec));

        $manifest = array(
            'version'      => $ver,
            'generated_at' => $at,
            'files'        => $files,
            'signature'    => $sig,
            'note'         => sig_opt($argv, 'note'),
        );
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $dest = $root . '/update-manifest.json';
        if (file_put_contents($dest, $json . "\n") === false) {
            fwrite(STDERR, "写入失败：" . $dest . "\n");
            exit(2);
        }
        echo "已生成 " . $dest . "\n";
        echo '版本：' . $ver . '　文件数：' . count($files) . "\n";
        echo "记得把 update-manifest.json 一起提交，并让站点里的公钥与私钥配对。\n";
        break;

    default:
        echo "用法：\n";
        echo "  php tools/sign-release.php keygen\n";
        echo "  php tools/sign-release.php hash [版本号]\n";
        echo "  php tools/sign-release.php sign --key=<私钥文件> [--root=<仓库根>] [--note=说明]\n";
        exit(1);
}
