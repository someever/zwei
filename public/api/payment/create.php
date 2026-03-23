<?php
/**
 * 创建支付订单
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../app/utils/Database.php';
require_once __DIR__ . '/../../../app/utils/Payment.php';
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
    
    // 获取价格
    $prices = [
        'single' => PAYMENT_SINGLE_PRICE,
        'bundle' => PAYMENT_BUNDLE_PRICE,
        'monthly' => PAYMENT_MONTHLY_PRICE
    ];
    
    $amount = $prices[$type] ?? PAYMENT_SINGLE_PRICE;
    
    // 生成订单号
    $orderNo = 'ZWEI' . date('YmdHis') . rand(1000, 9999);
    
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
    
    // 调用微信支付创建订单
    $payment = new Payment();
    $payResult = $payment->createOrder($orderNo, $amount, $description);
    
    $result['success'] = true;
    $result['order_no'] = $orderNo;
    $result['amount'] = $amount;
    $result['code_url'] = $payResult['code_url']; // 二维码链接
    
} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
