<?php
/**
 * 排盘API - 同步调用 Gemini，但前端轮询状态
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/utils/Database.php';
require_once __DIR__ . '/../../app/utils/PanCalculator.php';
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

    // 创建/获取用户
    $userModel = new User();
    $user = $userModel->createDemoUser();
    $_SESSION['user_id'] = $user['id'];

    // 检查是否有未完成的解读
    $readingModel = new Reading();
    $latestReading = $readingModel->getLatestByUserId($user['id']);
    
    if ($latestReading && in_array($latestReading['status'], ['pending', 'processing'])) {
        // 超过10分钟的记录视为超时，自动标记为 failed，允许重新提交
        $createdAt = strtotime($latestReading['created_at'] . ' UTC');
        if (time() - $createdAt > 600) {
            $readingModel->updateStatus($latestReading['id'], 'failed');
        } else {
            // 返回现有解读 ID，让前端去 processing 页面等待
            $result['success'] = true;
            $result['reading_id'] = $latestReading['id'];
            $result['status'] = $latestReading['status'];
            $result['message'] = '您有正在进行的解读';
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 排盘计算
    $calculator = new PanCalculator();
    $panData = $calculator->calculate(
        $data['birthYear'],
        $data['birthMonth'],
        $data['birthDay'],
        $data['birthHour'],
        $data['birthMinute'],
        $data['gender'],
        [
            'location' => $data['birthLocation'] ?? '',
            'province' => $data['province'] ?? '',
            'city' => $data['city'] ?? ''
        ]
    );

    // 保存算命记录（状态为 processing）
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
        'overall_reading' => '',
        'status' => 'processing'
    ]);

    // 保存到 session
    $_SESSION['pan_data'] = $panData;
    $_SESSION['reading_id'] = $readingId;
    $_SESSION['purchased_types'] = [];

    $result['success'] = true;
    $result['reading_id'] = $readingId;
    $result['status'] = 'processing';
    $result['message'] = '排盘完成，正在生成解读...';

} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// 提交成功后，在后台启动独立进程生成 AI 解读
if (!empty($result['success']) && !empty($readingId)) {
    // 明确保存并关闭 Session，避免锁定
    session_write_close();
    
    // 明确关闭数据库连接，确保所有事务已提交且释放文件锁
    Database::close();

    $workerScript = __DIR__ . '/../../scripts/generate_worker.php';
    // 在 Web/FPM 环境下，PHP_BINARY 往往是 php-fpm 导致无法执行命令行。改用 'php'
    $phpBin = (strpos(PHP_BINARY, 'fpm') !== false || strpos(PHP_SAPI, 'fpm') !== false || strpos(PHP_SAPI, 'cgi') !== false) ? 'php' : PHP_BINARY;
    
    // nohup 保证请求结束后后台进程不被杀死，> /dev/null 不影响 error_log() 的输出
    $cmd = sprintf('nohup %s %s %d > /dev/null 2>&1 &', escapeshellarg($phpBin), escapeshellarg($workerScript), (int)$readingId);
    exec($cmd);
}