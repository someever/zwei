<?php
/**
 * 处理中页面 - 用户提交后等待异步处理
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/utils/Database.php';
require_once __DIR__ . '/../app/models/Reading.php';

session_start();

$userId = $_SESSION['user_id'] ?? null;
$readingId = $_SESSION['reading_id'] ?? null;

if (!$userId || !$readingId) {
    header('Location: index.php');
    exit;
}

$readingModel = new Reading();
$reading = $readingModel->getById($readingId);

if (!$reading) {
    header('Location: index.php');
    exit;
}

// 如果已完成，直接跳转到结果页
if ($reading['status'] === 'completed') {
    $_SESSION['pan_data'] = $reading['pan_data'];
    $_SESSION['reading_id'] = $reading['id'];
    $_SESSION['overall_reading'] = $reading['overall_reading'];
    header('Location: result.php');
    exit;
}

$panData = $reading['pan_data'];
$readingList = $readingModel->getUserReadingsList($userId, 10);
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>知运星 - 正在解读中</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="container">
        <header class="header">
            <div class="logo">
                <img src="images/icon.png" alt="知运星" class="app-icon" onerror="this.style.display='none'">
                <span class="logo-icon">🔮</span>
            </div>
            <h1>知运星</h1>
        </header>

        <main class="main processing-page">
            <div class="processing-card">
                <div class="processing-icon" id="processing-icon">⏳</div>
                <h2 id="status-title">正在为您拨通天机</h2>
                <p class="processing-desc" id="status-desc">
                    您的命盘已排好，AI 正在为您生成深度解读。<br>
                    通常需要 1-3 分钟，请耐心等待...
                </p>
                
                <div class="reading-info">
                    <p><strong>👤 姓名：</strong><?= htmlspecialchars($reading['name']) ?></p>
                    <p><strong>📊 状态：</strong><span class="status-badge processing" id="status-badge">解读中</span></p>
                    <p><strong>🕐 提交时间：</strong><?= date('Y-m-d H:i:s', strtotime($reading['created_at'] . ' UTC')) ?></p>
                </div>
                
                <div class="pan-preview">
                    <h3>📋 命盘信息</h3>
                    <p><strong>农历：</strong><?= $panData['lunar_date']['year'] ?>年<?= $panData['lunar_date']['month'] ?>月<?= $panData['lunar_date']['day'] ?>日</p>
                    <p><strong>干支：</strong><?= $panData['lunar_date']['ganzhi_year'] ?>年 <?= $panData['lunar_date']['ganzhi_month'] ?>月 <?= $panData['lunar_date']['ganzhi_day'] ?>日 <?= $panData['lunar_date']['ganzhi_hour'] ?></p>
                    <p><strong>命宫：</strong><?= $panData['pan']['ming_gong']['name'] ?></p>
                    <p><strong>身宫：</strong><?= $panData['pan']['shen_gong']['name'] ?></p>
                    <?php if (!empty($panData['pan']['patterns'])): ?>
                        <p><strong>格局：</strong><?= implode('、', $panData['pan']['patterns']) ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if (count($readingList) > 1): ?>
                    <div class="reading-list">
                        <h3>📜 历史记录</h3>
                        <ul>
                            <?php foreach ($readingList as $r): ?>
                                <li class="<?= $r['id'] == $reading['id'] ? 'current' : '' ?>">
                                    <span class="reading-name"><?= htmlspecialchars($r['name']) ?></span>
                                    <span class="reading-status <?= $r['status'] ?>">
                                        <?= $r['status'] === 'completed' ? '✅ 已完成' : ($r['status'] === 'processing' ? '⏳ 处理中' : '⏸️ 等待中') ?>
                                    </span>
                                    <span class="reading-time"><?= date('m-d H:i', strtotime($r['created_at'] . ' UTC')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <button class="btn-primary btn-check" id="refresh-btn" onclick="checkStatus()">
                    🔄 刷新状态
                </button>
                <div style="text-align: center; margin-top: 16px;">
                    <a href="index.php?new=1" style="color: var(--text-light); text-decoration: none; font-size: 0.95rem;">← 放弃等待，重新算一次</a>
                </div>
            </div>
        </main>

        <footer class="footer">
            <p>© 2026 知运星 · 紫微斗数传承</p>
        </footer>
    </div>

    <script>
        const readingId = <?= $reading['id'] ?>;
        let isGenerating = false;
        
        document.addEventListener('DOMContentLoaded', function() {
            startGeneration();
        });
        
        async function startGeneration() {
            if (isGenerating) return;
            isGenerating = true;
            
            try {
                const response = await fetch('api/status.php?reading_id=' + readingId);
                const data = await response.json();
                
                if (data.success && data.status === 'completed') {
                    updateUI('completed');
                    setTimeout(() => { window.location.href = 'result.php'; }, 800);
                } else if (data.status === 'failed') {
                    updateUI('failed');
                    isGenerating = false;
                    // 生成失败 3 秒后自动跳转回首页，不卡在中间页
                    setTimeout(() => { window.location.href = 'index.php?new=1'; }, 3000);
                } else {
                    isGenerating = false;
                    // 自动轮询：每10秒检查一次状态，降低服务器压力
                    setTimeout(startGeneration, 10000);
                }
            } catch (error) {
                console.error('Error:', error);
                isGenerating = false;
                // 网络错误后稍作等待再次重试
                setTimeout(startGeneration, 15000);
            }
        }
        
        async function checkStatus() {
            const btn = document.getElementById('refresh-btn');
            btn.textContent = '🔄 检查中...';
            btn.disabled = true;
            
            try {
                const response = await fetch('api/status.php?reading_id=' + readingId);
                const data = await response.json();
                
                if (data.success && data.status === 'completed') {
                    updateUI('completed');
                    setTimeout(() => { window.location.href = 'result.php'; }, 800);
                } else {
                    btn.textContent = '🔄 刷新状态';
                    btn.disabled = false;
                }
            } catch (error) {
                btn.textContent = '🔄 刷新状态';
                btn.disabled = false;
            }
        }
        
        function updateUI(status) {
            const icon = document.getElementById('processing-icon');
            const title = document.getElementById('status-title');
            const desc = document.getElementById('status-desc');
            const badge = document.getElementById('status-badge');
            
            if (status === 'completed') {
                icon.textContent = '✅';
                icon.style.animation = 'none';
                title.textContent = '解读已完成！';
                desc.innerHTML = '正在为您跳转到结果页面...';
                badge.className = 'status-badge completed';
                badge.textContent = '已完成';
            } else if (status === 'failed') {
                icon.textContent = '❌';
                icon.style.animation = 'none';
                title.textContent = '生成失败';
                desc.innerHTML = '解读生成失败，请刷新重试或联系客服';
                badge.className = 'status-badge failed';
                badge.textContent = '失败';
            }
        }
        
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) checkStatus();
        });
    </script>
</body>

</html>