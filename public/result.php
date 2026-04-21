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
    header('Location: index.php');
    exit;
}

$panData = $_SESSION['pan_data'];
$readingId = $_SESSION['reading_id'];
$overallReading = $_SESSION['overall_reading'] ?? '';
$purchasedTypes = $_SESSION['purchased_types'] ?? [];

// Markdown转HTML（逐行解析器）
function markdownToHtml($text)
{
    $lines = explode("\n", $text);
    $html = '';
    $inList = false;      // 当前是否在列表中
    $listType = '';       // ul 或 ol
    $inParagraph = false; // 当前是否在段落中
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // 空行：关闭所有块
        if ($trimmed === '') {
            if ($inList) { $html .= "</{$listType}>"; $inList = false; }
            if ($inParagraph) { $html .= '</p>'; $inParagraph = false; }
            continue;
        }
        
        // 水平线
        if (preg_match('/^---+$/', $trimmed) || preg_match('/^\*\*\*+$/', $trimmed)) {
            if ($inList) { $html .= "</{$listType}>"; $inList = false; }
            if ($inParagraph) { $html .= '</p>'; $inParagraph = false; }
            $html .= '<hr>';
            continue;
        }
        
        // 标题
        if (preg_match('/^(#{1,5})\s+(.+)$/', $trimmed, $m)) {
            if ($inList) { $html .= "</{$listType}>"; $inList = false; }
            if ($inParagraph) { $html .= '</p>'; $inParagraph = false; }
            $level = strlen($m[1]) + 1; // # → h2, ## → h3, ### → h4 ...
            if ($level > 6) $level = 6;
            $content = applyInline($m[2]);
            $html .= "<h{$level}>{$content}</h{$level}>";
            continue;
        }
        
        // 无序列表
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            if ($inParagraph) { $html .= '</p>'; $inParagraph = false; }
            if (!$inList || $listType !== 'ul') {
                if ($inList) $html .= "</{$listType}>";
                $html .= '<ul>';
                $inList = true;
                $listType = 'ul';
            }
            $html .= '<li>' . applyInline($m[1]) . '</li>';
            continue;
        }
        
        // 有序列表
        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            if ($inParagraph) { $html .= '</p>'; $inParagraph = false; }
            if (!$inList || $listType !== 'ol') {
                if ($inList) $html .= "</{$listType}>";
                $html .= '<ol>';
                $inList = true;
                $listType = 'ol';
            }
            $html .= '<li>' . applyInline($m[1]) . '</li>';
            continue;
        }
        
        // 普通文本行（段落）
        if ($inList) { $html .= "</{$listType}>"; $inList = false; }
        if (!$inParagraph) {
            $html .= '<p>';
            $inParagraph = true;
        } else {
            $html .= '<br>';
        }
        $html .= applyInline($trimmed);
    }
    
    // 关闭未闭合的块
    if ($inList) $html .= "</{$listType}>";
    if ($inParagraph) $html .= '</p>';
    
    return $html;
}

