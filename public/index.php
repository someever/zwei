<?php
/**
 * 首页 - 检查用户状态，引导到正确页面
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/utils/Database.php';
require_once __DIR__ . '/../app/models/Reading.php';

session_start();

// 微信授权逻辑
if (strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'MicroMessenger') !== false && WECHAT_APPID && WECHAT_APPSECRET) {
    require_once __DIR__ . '/../app/utils/Wechat.php';
    $wechat = new Wechat();
    
    if (isset($_GET['code'])) {
        $openid = $wechat->getOpenidByCode($_GET['code']);
        if ($openid) {
            $userModel = new User();
            $user = $userModel->findOrCreateByOpenid($openid);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['openid'] = $openid;
            $_SESSION['openid_verified'] = true;
        }
        // 移除 code 参数，避免污染 URL
        $url = APP_URL . '/index.php' . (isset($_GET['new']) ? '?new=1' : '');
        header("Location: " . $url);
        exit;
    } elseif (!isset($_SESSION['openid_verified'])) {
        $redirectUrl = APP_URL . $_SERVER['REQUEST_URI'];
        header("Location: " . $wechat->getAuthUrl($redirectUrl));
        exit;
    }
}

// 如果从外部（非微信）进入，且没有 userId，创建一个演示用户
if (!isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../app/models/User.php';
    $userModel = new User();
    $user = $userModel->createDemoUser();
    $_SESSION['user_id'] = $user['id'];
}

// 如果带了 ?new=1，说明用户主动要回首页重新算
$forceNew = isset($_GET['new']) && $_GET['new'] == '1';

$userId = $_SESSION['user_id'] ?? null;
if ($userId && !$forceNew) {
    $readingModel = new Reading();
    $latestReading = $readingModel->getLatestByUserId($userId);
    
    if ($latestReading && in_array($latestReading['status'], ['pending', 'processing'])) {
        // 超过10分钟的 processing 记录视为超时，自动标记为 failed
        $createdAt = strtotime($latestReading['created_at'] . ' UTC');
        if (time() - $createdAt > 600) {
            $readingModel->updateStatus($latestReading['id'], 'failed');
        } else {
            header('Location: processing.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>知运星 - 探索你的命运轨迹</title>
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
            <p class="subtitle">探索你的命运轨迹</p>
        </header>

        <main class="main">
            <!-- 手工输入模式 -->
            <form id="birthForm" class="form mode-form active">
                <div class="form-group">
                    <label for="name">姓名</label>
                    <input type="text" id="name" name="name" placeholder="请输入姓名" required>
                </div>

                <div class="form-group">
                    <label>性别</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="gender" value="male" checked>
                            <span>男</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="gender" value="female">
                            <span>女</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="birthDate">出生日期</label>
                    <input type="date" id="birthDate" name="birthDate" required>
                </div>

                <div class="form-group">
                    <label for="birthTime">出生时间</label>
                    <div class="time-inputs">
                        <select id="birthHour" name="birthHour" required>
                            <?php for ($i = 0; $i < 24; $i++): ?>
                                <option value="<?= $i ?>"><?= str_pad($i, 2, '0', STR_PAD_LEFT) ?> 时</option>
                            <?php endfor; ?>
                        </select>
                        <select id="birthMinute" name="birthMinute" required>
                            <?php for ($i = 0; $i < 60; $i++): ?>
                                <option value="<?= $i ?>"><?= str_pad($i, 2, '0', STR_PAD_LEFT) ?> 分</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="provinceSelect">出生地点</label>
                    <select id="provinceSelect" name="province" required>
                        <option value="">请选择省份/直辖市</option>
                    </select>
                    <input type="hidden" id="citySelect" name="city" value="">
                </div>

                <button type="submit" class="btn-primary">查看运势</button>
            </form>
        </main>

        <footer class="footer">
            <p>© 2026 知运星 · 紫微斗数传承</p>
            <script src="js/main.js"></script>
        </footer>
    </div>
</body>

</html>