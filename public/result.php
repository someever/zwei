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
function markdownToHtml($text)
{
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

// 从数据库同步已购买状态（解决微信支付回调后 session 未更新的问题）
if (isset($_SESSION['user_id'])) {
    $orderModel = new Order();
    $paidOrders = $orderModel->getUserOrders($_SESSION['user_id']);
    $dbPurchasedTypes = [];
    foreach ($paidOrders as $order) {
        if ($order['status'] !== 'paid')
            continue;
        if ($order['type'] === 'bundle') {
            $dbPurchasedTypes = ['career', 'marriage', 'wealth', 'health'];
            break;
        } elseif ($order['type'] === 'monthly') {
            $hasMonthlyCard = true;
            break;
        } elseif ($order['type'] === 'single' && !empty($order['description'])) {
            // description 格式: "紫微斗数单次解读|career"
            $parts = explode('|', $order['description']);
            if (isset($parts[1]) && in_array($parts[1], ['career', 'marriage', 'wealth', 'health'])) {
                $dbPurchasedTypes[] = $parts[1];
            }
        }
    }
    if (!empty($dbPurchasedTypes)) {
        $purchasedTypes = array_unique(array_merge($purchasedTypes, $dbPurchasedTypes));
        $_SESSION['purchased_types'] = $purchasedTypes;
    }
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
                        // 合婚分析需要另一半信息
                        $pYear = $_POST['pYear'] ?? '';
                        $pMonth = $_POST['pMonth'] ?? '';
                        $pDay = $_POST['pDay'] ?? '';
                        $pHour = $_POST['pHour'] ?? 0;
                        $pMinute = $_POST['pMinute'] ?? 0;
                        $pGender = $_POST['pGender'] ?? '';

                        if (!$pYear || !$pMonth || !$pDay || !$pGender) {
                            $result['need_partner_info'] = true;
                            echo json_encode($result);
                            exit;
                        }

                        // 计算另一半命盘
                        $calculator = new PanCalculator();
                        $partnerPan = $calculator->calculate($pYear, $pMonth, $pDay, $pHour, $pMinute, $pGender);
                        $partnerPanText = $calculator->formatForGemini($partnerPan);

                        // 区分主次（Gemini 方法是 generateMarriageReading($malePan, $femalePan)）
                        if ($panData['gender'] === 'male') {
                            $content = $gemini->generateMarriageReading($panText, $partnerPanText);
                        } else {
                            $content = $gemini->generateMarriageReading($partnerPanText, $panText);
                        }
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
                $readingType = $_POST['reading_type'] ?? '';

                // 确定支付金额和类型
                $typeMap = [
                    'single' => ['amount' => PAYMENT_SINGLE_PRICE, 'desc' => '紫微斗数单次解读'],
                    'bundle' => ['amount' => PAYMENT_BUNDLE_PRICE, 'desc' => '紫微斗数四次打包'],
                    'monthly' => ['amount' => PAYMENT_MONTHLY_PRICE, 'desc' => '紫微斗数月卡会员'],
                ];

                if (!isset($typeMap[$package])) {
                    throw new Exception('无效的套餐类型');
                }

                $payInfo = $typeMap[$package];

                // 检查微信支付是否配置
                if (WECHAT_APPID && WECHAT_MCH_ID && WECHAT_API_KEY) {
                    // 创建订单
                    $orderNo = 'ZWEI' . date('YmdHis') . rand(1000, 9999);
                    $orderModel = new Order();
                    $orderId = $orderModel->create([
                        'user_id' => $_SESSION['user_id'] ?? 0,
                        'order_no' => $orderNo,
                        'type' => $package,
                        'amount' => $payInfo['amount'],
                        'description' => $package === 'single' && $readingType
                            ? $payInfo['desc'] . '|' . $readingType
                            : $payInfo['desc'],
                        'status' => 'pending'
                    ]);

                    // 调用微信支付
                    require_once __DIR__ . '/../app/utils/Payment.php';
                    $payment = new Payment();
                    $payResult = $payment->createOrder($orderNo, $payInfo['amount'], $payInfo['desc']);

                    $result['success'] = true;
                    $result['payment'] = true;
                    $result['order_no'] = $orderNo;
                    $result['code_url'] = $payResult['code_url'];
                    $result['amount'] = $payInfo['amount'];
                } else {
                    // 微信支付未配置，演示模式：直接开通
                    if ($package === 'monthly') {
                        if ($user) {
                            $userModel->activateMonthlyCard($user['id'], 30);
                        }
                        $hasMonthlyCard = true;
                        $_SESSION['purchased_types'] = ['career', 'marriage', 'wealth', 'health'];
                    } elseif ($package === 'bundle') {
                        $_SESSION['purchased_types'] = ['career', 'marriage', 'wealth', 'health'];
                    } elseif ($package === 'single') {
                        if ($readingType) {
                            $purchased = $_SESSION['purchased_types'] ?? [];
                            $purchased[] = $readingType;
                            $_SESSION['purchased_types'] = array_unique($purchased);
                        }
                    }

                    $result['success'] = true;
                    $result['payment'] = false;
                    $result['message'] = '购买成功（演示模式）';
                }
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
                        <p><strong>农历：</strong><?= $panData['lunar_date']['year'] ?>年<?= $panData['lunar_date']['month'] ?>月<?= $panData['lunar_date']['day'] ?>日
                        </p>
                        <p><strong>干支：</strong><?= $panData['lunar_date']['ganzhi_year'] ?>年
                            <?= $panData['lunar_date']['ganzhi_month'] ?>月 <?= $panData['lunar_date']['ganzhi_day'] ?>日
                            <?= $panData['lunar_date']['ganzhi_hour'] ?>
                        </p>
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

        <!-- 合婚信息输入弹窗 -->
        <div id="partnerModal" class="modal" style="display: none;">
            <div class="modal-content">
                <h3>💑 请输入另一半的出生信息</h3>
                <form id="partnerForm">
                    <div class="form-group">
                        <label>性别</label>
                        <select name="pGender" required>
                            <option value="male">男</option>
                            <option value="female" selected>女</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>出生日期</label>
                        <input type="date" name="pDate" required>
                    </div>
                    <div class="form-group">
                        <label>出生时间</label>
                        <div class="time-input">
                            <select name="pHour">
                                <?php for ($i = 0; $i < 24; $i++): ?>
                                    <option value="<?= $i ?>"><?= sprintf('%02d', $i) ?>时</option>
                                <?php endfor; ?>
                            </select>
                            <select name="pMinute">
                                <?php for ($i = 0; $i < 60; $i += 5): ?>
                                    <option value="<?= $i ?>"><?= sprintf('%02d', $i) ?>分</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="document.getElementById('partnerModal').style.display='none'">取消</button>
                        <button type="submit" class="btn-submit">开始合婚分析</button>
                    </div>
                </form>
            </div>
        </div>

        <footer class="footer">
            <p>© 2026 知运星 · 紫微斗数传承</p>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // 购买处理函数
            function handlePurchase(package, readingType) {
                const body = 'action=purchase&package=' + package + (readingType ? '&reading_type=' + readingType : '');
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            if (data.payment && data.code_url) {
                                // 微信支付：显示二维码链接
                                const msg = '请使用微信扫描以下二维码支付 ¥' + data.amount + '\n\n' +
                                    '订单号: ' + data.order_no + '\n' +
                                    '二维码链接: ' + data.code_url + '\n\n' +
                                    '支付完成后请刷新页面';
                                alert(msg);
                            } else {
                                // 演示模式
                                alert(data.message || '购买成功！');
                                location.reload();
                            }
                        } else {
                            alert('购买失败: ' + (data.message || '未知错误'));
                        }
                    })
                    .catch(err => {
                        alert('网络错误，请重试');
                    });
            }

            // 购买单次解读
            document.querySelectorAll('.btn-buy').forEach(btn => {
                btn.addEventListener('click', function () {
                    if (this.disabled) return;
                    const option = this.closest('.reading-option');
                    const type = option.dataset.type;
                    handlePurchase('single', type);
                });
            });

            // 打包购买
            document.querySelector('.btn-bundle')?.addEventListener('click', function () {
                handlePurchase('bundle');
            });

            // 月卡购买
            document.querySelector('.btn-monthly')?.addEventListener('click', function () {
                handlePurchase('monthly');
            });

        // 查看解读
        document.querySelectorAll('.btn-read').forEach(btn => {
            btn.addEventListener('click', function () {
                const option = this.closest('.reading-option');
                const type = option.dataset.type;

                if (type === 'marriage') {
                    document.getElementById('partnerModal').style.display = 'flex';
                    return;
                }

                loadReading(type);
            });
        });

        // 提交合婚信息
        document.getElementById('partnerForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            const date = formData.get('pDate').split('-');
            
            const params = new URLSearchParams();
            params.append('action', 'get_reading');
            params.append('type', 'marriage');
            params.append('pYear', date[0]);
            params.append('pMonth', date[1]);
            params.append('pDay', date[2]);
            params.append('pHour', formData.get('pHour'));
            params.append('pMinute', formData.get('pMinute'));
            params.append('pGender', formData.get('pGender'));

            document.getElementById('partnerModal').style.display = 'none';
            loadReading('marriage', params);
        });

        function loadReading(type, extraParams = null) {
            const resultSection = document.getElementById('readingResult');
            const resultContent = resultSection.querySelector('.reading-result-content');
            
            resultSection.style.display = 'block';
            resultContent.innerHTML = '<p>🔮 正在为您拨通天机，请稍候...</p>';
            
            let body = 'action=get_reading&type=' + type;
            if (extraParams) {
                body = extraParams.toString();
            }

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        resultContent.innerHTML = data.content.replace(/\n/g, '<br>');
                        resultSection.scrollIntoView({ behavior: 'smooth' });
                    } else if (data.need_purchase) {
                        alert('请先购买此项服务');
                        resultSection.style.display = 'none';
                    } else {
                        resultContent.innerHTML = '<p class="error">' + data.message + '</p>';
                    }
                })
                .catch(err => {
                    resultContent.innerHTML = '<p class="error">网络请求失败，请重试</p>';
                });
        }
        });
    </script>
</body>

</html>