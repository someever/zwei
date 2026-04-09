<?php
/**
 * 后台 AI 生成 Worker
 * 用法: php generate_worker.php <reading_id>
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/utils/Database.php';
require_once __DIR__ . '/../app/utils/PanCalculator.php';
require_once __DIR__ . '/../app/utils/GeminiClient.php';
require_once __DIR__ . '/../app/models/Reading.php';

$readingId = $argv[1] ?? null;
if (!$readingId) {
    error_log("generate_worker: missing reading_id");
    exit(1);
}

try {
    $readingModel = new Reading();
    $reading = $readingModel->getById($readingId);

    if (!$reading) {
        error_log("generate_worker: reading {$readingId} not found");
        exit(1);
    }

    if ($reading['status'] !== 'processing') {
        error_log("generate_worker: reading {$readingId} status is {$reading['status']}, skip");
        exit(0);
    }

    $calculator = new PanCalculator();
    $panText = $calculator->formatForGemini($reading['pan_data']);

    $gemini = new GeminiClient();
    $overallReading = $gemini->generateOverallReading($panText);

    $readingModel->updateOverallReading($readingId, $overallReading);
    error_log("generate_worker: reading {$readingId} completed");

} catch (Exception $e) {
    $readingModel->updateStatus($readingId, 'failed');
    error_log("generate_worker: reading {$readingId} failed - " . $e->getMessage());
    exit(1);
}