// 行内格式处理
function applyInline($text)
{
    // 始终转义 HTML，防止 XSS
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // 加粗
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // 斜体
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    return $text;
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

// ─── 处理支付宝同步回跳 ───────────────────────────────────────────────────────
// 支付宝跳回时会在 URL 带上 out_trade_no / trade_status / sign 等参数
// 在 notify_url 到达前先用 return 参数直接确认支付，避免页面还显示「未购买」
if (
    isset($_GET['out_trade_no'], $_GET['trade_status']) &&
    $_GET['trade_status'] === 'TRADE_SUCCESS'
) {
    require_once __DIR__ . '/../app/utils/Alipay.php';
    $alipay = new Alipay();
    $returnParams = $_GET;
    if ($alipay->verifyNotify($returnParams)) {
        $orderNo = $returnParams['out_trade_no'];
        $tradeNo = $returnParams['trade_no'] ?? '';
        $orderModel = new Order();
        $order = $orderModel->getByOrderNo($orderNo);
        // 只处理 pending 状态，已 paid 的幂等跳过
        if ($order && $order['status'] === 'pending') {
            try {
                $orderModel->processPayment($orderNo, $tradeNo);
                error_log("Alipay return_url processed order {$orderNo}");
            } catch (Exception $e) {
                error_log("Alipay return_url processPayment error: " . $e->getMessage());
            }
        }
    } else {
        error_log("Alipay return_url sign verify failed: " . json_encode($_GET));
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// 从数据库同步已购买状态（解决支付宝/微信支付回调后 session 未更新的问题）
$orderModel = $orderModel ?? new Order();
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    $paidOrders = $orderModel->getUserOrders($_SESSION['user_id']);
    $dbPurchasedTypes = [];
    foreach ($paidOrders as $order) {
        if ($order['status'] !== 'paid')
            continue;
        if ($order['type'] === 'overview') {
            $dbPurchasedTypes[] = 'overview';
        } elseif ($order['type'] === 'bundle') {
            // 历史订单兼容：bundle 解锁全部深入解读
            $dbPurchasedTypes = array_unique(array_merge($dbPurchasedTypes, ['career_wealth', 'marriage', 'romance', 'health', 'career', 'wealth']));
        } elseif ($order['type'] === 'monthly') {
            $hasMonthlyCard = true;
        } elseif ($order['type'] === 'single' && !empty($order['description'])) {
            // description 格式: "紫微斗数单次解读|career"
            $parts = explode('|', $order['description']);
            if (isset($parts[1]) && in_array($parts[1], ['career_wealth', 'marriage', 'romance', 'health', 'career', 'wealth'])) {
                $dbPurchasedTypes[] = $parts[1];
            }
        }
    }
    if (!empty($dbPurchasedTypes)) {
        $purchasedTypes = array_unique(array_merge($purchasedTypes, $dbPurchasedTypes));
        $_SESSION['purchased_types'] = $purchasedTypes;
    }
} elseif (isset($_GET['out_trade_no'])) {
    // 匿名用户：通过回跳的 order_no 直接查订单并识别购买类型
    $orderModel = new Order();
    $order = $orderModel->getByOrderNo($_GET['out_trade_no']);
    if ($order && $order['status'] === 'paid') {
        if ($order['type'] === 'overview') {
            $purchasedTypes[] = 'overview';
            $purchasedTypes = array_unique($purchasedTypes);
        } elseif ($order['type'] === 'bundle') {
            $purchasedTypes = array_unique(array_merge($purchasedTypes, ['career_wealth', 'marriage', 'romance', 'health', 'career', 'wealth']));
        } elseif ($order['type'] === 'monthly') {
            $hasMonthlyCard = true;
        } elseif ($order['type'] === 'single' && !empty($order['description'])) {
            $parts = explode('|', $order['description']);
            if (isset($parts[1]) && in_array($parts[1], ['career_wealth', 'marriage', 'romance', 'health', 'career', 'wealth'])) {
                $purchasedTypes[] = $parts[1];
                $purchasedTypes = array_unique($purchasedTypes);
            }
        }
        $_SESSION['purchased_types'] = $purchasedTypes;
    }
}

$hasOverviewAccess = in_array('overview', $purchasedTypes);

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $result = ['success' => false, 'message' => ''];

    try {
        switch ($_POST['action']) {
            case 'get_reading':
                $type = $_POST['type'] ?? '';
                $validTypes = ['career_wealth', 'marriage', 'romance', 'health'];

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

                $readingModel = new Reading();
                $reading = $readingModel->getById($readingId);
                
                // 如果数据库已经有结果了，直接返回
                $field = $type . '_reading';
                if (!empty($reading[$field])) {
                    $result['success'] = true;
                    $result['status'] = 'completed';
                    $result['content'] = markdownToHtml($reading[$field]);
                    echo json_encode($result);
                    exit;
                }

                // 如果没有结果，启动后台进程（如果没在跑的话）
                // 为了简单起见，我们直接尝试启动进程，由 worker 内部判断是否需要重复执行
                if ($type === 'marriage') {
                    // 合婚逻辑较复杂，暂时保留同步或以后再优化
                    $pYear = $_POST['pYear'] ?? '';
                    $pMonth = $_POST['pMonth'] ?? '';
                    $pDay = $_POST['pDay'] ?? '';
                    $pHour = $_POST['pHour'] ?? 0;
                    $pMinute = $_POST['pMinute'] ?? 0;
                    $pGender = $_POST['pGender'] ?? '';
                    $pProvince = $_POST['pProvince'] ?? '';
                    $pCity = $_POST['pCity'] ?? '';

                    if (!$pYear || !$pMonth || !$pDay || !$pGender) {
                        $result['need_partner_info'] = true;
                        echo json_encode($result);
                        exit;
                    }

                    $calculator = new PanCalculator();
                    $partnerPan = $calculator->calculate($pYear, $pMonth, $pDay, $pHour, $pMinute, $pGender, [
                        'province' => $pProvince,
                        'city' => $pCity
                    ]);
                    $panText = $calculator->formatForGemini($reading['pan_data']);
                    $partnerPanText = $calculator->formatForGemini($partnerPan);
                    $gemini = new GeminiClient();

                    if ($panData['gender'] === 'male') {
                        $content = $gemini->generateMarriageReading($panText, $partnerPanText);
                    } else {
                        $content = $gemini->generateMarriageReading($partnerPanText, $panText);
                    }
                    $readingModel->updateReading($readingId, $type, $content);
                    $result['success'] = true;
                    $result['status'] = 'completed';
                    $result['content'] = markdownToHtml($content);
                } else {
                    // 事业、财运、健康走后台异步
                    $phpBin = (strpos(PHP_BINARY, 'fpm') !== false || strpos(PHP_SAPI, 'fpm') !== false) ? 'php' : PHP_BINARY;
                    $workerScript = __DIR__ . '/../scripts/generate_worker.php';
                    $cmd = sprintf('%s %s %d %s &', escapeshellarg($phpBin), escapeshellarg($workerScript), $readingId, escapeshellarg($type));
                    exec($cmd);

                    $result['success'] = true;
                    $result['status'] = 'processing';
                    $result['message'] = '正在为您拨通天机...';
                }
                break;

            case 'purchase':
                $package = $_POST['package'] ?? '';
                $readingType = $_POST['reading_type'] ?? '';
                $payMethod = $_POST['pay_method'] ?? 'wechat';

                // 确定支付金额和类型
                $typeMap = [
                    'overview' => ['amount' => PAYMENT_OVERVIEW_PRICE, 'desc' => '命盘整体解读'],
                    'single'   => ['amount' => PAYMENT_SINGLE_PRICE,   'desc' => '紫微斗数单次深入解读'],
                ];

                if (!isset($typeMap[$package])) {
                    throw new Exception('无效的套餐类型');
                }

                $payInfo = $typeMap[$package];
                $isMobile = isMobile();

                // 支付绕过模式（生产自测用）
                if (PAYMENT_BYPASS) {
                    if ($package === 'overview') {
                        $purchased = $_SESSION['purchased_types'] ?? [];
                        $purchased[] = 'overview';
                        $_SESSION['purchased_types'] = array_unique($purchased);
                    } elseif ($package === 'single' && $readingType) {
                        $purchased = $_SESSION['purchased_types'] ?? [];
                        $purchased[] = $readingType;
                        $_SESSION['purchased_types'] = array_unique($purchased);
                    }

                    $result['success'] = true;
                    $result['payment'] = false;
                    $result['message'] = '购买成功（测试模式）';
                    break;
                }

                // 微信支付
                if ($payMethod === 'wechat' && WECHAT_APPID && WECHAT_MCH_ID) {
                    require_once __DIR__ . '/../app/utils/Payment.php';
                    $orderNo = 'WX' . date('YmdHis') . random_int(100000, 999999);

                    // 记录订单
                    $orderModel = new Order();
                    $orderModel->create([
                        'user_id' => $_SESSION['user_id'] ?? 0,
                        'order_no' => $orderNo,
                        'type' => $package,
                        'amount' => $payInfo['amount'],
                        'description' => $package === 'single' && $readingType ? $payInfo['desc'] . '|' . $readingType : $payInfo['desc'],
                        'status' => 'pending'
                    ]);

                    $payment = new Payment();
                    // 逻辑：
                    // 1. 如果在微信内置浏览器 -> JSAPI
                    // 2. 如果是普通手机浏览器 -> MWEB (H5支付)
                    // 3. 其他 -> NATIVE (扫码支付)
                    $inWechat = isWechat();
                    if ($inWechat) {
                        $tradeType = 'JSAPI';
                    } elseif ($isMobile) {
                        $tradeType = 'MWEB';
                    } else {
                        $tradeType = 'NATIVE';
                    }
                    
                    $payResult = $payment->createOrder($orderNo, $payInfo['amount'], $payInfo['desc'], $tradeType);

                    $result['success'] = true;
                    $result['payment'] = true;
                    $result['pay_method'] = 'wechat';
                    $result['trade_type'] = $tradeType;
                    $result['pay_url'] = $payResult['pay_url'] ?? '';
                    $result['order_no'] = $orderNo;
                    
                    // 如果是 JSAPI，还需要返回给前端调起支付的参数
                    if ($tradeType === 'JSAPI' && isset($payResult['prepay_id'])) {
                        require_once __DIR__ . '/../app/utils/Wechat.php';
                        $wechat = new Wechat();
                        $result['jsapi_params'] = $wechat->getJsApiParameters($payResult['prepay_id']);
                    }
                }
                // 支付宝支付
                elseif ($payMethod === 'alipay' && ALIPAY_APPID && ALIPAY_PRIVATE_KEY) {
                    require_once __DIR__ . '/../app/utils/Alipay.php';
                    $orderNo = 'ALI' . date('YmdHis') . random_int(100000, 999999);

                    // 记录订单
                    $orderModel = new Order();
                    $orderModel->create([
                        'user_id' => $_SESSION['user_id'] ?? 0,
                        'order_no' => $orderNo,
                        'type' => $package,
                        'amount' => $payInfo['amount'],
                        'description' => $package === 'single' && $readingType ? $payInfo['desc'] . '|' . $readingType : $payInfo['desc'],
                        'status' => 'pending'
                    ]);

                    $alipay = new Alipay();
                    $tradeType = $isMobile ? 'WAP' : 'PAGE';
                    $payResult = $alipay->createOrder($orderNo, $payInfo['amount'], $payInfo['desc'], $tradeType);

                    $result['success'] = true;
                    $result['payment'] = true;
                    $result['pay_method'] = 'alipay';
                    $result['pay_url'] = $payResult['pay_url'];
                    $result['order_no'] = $orderNo;
                } else {
                    // 演示模式
                    if ($package === 'overview') {
                        $purchased = $_SESSION['purchased_types'] ?? [];
                        $purchased[] = 'overview';
                        $_SESSION['purchased_types'] = array_unique($purchased);
                    } elseif ($package === 'single' && $readingType) {
                        $purchased = $_SESSION['purchased_types'] ?? [];
                        $purchased[] = $readingType;
                        $_SESSION['purchased_types'] = array_unique($purchased);
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

// 辅助函数：检测是否为移动端
function isMobile()
{
    return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"] ?? '');
}

// 辅助函数：检测是否为微信浏览器
function isWechat()
{
    return strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'MicroMessenger') !== false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh.js"></script>
    <div class="container">
        <header class="header">
            <div class="logo">
                <img src="images/icon.png" alt="知运星" class="app-icon" onerror="this.style.display='none'">
                <span class="logo-icon">🔮</span>
            </div>
            <h1>知运星</h1>
            <a href="index.php" class="back-link">← 返回首页</a>
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
                <?php if ($hasOverviewAccess): ?>
                    <div class="reading-content">
                        <?= $overallReading ?>
                    </div>
                <?php else: ?>
                    <div class="overview-paywall">
                        <p class="paywall-hint">🔮 解锁命格解析，了解您的命盘格局与人生走向</p>
                        <button class="btn-buy-overview" id="btnBuyOverview">
                            ¥<?= PAYMENT_OVERVIEW_PRICE ?> 解锁命格解析
                        </button>
                    </div>
                <?php endif; ?>
            </section>

            <section class="section">
                <h2>💎 深入解读</h2>
                <p class="section-desc">选择以下项目获取详细解读</p>

                <div class="reading-options">
                    <div class="reading-option" data-type="career_wealth">
                        <div class="option-icon">💼</div>
                        <div class="option-title">事业财运分析</div>
                        <div class="option-desc">事业发展、正偏财运、财富密码、职场避坑</div>
                        <div class="option-price">¥<?= PAYMENT_SINGLE_PRICE ?></div>
                        <button class="btn-buy" <?= in_array('career_wealth', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('career_wealth', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('career_wealth', $purchasedTypes) || $hasMonthlyCard): ?>
                            <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>

                    <div class="reading-option" data-type="romance">
                        <div class="option-icon">🌸</div>
                        <div class="option-title">桃花运势分析</div>
                        <div class="option-desc">正缘特征、感情避坑、遇见时机、烂桃花预警</div>
                        <div class="option-price">¥<?= PAYMENT_SINGLE_PRICE ?></div>
                        <button class="btn-buy" <?= in_array('romance', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('romance', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('romance', $purchasedTypes) || $hasMonthlyCard): ?>
                            <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>

                    <div class="reading-option" data-type="marriage">
                        <div class="option-icon">💑</div>
                        <div class="option-title">合婚分析</div>
                        <div class="option-desc">婚姻缘分、相处模式、子女缘分</div>
                        <div class="option-price">¥<?= PAYMENT_SINGLE_PRICE ?></div>
                        <button class="btn-buy" <?= in_array('marriage', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('marriage', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('marriage', $purchasedTypes) || $hasMonthlyCard): ?>
                            <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>

                    <div class="reading-option" data-type="health">
                        <div class="option-icon">🏥</div>
                        <div class="option-title">健康分析</div>
                        <div class="option-desc">先天体质、易患疾病、养生建议</div>
                        <div class="option-price">¥<?= PAYMENT_SINGLE_PRICE ?></div>
                        <button class="btn-buy" <?= in_array('health', $purchasedTypes) || $hasMonthlyCard ? 'disabled' : '' ?>>
                            <?= in_array('health', $purchasedTypes) ? '已购买' : ($hasMonthlyCard ? '免费解锁' : '购买') ?>
                        </button>
                        <?php if (in_array('health', $purchasedTypes) || $hasMonthlyCard): ?>
                            <button class="btn-read">查看解读</button>
                        <?php endif; ?>
                    </div>
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
                        <div class="time-input" style="display: flex; gap: 10px;">
                            <select name="pHour" style="flex: 1;">
                                <?php for ($i = 0; $i < 24; $i++): ?>
                                    <option value="<?= $i ?>"><?= sprintf('%02d', $i) ?>时</option>
                                <?php endfor; ?>
                            </select>
                            <select name="pMinute" style="flex: 1;">
                                <?php for ($i = 0; $i < 60; $i += 5): ?>
                                    <option value="<?= $i ?>"><?= sprintf('%02d', $i) ?>分</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group row">
                        <div class="col">
                            <label>出生省份</label>
                            <select name="pProvince" id="pProvinceSelect" required>
                                <option value="">请选择省份</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>出生城市</label>
                            <select name="pCity" id="pCitySelect" required>
                                <option value="">请选择城市</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-cancel"
                            onclick="document.getElementById('partnerModal').style.display='none'">取消</button>
                        <button type="submit" class="btn-submit">开始合婚分析</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 支付选择弹窗 -->
        <div id="payModal" class="modal" style="display: none;">
            <div class="modal-content">
                <h3>💰 请选择支付方式</h3>
                <div class="pay-methods" style="display: flex; flex-direction: column; gap: 12px; margin: 20px 0;">
                    <button class="btn-pay-method wechat" data-method="wechat"
                        style="padding: 15px; border: 2px solid #07c160; border-radius: 12px; background: #f0fdf4; color: #07c160; font-size: 1.1rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px;">
                        微信支付
                    </button>
                    <button class="btn-pay-method alipay" data-method="alipay"
                        style="padding: 15px; border: 2px solid #1677ff; border-radius: 12px; background: #f0f7ff; color: #1677ff; font-size: 1.1rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px;">
                        支付宝
                    </button>
                </div>
                <div class="form-actions" style="margin-top: 20px; text-align: center;">
                    <a href="javascript:;" onclick="document.getElementById('payModal').style.display='none'"
                        style="color: #666; text-decoration: none;">暂不购买</a>
                </div>
            </div>
        </div>

        <footer class="footer">
            <p>© 2026 知运星 · 紫微斗数传承</p>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', async function () {
            // 加载省市数据
            async function initProvinceSelectors(provinceElId, cityElId) {
                const provinceSelect = document.getElementById(provinceElId);
                const citySelect = document.getElementById(cityElId);
                if (!provinceSelect || !citySelect) return;

                try {
                    const response = await fetch('js/data/province.json');
                    if (!response.ok) throw new Error('Failed to load province data');
                    const data = await response.json();

                    // 填充省份
                    Object.keys(data).forEach(province => {
                        const opt = document.createElement('option');
                        opt.value = province;
                        opt.textContent = province;
                        provinceSelect.appendChild(opt);
                    });

                    // 省份变更监听
                    provinceSelect.addEventListener('change', function () {
                        const province = this.value;
                        citySelect.innerHTML = '<option value="">请选择城市</option>';
                        if (province && data[province]) {
                            data[province].forEach(city => {
                                const opt = document.createElement('option');
                                opt.value = city;
                                opt.textContent = city;
                                citySelect.appendChild(opt);
                            });
                        }
                    });
                } catch (error) {
                    console.error('Province data loading error:', error);
                }
            }

            // 初始化合婚弹窗省市选择器
            await initProvinceSelectors('pProvinceSelect', 'pCitySelect');
            // 初始化日期选择器
            flatpickr("input[name='pDate']", {
                locale: "zh",
                dateFormat: "Y-m-d",
                maxDate: "today",
                disableMobile: true
            });

            let currentPayPackage = '';
            let currentPayType = '';

            // 购买处理函数
            function handlePurchase(package, readingType) {
                currentPayPackage = package;
                currentPayType = readingType;
                document.getElementById('payModal').style.display = 'flex';
            }

            // 执行支付
            document.querySelectorAll('.btn-pay-method').forEach(btn => {
                btn.addEventListener('click', function () {
                    const payMethod = this.dataset.method;
                    const body = 'action=purchase&package=' + currentPayPackage +
                        (currentPayType ? '&reading_type=' + currentPayType : '') +
                        '&pay_method=' + payMethod;

                    this.textContent = '正在发起...';
                    this.disabled = true;

                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body
                    })
                        .then(res => res.json())
                        .then(data => {
                            this.textContent = payMethod === 'wechat' ? '微信支付' : '支付宝';
                            this.disabled = false;
                            document.getElementById('payModal').style.display = 'none';

                            if (data.success) {
                                if (data.payment) {
                                    if (data.trade_type === 'JSAPI' && data.jsapi_params) {
                                        // 微信内置浏览器 JSAPI 支付
                                        callWechatPay(data.jsapi_params);
                                    } else if (data.pay_url) {
                                        // 直接跳转到支付链接（无论是支付宝WAP还是微信H5）
                                        window.location.href = data.pay_url;
                                    } else if (data.code_url) {
                                        alert('请扫码支付：' + data.code_url);
                                    }
                                } else {
                                    alert(data.message || '购买成功！');
                                    location.reload();
                                }
                            } else {
                                alert('购买失败: ' + (data.message || '未知错误'));
                            }
                        })
                        .catch(err => {
                            this.disabled = false;
                            alert('网络请求失败，请尝试刷新页面');
                        });
                });
            });

            // 调起微信支付
            function callWechatPay(params) {
                if (typeof WeixinJSBridge == "undefined") {
                    if (document.addEventListener) {
                        document.addEventListener('WeixinJSBridgeReady', onBridgeReady(params), false);
                    } else if (document.attachEvent) {
                        document.attachEvent('WeixinJSBridgeReady', onBridgeReady(params));
                        document.attachEvent('onWeixinJSBridgeReady', onBridgeReady(params));
                    }
                } else {
                    onBridgeReady(params);
                }
            }

            function onBridgeReady(params) {
                WeixinJSBridge.invoke(
                    'getBrandWCPayRequest', {
                        "appId": params.appId,     //公众号名称，由商户传入     
                        "timeStamp": params.timeStamp, //时间戳，自1970年以来的秒数     
                        "nonceStr": params.nonceStr, //随机串     
                        "package": params.package,
                        "signType": params.signType, //微信签名方式：     
                        "paySign": params.paySign //微信签名 
                    },
                    function (res) {
                        if (res.err_msg == "get_brand_wcpay_request:ok") {
                            // 使用以上方式判断前端返回,微信团队郑重提示：
                            //res.err_msg将在用户支付成功后返回ok，但并不保证它绝对可靠。
                            alert('支付成功！');
                            location.reload();
                        } else if (res.err_msg == "get_brand_wcpay_request:cancel") {
                            alert('支付已取消');
                        } else {
                            alert('支付失败: ' + res.err_msg);
                        }
                    }
                );
            }


            // 解锁命格解析（整体解读）
            document.getElementById('btnBuyOverview')?.addEventListener('click', function () {
                handlePurchase('overview', '');
            });

            // 购买单次深入解读
            document.querySelectorAll('.btn-buy').forEach(btn => {
                btn.addEventListener('click', function () {
                    if (this.disabled) return;
                    const option = this.closest('.reading-option');
                    const type = option.dataset.type;
                    handlePurchase('single', type);
                });
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
                params.append('pProvince', formData.get('pProvince'));
                params.append('pCity', formData.get('pCity'));

                document.getElementById('partnerModal').style.display = 'none';
                loadReading('marriage', params);
            });

            function loadReading(type, extraParams = null) {
                const resultSection = document.getElementById('readingResult');
                const resultContent = resultSection.querySelector('.reading-result-content');

                resultSection.style.display = 'block';
                if (!extraParams) {
                    resultContent.innerHTML = '<p>🔮 正在为您拨通天机，请稍候...</p>';
                }

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
                            if (data.status === 'processing') {
                                // 进入轮询模式：每 5 秒检查一次
                                setTimeout(() => loadReading(type, null), 5000);
                            } else {
                                resultContent.innerHTML = data.content;
                                resultSection.scrollIntoView({ behavior: 'smooth' });
                            }
                        } else if (data.need_purchase) {
                            alert('请先购买此项服务');
                            resultSection.style.display = 'none';
                        } else if (data.need_partner_info) {
                            document.getElementById('partnerModal').style.display = 'flex';
                            resultSection.style.display = 'none';
                        } else {
                            const errP = document.createElement('p');
                            errP.className = 'error';
                            errP.textContent = data.message || '未知错误';
                            resultContent.innerHTML = '';
                            resultContent.appendChild(errP);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        resultContent.innerHTML = '<p class="error">网络请求失败，请稍后重试</p>';
                    });
            }
        });
    </script>
</body>

</html>