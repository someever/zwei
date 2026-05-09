<?php
/**
 * 创建支付订单
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../app/utils/Database.php';
require_once __DIR__ . '/../../../app/utils/FuiouPay.php';
require_once __DIR__ . '/../../../app/models/Order.php';

header('Content-Type: application/json; charset=utf-8');

session_start();

$result = ['success' => false, 'message' => ''];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $data = $_POST ?: $input;
    
    if (!$data) {
        throw new Exception('无效的请求数据');
    }
    
    $type = $data['type'] ?? 'single'; // single, bundle, monthly
    $readingId = $data['reading_id'] ?? 0;
    $payMethod = $data['pay_method'] ?? 'wechat';
    
    // 获取价格
    $prices = [
        'single' => PAYMENT_SINGLE_PRICE,
        'bundle' => PAYMENT_BUNDLE_PRICE,
        'monthly' => PAYMENT_MONTHLY_PRICE
    ];
    
    $amount = $prices[$type] ?? PAYMENT_SINGLE_PRICE;
    
    // 生成订单号
    $orderNo = FUIOU_ORDER_PREFIX . date('YmdHis') . random_int(10000000, 99999999);
    
    // 订单描述
    $descriptions = [
        'single' => '紫微斗数单次解读',
        'bundle' => '紫微斗数四次打包',
        'monthly' => '紫微斗数月卡会员'
    ];
    $description = $descriptions[$type] ?? '紫微斗数解读';
    
    // 创建本地订单记录
    $orderModel = new Order();
    $userId = $_SESSION['user_id'] ?? 0;
    $orderId = $orderModel->create([
        'user_id' => $userId,
        'order_no' => $orderNo,
        'type' => $type,
        'amount' => $amount,
        'description' => $description,
        'reading_id' => $readingId,
        'status' => 'pending'
    ]);
    
    // 记录到 session 中，以便匿名用户支付后刷新页面能够识别到该订单
    $_SESSION['pending_orders'] = $_SESSION['pending_orders'] ?? [];
    $_SESSION['pending_orders'][] = $orderNo;
    
    $payMethod = $payMethod === 'alipay' ? 'alipay' : 'wechat';
    $orderType = $payMethod === 'alipay' ? 'ALIPAY' : 'WECHAT';
    $payment = new FuiouPay();
    $inWechat = strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'MicroMessenger') !== false;

    if ($payMethod === 'alipay' && $inWechat) {
        throw new Exception('微信内暂不支持直接打开支付宝，请点击右上角在浏览器中打开后再选择支付宝。');
    }

    if ($payMethod === 'wechat' && $inWechat) {
        $payResult = $payment->createJsapiOrder($orderNo, $amount, $description, WECHAT_APPID, $_SESSION['openid'] ?? '');
        $tradeType = 'FUIOU_JSAPI';
    } else {
        $payResult = $payment->createOrder($orderNo, $amount, $description, $orderType);
        $tradeType = 'FUIOU_' . $orderType;
    }
    
    $result['success'] = true;
    $result['order_no'] = $orderNo;
    $result['amount'] = $amount;
    $result['pay_method'] = $payMethod;
    $result['trade_type'] = $tradeType;
    $result['pay_url'] = $payResult['pay_url'] ?? '';
    $result['code_url'] = $payResult['code_url'] ?? '';
    $result['jsapi_params'] = $payResult['jsapi_params'] ?? null;
    $result['query_order_type'] = $orderType;
    
} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
