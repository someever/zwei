<?php
/**
 * 微信 H5 拉起小程序支付的中间页
 */
$scheme = $_GET['scheme'] ?? '';
if (empty($scheme) || strpos($scheme, 'weixin://') !== 0) {
    die('无效的跳转链接');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>正在拉起微信支付</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            text-align: center;
            padding-top: 80px;
            background: #f5f5f5;
            margin: 0;
        }
        .icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        .title {
            font-size: 20px;
            color: #333;
            margin-bottom: 10px;
            font-weight: 500;
        }
        .tips {
            color: #888;
            font-size: 14px;
            margin-bottom: 40px;
            padding: 0 20px;
            line-height: 1.5;
        }
        .btn {
            display: inline-block;
            padding: 14px 40px;
            background: #07c160;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(7, 193, 96, 0.3);
        }
        .btn:active {
            background: #06ad56;
        }
    </style>
</head>
<body>
    <div class="icon">微信支付</div>
    <div class="title">正在前往微信支付...</div>
    <p class="tips">如果页面没有自动跳转，请点击下方按钮拉起微信</p>
    <a href="<?php echo htmlspecialchars($scheme, ENT_QUOTES, 'UTF-8'); ?>" class="btn">点此打开微信</a>

    <script>
        // 尝试自动拉起微信
        setTimeout(function() {
            window.location.href = <?php echo json_encode($scheme); ?>;
        }, 500);

        // 监听返回事件，如果在微信里放弃支付返回了这个页面，可以提供一个返回商户的按钮或者自动后退
        document.addEventListener("visibilitychange", function() {
            if (document.visibilityState === 'visible') {
                // 用户可能从微信返回了
                const btn = document.querySelector('.btn');
                btn.textContent = '重新拉起微信支付';
                btn.style.background = '#fff';
                btn.style.color = '#07c160';
                btn.style.border = '1px solid #07c160';
                
                let backBtn = document.getElementById('backBtn');
                if (!backBtn) {
                    backBtn = document.createElement('a');
                    backBtn.id = 'backBtn';
                    backBtn.href = 'javascript:history.back();';
                    backBtn.textContent = '返回重新选择';
                    backBtn.style.display = 'block';
                    backBtn.style.marginTop = '20px';
                    backBtn.style.color = '#666';
                    backBtn.style.textDecoration = 'none';
                    document.body.appendChild(backBtn);
                }
            }
        });
    </script>
</body>
</html>
