<?php
/**
 * 后台 AI 生成 Worker
 * 用法: php generate_worker.php <reading_id> [type]
 */
require_once __DIR__ . '/../config.php';

// Debug: 确认进程是否被 exec() 启动
error_log("generate_worker: PID=" . getmypid() . " started for reading={$argv[1]}, type=" . ($argv[2] ?? 'overall'));
require_once __DIR__ . '/../app/utils/Database.php';
require_once __DIR__ . '/../app/utils/PanCalculator.php';
require_once __DIR__ . '/../app/utils/GeminiClient.php';
require_once __DIR__ . '/../app/models/Reading.php';

$readingId = $argv[1] ?? null;
$type = $argv[2] ?? 'overall'; // 默认为整体解读
$attempt = isset($argv[3]) ? (int)$argv[3] : 1;
$maxRetries = 2; // 最多重试2次

if (!$readingId) {
    error_log("generate_worker: missing reading_id");
    exit(1);
}

// 文件锁防止同一 reading+type 的重复进程
$lockDir = __DIR__ . '/../database';
$lockFile = $lockDir . '/worker_' . $readingId . '_' . $type . '.lock';
$fp = fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    error_log("generate_worker: another worker already running for reading {$readingId}/{$type}, exiting");
    exit(0);
}

// 注册脚本结束或死掉时的自动清理勾子（释放并删除该锁文件）
register_shutdown_function(function() use ($fp, $lockFile) {
    if ($fp) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

$readingModel = new Reading();
$calculator = new PanCalculator();
$gemini = new GeminiClient();
$startTime = time();
$lastError = null;

do {
    try {
        $reading = $readingModel->getById($readingId);

        if (!$reading) {
            error_log("generate_worker: reading {$readingId} not found");
            exit(1);
        }

        // 对于 overall，检查状态是否已变化（可能已被其他进程完成）
        if ($type === 'overall' && $reading['status'] === 'completed' && !empty($reading['overall_reading'])) {
            error_log("generate_worker: reading {$readingId} already completed, skipping");
            exit(0);
        }

        $panData = $reading['pan_data'];
        $panText = $calculator->formatForGemini($panData);

        error_log("generate_worker: attempt {$attempt}/" . ($maxRetries + 1) . " for {$type} reading ID {$readingId}");

        switch ($type) {
            case 'overall':
                // 更加宽容的判断：只要没完成且没内容，就尝试生成
                if ($reading['status'] === 'completed' || !empty($reading['overall_reading'])) {
                    error_log("generate_worker: reading {$readingId} status is '{$reading['status']}', skip overall");
                    exit(0);
                }
                $content = $gemini->generateOverallReading($panText);
                $readingModel->updateOverallReading($readingId, $content);
                break;

            case 'career_wealth':
                $content = $gemini->generateCareerWealthReading($panText);
                $readingModel->updateReading($readingId, 'career_wealth', $content);
                break;

            case 'romance':
                $content = $gemini->generateRomanceReading($panText);
                $readingModel->updateReading($readingId, 'romance', $content);
                break;

            case 'health':
                $content = $gemini->generateHealthReading($panText);
                $readingModel->updateReading($readingId, 'health', $content);
                break;

            case 'marriage':
                error_log("generate_worker: marriage type needs partner context, skipping");
                exit(1);

            default:
                error_log("generate_worker: unknown type {$type}");
                exit(1);
        }

        $duration = time() - $startTime;
        error_log("generate_worker: {$type} reading for ID {$readingId} completed successfully in {$duration} seconds");
        exit(0);

    } catch (Exception $e) {
        $lastError = $e;
        error_log("generate_worker: attempt {$attempt} failed for {$readingId}/{$type} - " . $e->getMessage());

        // 如果还能重试，等 10 秒后重试
        if ($attempt <= $maxRetries) {
            error_log("generate_worker: waiting 10s before retry...");
            sleep(10);
        }
    }

    $attempt++;
} while ($attempt <= $maxRetries);

// 所有重试都失败了
if ($type === 'overall') {
    $readingModel->updateStatus($readingId, 'failed');
}
error_log("generate_worker ERROR: all retries exhausted for {$readingId}/{$type} - " . $lastError->getMessage());
exit(1);
