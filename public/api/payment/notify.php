<?php
/**
 * 微信支付回调通知
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../app/utils/Database.php';
require_once __DIR__ . '/../../../app/utils/Payment.php';
require_once __DIR__ . '/../../../app/models/User.php';
require_once __DIR__ . '/../../../app/models/Order.php';

header('Content-Type: application/xml; charset=utf-8');

$payment = new Payment();
$result = ['return_code' => 'SUCCESS', 'return_msg' => 'OK'];

try {
    $xml = file_get_contents('php://input');

    if (empty($xml)) {
        throw new Exception('无回调数据');
    }

    $processResult = $payment->processNotify($xml);

    if ($processResult['return_code'] !== 'SUCCESS') {
        $result = $processResult;
    }

} catch (Exception $e) {
    error_log('WeChat Pay notify error: ' . $e->getMessage());
    $result = ['return_code' => 'FAIL', 'return_msg' => $e->getMessage()];
}

echo $payment->arrayToXml($result);
