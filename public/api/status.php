<?php
/**
 * 查询/处理解读状态 API
 * 如果状态是 processing，自动调用 Gemini 生成解读
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/utils/Database.php';
require_once __DIR__ . '/../../app/utils/PanCalculator.php';
require_once __DIR__ . '/../../app/utils/GeminiClient.php';
require_once __DIR__ . '/../../app/models/Reading.php';

header('Content-Type: application/json; charset=utf-8');
// 允许长时间运行
set_time_limit(300);

session_start();

$result = ['success' => false, 'message' => ''];

try {
    $userId = $_SESSION['user_id'] ?? null;
    $readingId = $_GET['reading_id'] ?? $_POST['reading_id'] ?? $_SESSION['reading_id'] ?? null;
    
    if (!$readingId) {
        throw new Exception('缺少 reading_id');
    }
    
    $readingModel = new Reading();
    $reading = $readingModel->getById($readingId);
    
    if (!$reading) {
        throw new Exception('解读记录不存在');
    }
    
    // 如果状态是 processing 且没有解读内容，调用 Gemini
    if ($reading['status'] === 'processing' && empty($reading['overall_reading'])) {
        try {
            // 格式化命盘数据
            $calculator = new PanCalculator();
            $panText = $calculator->formatForGemini($reading['pan_data']);
            
            // 调用 Gemini 生成整体解读
            $gemini = new GeminiClient();
            $overallReading = $gemini->generateOverallReading($panText);
            
            // 更新结果
            $readingModel->updateOverallReading($readingId, $overallReading);
            
            // 更新返回数据
            $reading['status'] = 'completed';
            $reading['overall_reading'] = $overallReading;
            
        } catch (Exception $e) {
            // 处理失败
            $readingModel->updateStatus($readingId, 'failed');
            $result['error'] = $e->getMessage();
            $reading['status'] = 'failed';
        }
    }
    
    $result['success'] = true;
    $result['reading_id'] = $readingId;
    $result['status'] = $reading['status'];
    $result['name'] = $reading['name'];
    $result['created_at'] = $reading['created_at'];
    $result['pan_data'] = $reading['pan_data'];
    
    // 如果已完成，返回解读内容
    if ($reading['status'] === 'completed') {
        $result['overall_reading'] = $reading['overall_reading'];
        $_SESSION['pan_data'] = $reading['pan_data'];
        $_SESSION['reading_id'] = $readingId;
        $_SESSION['overall_reading'] = $reading['overall_reading'];
    }
    
    // 获取用户所有解读列表
    if ($userId) {
        $result['reading_list'] = $readingModel->getUserReadingsList($userId, 10);
    }
    
} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);