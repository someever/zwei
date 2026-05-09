<?php
/**
 * 路由器
 */

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// 静态文件
if (preg_match('/\.(css|js|json|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $path)) {
    $file = __DIR__ . '/public' . $path;
    if (file_exists($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        readfile($file);
        exit;
    }
}

// API 路由
if (strpos($path, '/api/') === 0) {
    // $path already includes .php (e.g., /api/pan.php)
    $apiFile = __DIR__ . '/public' . $path;
    if (file_exists($apiFile)) {
        // 读取原始 POST body 并填充 $_POST
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput) && empty($_POST)) {
            $data = json_decode($rawInput, true);
            if ($data) {
                $_POST = $data;
            }
        }
        require $apiFile;
        exit;
    }
}

// 页面及其他 PHP 路由
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/public/index.php';
    exit;
}

// 通用处理 public 目录下的 php 文件
$phpFile = __DIR__ . '/public' . $path;
if (file_exists($phpFile) && pathinfo($phpFile, PATHINFO_EXTENSION) === 'php') {
    require $phpFile;
    exit;
}

// 兜底返回首页
require __DIR__ . '/public/index.php';
