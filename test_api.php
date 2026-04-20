<?php
/**
 * Gemini API 连通性测试
 */

require_once __DIR__ . '/config.php';

echo "=== Gemini API 连通性测试 ===\n";
echo "API Key: " . (GEMINI_API_KEY ? substr(GEMINI_API_KEY, 0, 10) . '...' : '未配置') . "\n";
echo "Model: " . GEMINI_MODEL . "\n";
echo "Base URL: " . GEMINI_BASE_URL . "\n\n";

$url = GEMINI_BASE_URL . '/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;

$data = [
    'contents' => [
        ['parts' => [['text' => '你好，请用一句话介绍自己']]]
    ]
];

echo "请求 URL: " . preg_replace('/key=.+/', 'key=***', $url) . "\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";

if ($error) {
    echo "cURL Error: $error\n";
} else {
    $result = json_decode($response, true);
    if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        echo "✅ Gemini API 连通成功!\n";
        echo "回复: " . $result['candidates'][0]['content']['parts'][0]['text'] . "\n";
    } else {
        echo "❌ Gemini API 调用失败\n";
        echo "Response: " . substr($response, 0, 500) . "\n";
    }
}
