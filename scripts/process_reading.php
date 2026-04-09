<?php
/**
 * 后台处理脚本 - 异步生成解读
 * 用法: php process_reading.php <reading_id>
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/utils/Database.php';
require_once __DIR__ . '/../app/utils/PanCalculator.php';
require_once __DIR__ . '/../app/utils/GeminiClient.php';
require_once __DIR__ . '/../app/models/Reading.php';

// 获取 reading_id 参数
$readingId = $argv[1] ?? null;

if (!$readingId) {
    // 如果没有参数，处理所有 pending 状态的记录
    $readingModel = new Reading();
    $pendingReadings = $readingModel->getPendingReadings(10);
    
    foreach ($pendingReadings as $reading) {
        processReading($reading['id']);
    }
    exit(0);
}

// 处理指定的 reading
processReading($readingId);

function processReading($readingId) {
    $readingModel = new Reading();
    $reading = $readingModel->getById($readingId);
    
    if (!$reading) {
        error_log("Reading not found: $readingId");
        return false;
    }
    
    if ($reading['status'] === 'completed') {
        error_log("Reading already completed: $readingId");
        return true;
    }
    
    // 更新状态为 processing
    $readingModel->updateStatus($readingId, 'processing');
    
    try {
        // 格式化命盘数据
        $calculator = new PanCalculator();
        $panText = $calculator->formatForGemini($reading['pan_data']);
        
        // 调用 Gemini 生成整体解读
        $gemini = new GeminiClient();
        $overallReading = $gemini->generateOverallReading($panText);
        
        // 更新结果
        $readingModel->updateOverallReading($readingId, $overallReading);
        
        error_log("Reading completed: $readingId");
        return true;
        
    } catch (Exception $e) {
        // 处理失败，标记为 failed
        $readingModel->updateStatus($readingId, 'failed');
        error_log("Reading failed: $readingId - " . $e->getMessage());
        return false;
    }
}