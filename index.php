<?php
/**
 * 入口文件 - 路由处理
 */

require_once __DIR__ . '/config.php';

// 简单的路由
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// 移除根路径
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath !== '/') {
    $path = str_replace($basePath, '', $path);
}

// 路由
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/public/index.php';
} elseif ($path === '/result.php') {
    require __DIR__ . '/public/result.php';
} elseif (strpos($path, '/api/') === 0) {
    // API路由已经在各自的文件中处理
    http_response_code(404);
    echo json_encode(['error' => 'API endpoint not found']);
} else {
    http_response_code(404);
    echo "404 Not Found";
}
