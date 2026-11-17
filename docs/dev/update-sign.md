# 更新包签名（发布流程）

自动更新的信任链只有一条：**代码里内置的公钥**。GitHub 仓库、API 返回的 commit、
下载到的 zip 都在信任边界之外——账号被拿走、提交被改写，都不应该让服务器执行新代码。

## 一、三道闸门

| 闸门 | 位置 | 挡什么 |
|---|---|---|
| 来源白名单 | `update_allowed_repos()` | 后台把更新源改成任意 `owner/repo` |
| 发布包 SHA256 | `update_trusted_hashes()` | 下载到的 zip 被替换（没配密钥时的退路） |
| Ed25519 签名清单 | `update_public_key()` | 仓库本身被推送恶意提交 |

三者是叠加的：配了公钥就走签名校验，签名不过直接拒绝；没配公钥才退到哈希/白名单。

## 二、一次性准备：生成密钥

```bash
php tools/sign-release.php keygen
```

输出两个 hex：

- **公钥** → 填进 `includes/updater.php` 的 `update_public_key()`
- **私钥** → 自己收好，`chmod 600`，不进仓库、不上服务器

```bash
# 建议放这里
echo '<私钥 hex>' > ~/.cicicms-update.key
chmod 600 ~/.cicicms-update.key
```

公钥写进代码后，**所有**站点都会用同一把公钥验签。换密钥等于所有站点都要先升级到带新公钥的版本，
所以第一次上线前想清楚，别反复换。

## 三、每次发布

```bash
# 1) 改版本号：includes/bootstrap.php 的 CMS_VERSION
# 2) 生成并签名清单
php tools/sign-release.php sign --key=~/.cicicms-update.key --note="修复登录限速并发绕过"

# 3) 核对一下
php tools/sign-release.php hash
```

`sign` 会写出仓库根的 `update-manifest.json`：

```json
{
  "version": "2.3.0",
  "generated_at": "2026-11-16T17:20:00+08:00",
  "files": { "includes/auth.php": "9f86d0…", "admin/index.php": "…" },
  "signature": "ede2…"
}
```

把这个文件**和代码一起提交**。它只列 `includes/`、`admin/`、`index.php`、`api.php`、`.htaccess`、
`README.md`、`LICENSE` 这些更新器会覆盖的文件（`content/` 与 `config.php` 永远不在清单里）。

## 四、更新器怎么验

`update_verify_package()`：

1. 配了公钥 → 包里必须有 `update-manifest.json`
2. `sodium_crypto_sign_verify_detached(signature, payload, publickey)` 必须通过
3. 清单版本必须与包内 `bootstrap.php` 的版本一致
4. 清单列出的文件必须都在包里
5. 每个文件落盘前，`hash('sha256', $data)` 必须等于清单里的值

任一步失败：不动任何文件、直接报错，日志里留一条 `update: ...`。

## 五、服务器环境要求

- PHP 7.2+ 且启用 `sodium`（常规发行版自带）
- 配了公钥但没有 sodium：更新会被拒绝，并提示装扩展——这是有意的，宁可不能更新，
  也不要退回「不校验就装」

## 六、没有配置密钥时

`update_public_key()` 留空时：

- 该站点只受来源白名单 + 结构校验保护，后台「系统更新」页会显示黄色标记
  「仅来源白名单」，更新日志里也会记一条
- 可以退而求其次用 `update_trusted_hashes()`：把某版本 zip 的 sha256 填进去，
  该版本的整包哈希必须对上。注意 GitHub 的 zip 是现场打包的，压缩库升级可能改变字节，
  所以这个只能当应急手段，不能替代签名
