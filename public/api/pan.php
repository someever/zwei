<?php
/**
 * 排盘API
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/utils/Database.php';
require_once __DIR__ . '/../../app/utils/PanCalculator.php';
require_once __DIR__ . '/../../app/utils/GeminiClient.php';
require_once __DIR__ . '/../../app/models/User.php';
require_once __DIR__ . '/../../app/models/Reading.php';

header('Content-Type: application/json; charset=utf-8');

session_start();

$result = ['success' => false, 'message' => ''];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $data = $_POST ?: $input;
    
    if (!$data) {
        throw new Exception('无效的请求数据');
    }
    
    $requiredFields = ['birthYear', 'birthMonth', 'birthDay', 'birthHour', 'birthMinute', 'gender'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new Exception('缺少必填字段: ' . $field);
        }
    }
    
    // 排盘计算
    $calculator = new PanCalculator();
    $panData = $calculator->calculate(
        $data['birthYear'], $data['birthMonth'], $data['birthDay'],
        $data['birthHour'], $data['birthMinute'], $data['gender'], $data['birthLocation'] ?? ''
    );
    
    // 生成命盘整体解读
    $panText = $calculator->formatForGemini($panData);
    $gemini = new GeminiClient();
    $overallReading = $gemini->generateOverallReading($panText);
    
    // 创建/获取用户
    $userModel = new User();
    $user = $userModel->createDemoUser();
    $_SESSION['user_id'] = $user['id'];
    
    // 保存算命记录
    $readingModel = new Reading();
    $readingId = $readingModel->create([
        'user_id' => $user['id'],
        'session_id' => session_id(),
        'name' => $data['name'] ?? '',
        'gender' => $data['gender'],
        'birth_year' => $data['birthYear'],
        'birth_month' => $data['birthMonth'],
        'birth_day' => $data['birthDay'],
        'birth_hour' => $data['birthHour'],
        'birth_minute' => $data['birthMinute'],
        'birth_location' => $data['birthLocation'] ?? '',
        'lunar_date' => $panData['lunar_date']['year'] . '年' . $panData['lunar_date']['month'] . '月' . $panData['lunar_date']['day'] . '日',
        'zhongshu' => $panData['zhongshu']['zhongshu'],
        'shichen' => $panData['shichen'],
        'pan_data' => $panData,
        'overall_reading' => $overallReading
    ]);
    
    $_SESSION['pan_data'] = $panData;
    $_SESSION['reading_id'] = $readingId;
    $_SESSION['overall_reading'] = $overallReading;
    $_SESSION['purchased_types'] = []; // 初始未购买任何解读
    
    $result['success'] = true;
    $result['pan_data'] = $panData;
    $result['reading_id'] = $readingId;
    $result['overall_reading'] = $overallReading;
    
} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
