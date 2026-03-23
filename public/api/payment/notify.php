<?php
/**
 * 微信支付回调通知
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../app/utils/Database.php';
require_once __DIR__ . '/../../../app/utils/Payment.php';
require_once __DIR__ . '/../../../app/models/Order.php';

header('Content-Type: application/xml; charset=utf-8');

$result = ['return_code' => 'SUCCESS', 'return_msg' => 'OK'];

try {
    $xml = file_get_contents('php://input');
    
    if (empty($xml)) {
        throw new Exception('无回调数据');
    }
    
    $payment = new Payment();
    $payment->processNotify($xml);
    
} catch (Exception $e) {
    $result = ['return_code' => 'FAIL', 'return_msg' => $e->getMessage()];
}

echo $payment->arrayToXml($result);
