<?php
/**
 * 结果页面
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/utils/Database.php';
require_once __DIR__ . '/../app/utils/PanCalculator.php';
require_once __DIR__ . '/../app/utils/GeminiClient.php';
require_once __DIR__ . '/../app/models/User.php';
require_once __DIR__ . '/../app/models/Reading.php';
require_once __DIR__ . '/../app/models/Order.php';

session_start();

if (!isset($_SESSION['pan_data']) || !isset($_SESSION['reading_id'])) {
    header('Location: /');
    exit;
}

$panData = $_SESSION['pan_data'];
$readingId = $_SESSION['reading_id'];
$overallReading = $_SESSION['overall_reading'] ?? '';
$purchasedTypes = $_SESSION['purchased_types'] ?? [];

// Markdown转HTML
function markdownToHtml($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // 标题
    $text = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $text);
    // 加粗
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // 列表
    $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/^(\d+)\. (.+)$/m', '<li>$2</li>', $text);
    // 换行
    $text = preg_replace('/\n\n/', '</p><p>', $text);
    $text = preg_replace('/\n/', '<br>', $text);
    // 包装li
    $text = preg_replace('/(<li>.*?<\/li>)/s', '<ul>$1</ul>', $text);
    return '<p>' . $text . '</p>';
}

$overallReading = markdownToHtml($overallReading);

// 检查用户
$user = null;
$hasMonthlyCard = false;
if (isset($_SESSION['user_id'])) {
    $userModel = new User();
    $user = $userModel->getById($_SESSION['user_id']);
    $hasMonthlyCard = $userModel->hasMonthlyCard($user);
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $result = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['action']) {
            case 'get_reading':
                $type = $_POST['type'] ?? '';
                $validTypes = ['career', 'marriage', 'wealth', 'health'];
                
                if (!in_array($type, $validTypes)) {
                    throw new Exception('无效的解读类型');
                }
                
                // 检查是否已购买
                if (!in_array($type, $purchasedTypes) && !$hasMonthlyCard) {
                    $result['message'] = '请先购买此服务';
                    $result['need_purchase'] = true;
                    $result['price'] = 10;
                    echo json_encode($result);
                    exit;
                }
                
                // 获取命盘数据
                $readingModel = new Reading();
                $reading = $readingModel->getById($readingId);
                $pan = $reading['pan_data'];
                $panText = (new PanCalculator())->formatForGemini($pan);
                $gemini = new GeminiClient();
                
                switch ($type) {
                    case 'career':
                        $content = $gemini->generateCareerReading($panText);
                        break;
                    case 'marriage':
                        $content = "合婚分析需要提供另一半的出生信息，请在首页重新输入进行合婚分析。";
                        break;
                    case 'wealth':
                        $content = $gemini->generateWealthReading($panText);
                        break;
                    case 'health':
                        $content = $gemini->generateHealthReading($panText);
                        break;
                    default:
                        throw new Exception('无效的解读类型');
                }
                
                $readingModel->updateReading($readingId, $type, $content);
                
                $result['success'] = true;
                $result['content'] = markdownToHtml($content);
                break;
                
            case 'purchase':
                $package = $_POST['package'] ?? '';
                
                // 演示模式：直接开通
                if ($package === 'monthly') {
                    if ($user) {
                        $userModel->activateMonthlyCard($user['id'], 30);
                    }
                    $hasMonthlyCard = true;
                    $_SESSION['purchased_types'] = ['career', 'marriage', 'wealth', 'health'];
                } elseif ($package === 'bundle') {
                    if ($user) {
                        $userModel->addBalance($user['id'], 30);
                    }
                    $_SESSION['purchased_types'] = ['career', 'marriage', 'wealth', 'health'];
                } elseif ($package === 'single') {
                    if ($user) {
                        $userModel->addBalance($user['id'], 10);
                    }
                    // 单次稍后选择
                }
                
                $result['success'] = true;
                $result['message'] = '购买成功';
                break;
        }
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>命盘解读 - 知运星</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="logo">
                <img src="/images/icon.png" alt="知运星" class="app-icon" onerror="this.style.display='none'">
                <span class="logo-icon">🔮</span>
            </div>
            <h1>知运星</h1>
            <a href="/" class="back-link">← 重新算命</a>
        </header>

        <main class="main">
            <section class="section">
                <h2>📊 命盘</h2>
                <div class="pan-display">
                    <div class="pan-info">
                        <p><strong>农历：</strong><?= $panData['lunar_date']['year'] ?>年<?= $panData['lunar_date']['month'] ?>月<?= $panData['lunar_date']['day'] ?>日</p>
                        <p><strong>干支：</strong><?= $panData['lunar_date']['ganzhi_year'] ?>年 <?= $panData['lunar_date']['ganzhi_month'] ?>月 <?= $panData['lunar_date']['ganzhi_day'] ?>日 <?= $panData['lunar_date']['ganzhi_hour'] ?></p>
                        <p><strong>时辰：</strong><?= $panData['shichen'] ?></p>
                        <p><strong>命宫：</strong><?= $panData['pan']['ming_gong']['name'] ?></p>
                        <p><strong>身宫：</strong><?= $panData['pan']['shen_gong']['name'] ?></p>
                        <?php if (!empty($panData['pan']['patterns'])): ?>
                        <p><strong>格局：</strong><?= implode('、', $panData['pan']['patterns']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="palaces-grid">
                        <?php foreach ($panData['pan']['palaces'] as $name => $palace): ?>
                        <div class="palace-card <?= isset($palace['is_ming_gong']) ? 'ming-gong' : '' ?>">
                            <div class="palace-name"><?= $name ?></div>
                            <div class="palace-ganzhi"><?= $palace['gan'] ?><?= $palace['zhi'] ?></div>
                            <div class="palace-stars"><?= implode('、', $palace['stars']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="section">
                <h2>📖 命盘整体解读</h2>
                <div class="reading-content">
                    <?= $overallReading ?>
                </div>
            </section>

            <section class="section">
                <h2>💎 深入解读</h2>
                <p class="section-desc">选择以下项目获取详细解读</p>
                
                <div class="reading-options">
                    <div class="reading-option" data-type="career">
                        <div class="option-icon">💼</div>
                        <div class="option-title">事业分析</div>
                        <div class="option-desc">事业发展方向、适合职业、事业高峰期</div>
                        <div class="option-price">¥10</div>
                        <button class="btn-buy" <?= in_array('career', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('career', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('career', $purchasedTypes) || $hasMonthlyCard): ?>
                        <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>

                    <div class="reading-option" data-type="marriage">
                        <div class="option-icon">💑</div>
                        <div class="option-title">合婚分析</div>
                        <div class="option-desc">婚姻缘分、相处模式、子女缘分</div>
                        <div class="option-price">¥10</div>
                        <button class="btn-buy" <?= in_array('marriage', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('marriage', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('marriage', $purchasedTypes) || $hasMonthlyCard): ?>
                        <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>

                    <div class="reading-option" data-type="wealth">
                        <div class="option-icon">💰</div>
                        <div class="option-title">财运分析</div>
                        <div class="option-desc">财运格局、理财建议、赚钱方向</div>
                        <div class="option-price">¥10</div>
                        <button class="btn-buy" <?= in_array('wealth', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('wealth', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('wealth', $purchasedTypes) || $hasMonthlyCard): ?>
                        <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>

                    <div class="reading-option" data-type="health">
                        <div class="option-icon">🏥</div>
                        <div class="option-title">健康分析</div>
                        <div class="option-desc">先天体质、易患疾病、养生建议</div>
                        <div class="option-price">¥10</div>
                        <button class="btn-buy" <?= in_array('health', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('health', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('health', $purchasedTypes) || $hasMonthlyCard): ?>
                        <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bulk-purchase">
                    <button class="btn-bundle" data-package="bundle">
                        打包购买全部四项 ¥30
                    </button>
                    <button class="btn-monthly" data-package="monthly">
                        开通月卡会员 ¥666（无限次）
                    </button>
                </div>
            </section>

            <section class="section" id="readingResult" style="display: none;">
                <h2>📝 详细解读</h2>
                <div class="reading-result-content"></div>
            </section>
        </main>

        <footer class="footer">
            <p>© 2026 知运星 · 紫微斗数传承</p>
        </footer>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 购买单次解读
        document.querySelectorAll('.btn-buy').forEach(btn => {
            btn.addEventListener('click', function() {
                alert('支付功能开发中，请先开通月卡会员体验全部功能');
            });
        });

        // 打包购买
        document.querySelector('.btn-bundle')?.addEventListener('click', function() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=purchase&package=bundle'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('购买成功！');
                    location.reload();
                }
            });
        });

        // 月卡购买
        document.querySelector('.btn-monthly')?.addEventListener('click', function() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=purchase&package=monthly'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('月卡开通成功！');
                    location.reload();
                }
            });
        });

        // 查看解读
        document.querySelectorAll('.btn-read').forEach(btn => {
            btn.addEventListener('click', function() {
                const option = this.closest('.reading-option');
                const type = option.dataset.type;
                
                const resultSection = document.getElementById('readingResult');
                const resultContent = resultSection.querySelector('.reading-result-content');
                
                resultSection.style.display = 'block';
                resultContent.innerHTML = '<p>加载中...</p>';
                
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=get_reading&type=' + type
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        resultContent.innerHTML = data.content.replace(/\n/g, '<br>');
                        resultSection.scrollIntoView({behavior: 'smooth'});
                    } else {
                        resultContent.innerHTML = '<p class="error">' + data.message + '</p>';
                    }
                });
            });
        });
    });
    </script>
</body>
</html>
