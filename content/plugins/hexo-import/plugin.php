<?php
/**
 * Hexo 导入插件
 *
 * 转换器与后台页面都在本目录内，核心程序不需要为它改任何一行代码。
 * 导入流程：上传 md / zip → 解析预览 → 确认入库。
 */

if (!defined('CMS_ROOT')) {
    exit;
}

require_once __DIR__ . '/markdown.php';
