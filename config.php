<?php
/**
 * 紫微斗数算命系统 - 配置文件
 */

// 加载 .env 文件
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

// 数据库配置 - 使用 SQLite
define('DB_TYPE', 'sqlite');
define('DB_PATH', __DIR__ . '/database/zwei.db');

// 应用配置
define('APP_NAME', '紫微命理');
define('APP_URL', 'http://localhost:8080');

// 支付配置
define('PAYMENT_SINGLE_PRICE', 10);
define('PAYMENT_BUNDLE_PRICE', 30);
define('PAYMENT_MONTHLY_PRICE', 666);
define('PAYMENT_MONTHLY_DAYS', 30);

// 微信支付配置
define('WECHAT_APPID', 'wxe351044898909b63');
define('WECHAT_MCH_ID', '1741287530');
define('WECHAT_API_KEY', '9359a9f7130997abebdb906bb0f8fa91');
define('WECHAT_NOTIFY_URL', 'https://your-domain.com/api/payment/notify.php'); // TODO: 改为你的公网域名
define('WECHAT_CERT_PATH', __DIR__ . '/cert/apiclient_cert.pem');
define('WECHAT_KEY_PATH', __DIR__ . '/cert/apiclient_key.pem');

// Gemini配置 - 使用 OpenAI 兼容接口
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1');
define('GEMINI_MODEL', 'gemini-2.5-flash');

// 时区
date_default_timezone_set('Asia/Shanghai');

// 演示模式（不调用真实AI）
define('DEMO_MODE', true); // 强制启用演示模式
