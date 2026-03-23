<?php
$key = trim(file_get_contents(__DIR__ . '/.env'));
$key = str_replace('GEMINI_API_KEY=', '', $key);

$url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=$key";

$data = [
    'contents' => [
        ['parts' => [['text' => '你好，请用一句话介绍自己']]]
    ]
];

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

echo "HTTP Code: $httpCode\n";
echo "Error: $error\n";
echo "Response: " . substr($response, 0, 300) . "\n";
