<?php
/**
 * 支付宝支付回调通知
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../app/utils/Database.php';
require_once __DIR__ . '/../../../app/utils/Alipay.php';
require_once __DIR__ . '/../../../app/models/User.php';
require_once __DIR__ . '/../../../app/models/Order.php';

$alipay = new Alipay();

try {
    $params = $_POST;

    if (empty($params)) {
        throw new Exception('无回调数据');
    }

    // 验证签名
    if ($alipay->verifyNotify($params)) {
        // 支付成功
        if ($params['trade_status'] === 'TRADE_SUCCESS' || $params['trade_status'] === 'TRADE_FINISHED') {
            $orderNo = $params['out_trade_no'];
            $tradeNo = $params['trade_no'];

            // 处理订单
            $orderModel = new Order();
            $orderModel->processPayment($orderNo, $tradeNo);

            echo "success";
            exit;
        }
    } else {
        error_log('Alipay notify sign error: ' . json_encode($params));
    }

} catch (Exception $e) {
    error_log('Alipay Pay notify error: ' . $e->getMessage());
}

echo "fail";
