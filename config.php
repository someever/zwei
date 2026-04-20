<?php
/**
 * 紫微斗数算命系统 - 配置文件
 */

// 增加脚本执行时间限制，防止 AI 调用超时
set_time_limit(660);

// 关闭报错页面输出防污染，开启全局错误收集
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', 1);

// 将所有业务日志（包含前台和跑在后台的 worker）按天集中记录到 logs/app-日期.log
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    if (!@mkdir($logDir, 0777, true)) {
        // 如果创建失败，尝试记录到系统日志
        $error = error_get_last();
        error_log("CRITICAL: Failed to create log directory: $logDir. Error: " . ($error['message'] ?? 'Unknown error') . ". Please run: mkdir -p $logDir && chmod 777 $logDir");
    }
}

$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logFile);
    // 确保文件存在且可写
    if (!file_exists($logFile)) {
        @touch($logFile);
        @chmod($logFile, 0666);
    }
} else {
    //  fallback to default error log if logs dir is not writable
    error_log("Log directory $logDir is not writable. Logging to system default.");
}

// 加载 .env 文件
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0)
            continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// 数据库配置 - 使用 SQLite
define('DB_TYPE', 'sqlite');
define('DB_PATH', __DIR__ . '/database/zwei.db');

// 应用配置
define('APP_NAME', '紫微命理');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:8080');

// 支付配置
define('PAYMENT_OVERVIEW_PRICE', 1);    // 命格解析（整体解读）
define('PAYMENT_SINGLE_PRICE', 5);      // 单次深入解读（事业/合婚/财运/健康）
define('PAYMENT_BUNDLE_PRICE', 30);     // 保留常量，暂不对外露出
define('PAYMENT_MONTHLY_PRICE', 666);   // 保留常量，暂不对外露出
define('PAYMENT_MONTHLY_DAYS', 30);
define('PAYMENT_BYPASS', strtolower($_ENV['PAYMENT_BYPASS'] ?? getenv('PAYMENT_BYPASS') ?: 'false') === 'true');

// 微信支付配置 - 从 .env 读取
define('WECHAT_APPID', $_ENV['WECHAT_APPID'] ?? getenv('WECHAT_APPID') ?: '');
define('WECHAT_APPSECRET', $_ENV['WECHAT_APPSECRET'] ?? getenv('WECHAT_APPSECRET') ?: '');
define('WECHAT_MCH_ID', $_ENV['WECHAT_MCH_ID'] ?? getenv('WECHAT_MCH_ID') ?: '');
define('WECHAT_API_KEY', $_ENV['WECHAT_API_KEY'] ?? getenv('WECHAT_API_KEY') ?: '');
define('WECHAT_NOTIFY_URL', $_ENV['WECHAT_NOTIFY_URL'] ?? getenv('WECHAT_NOTIFY_URL') ?: '');
define('WECHAT_CERT_PATH', __DIR__ . '/cert/apiclient_cert.pem');
define('WECHAT_KEY_PATH', __DIR__ . '/cert/apiclient_key.pem');

// 支付宝支付配置 - 从 .env 读取
define('ALIPAY_APPID', $_ENV['ALIPAY_APPID'] ?? getenv('ALIPAY_APPID') ?: '');
define('ALIPAY_PRIVATE_KEY', $_ENV['ALIPAY_PRIVATE_KEY'] ?? getenv('ALIPAY_PRIVATE_KEY') ?: '');
define('ALIPAY_PUBLIC_KEY', $_ENV['ALIPAY_PUBLIC_KEY'] ?? getenv('ALIPAY_PUBLIC_KEY') ?: '');
define('ALIPAY_NOTIFY_URL', $_ENV['ALIPAY_NOTIFY_URL'] ?? getenv('ALIPAY_NOTIFY_URL') ?: '');
define('ALIPAY_SANDBOX', strtolower($_ENV['ALIPAY_SANDBOX'] ?? getenv('ALIPAY_SANDBOX') ?: 'false') === 'true');
define('ALIPAY_RETURN_URL', $_ENV['ALIPAY_RETURN_URL'] ?? getenv('ALIPAY_RETURN_URL') ?: (APP_URL . '/result.php'));

// Gemini配置
define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_BASE_URL', $_ENV['GEMINI_BASE_URL'] ?? getenv('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta');
define('GEMINI_MODEL', $_ENV['GEMINI_MODEL'] ?? getenv('GEMINI_MODEL') ?: 'gemini-1.5-flash');

// 缘分居 API 配置
define('YUANFENJU_API_KEY', $_ENV['YUANFENJU_API_KEY'] ?? getenv('YUANFENJU_API_KEY') ?: '');

// PHP CLI 路径（用于后台 worker，可手动指定）
$phpCli = $_ENV['PHP_CLI_BIN'] ?? getenv('PHP_CLI_BIN') ?: '';
if (!$phpCli || $phpCli === 'php') {
    // 自动探测：先尝试 which php
    $execPhp = @shell_exec('which php');
    if ($execPhp) {
        $phpCli = trim($execPhp);
    } else {
        $phpCli = (strpos(PHP_BINARY, 'fpm') !== false || strpos(PHP_SAPI, 'fpm') !== false || strpos(PHP_SAPI, 'cgi') !== false) ? 'php' : PHP_BINARY;
    }
}
define('PHP_CLI_BIN', $phpCli);

// 时区
date_default_timezone_set('Asia/Shanghai');
