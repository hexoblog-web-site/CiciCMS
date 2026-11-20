<?php
/**
 * CiciCMS 后台入口
 *
 * 唯一后台界面：legacy（可视化后台）。
 * 鉴权、运行时配置与骨架都在 legacy/index.php，业务数据走 admin/api.php。
 */

require __DIR__ . '/legacy/index.php';
