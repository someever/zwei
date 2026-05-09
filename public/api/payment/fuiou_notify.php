<?php
/**
 * 富友聚合支付回调通知
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../app/utils/Database.php';
require_once __DIR__ . '/../../../app/utils/FuiouPay.php';
require_once __DIR__ . '/../../../app/models/User.php';
require_once __DIR__ . '/../../../app/models/Order.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        $data = $_POST;
    }

    if (empty($data)) {
        throw new Exception('无回调数据');
    }

    error_log('Received Fuiou Pay notify: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $payment = new FuiouPay();
    if ($payment->processNotify($data)) {
        echo '1';
        exit;
    }

    error_log('Fuiou Pay notify verify/process failed: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
} catch (Exception $e) {
    error_log('Fuiou Pay notify error: ' . $e->getMessage());
}

echo 'fail';
