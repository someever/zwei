<?php
/**
 * 诊断脚本 - 检查服务器环境
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== 知运星 生产环境诊断 ===\n\n";

// 1. 检查目录权限
$dirs = [
    '根目录' => __DIR__ . '/..',
    'logs目录' => __DIR__ . '/../logs',
    'database目录' => __DIR__ . '/../database',
];

foreach ($dirs as $name => $path) {
    $exists = is_dir($path);
    $writable = is_writable($path);
    echo "[$name]: " . realpath($path) . "\n";
    echo "  - 是否存在: " . ($exists ? "是" : "否") . "\n";
    echo "  - 是否可写: " . ($writable ? "是" : "否") . "\n";
    if (!$writable) {
        $owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($path))['name'] : 'unknown';
        echo "  - 当前所有者: $owner\n";
    }
    echo "\n";
}

// 2. 检查关键函数
$functions = ['exec', 'shell_exec', 'proc_open', 'flock'];
echo "=== 关键函数检查 ===\n";
foreach ($functions as $func) {
    $disabled = explode(',', ini_get('disable_functions'));
    $isDisabled = in_array($func, array_map('trim', $disabled));
    echo "[$func]: " . ($isDisabled ? "❌ 已禁用" : "✅ 已启用") . "\n";
}
echo "\n";

// 3. 检查 PHP CLI
echo "=== PHP CLI 检查 ===\n";
$phpBin = PHP_CLI_BIN;
echo "配置的 PHP 二进制: $phpBin\n";
$output = [];
$returnVar = 0;
exec("$phpBin -v 2>&1", $output, $returnVar);
if ($returnVar === 0) {
    echo "✅ PHP CLI 调用成功:\n" . implode("\n", array_slice($output, 0, 1)) . "\n";
} else {
    echo "❌ PHP CLI 调用失败 (错误代码 $returnVar):\n" . implode("\n", $output) . "\n";
    echo "建议: 请在 .env 中设置正确的 PHP_CLI_BIN 绝对路径，例如 /usr/bin/php\n";
}
echo "\n";

// 4. 尝试创建测试日志
echo "=== 日志测试 ===\n";
error_log("Diagnostic test log entry at " . date('Y-m-d H:i:s'));
$logFile = ini_get('error_log');
echo "当前 error_log 配置: $logFile\n";
if (file_exists($logFile)) {
    echo "✅ 日志文件存在且已尝试写入内容。\n";
} else {
    echo "❌ 日志文件不存在，写入可能失败。\n";
}
