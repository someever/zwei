<?php
/**
 * 富友聚合支付订单查询
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../app/utils/Database.php';
require_once __DIR__ . '/../../../app/utils/FuiouPay.php';
require_once __DIR__ . '/../../../app/models/User.php';
require_once __DIR__ . '/../../../app/models/Order.php';

header('Content-Type: application/json; charset=utf-8');
session_start();

$result = ['success' => false, 'paid' => false, 'message' => ''];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $data = $_POST ?: $input;

    $orderNo = trim($data['order_no'] ?? '');
    $orderType = strtoupper(trim($data['order_type'] ?? 'WECHAT'));

    if ($orderNo === '') {
        throw new Exception('缺少订单号');
    }

    if (!in_array($orderType, ['WECHAT', 'ALIPAY'], true)) {
        throw new Exception('无效的订单类型');
    }

    $orderModel = new Order();
    $order = $orderModel->getByOrderNo($orderNo);
    if (!$order) {
        throw new Exception('订单不存在');
    }

    if (isset($_SESSION['user_id']) && (int) $order['user_id'] !== (int) $_SESSION['user_id']) {
        throw new Exception('无权查询该订单');
    }

    if ($order['status'] === 'paid') {
        $result['success'] = true;
        $result['paid'] = true;
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $payment = new FuiouPay();
    $query = $payment->queryOrder($orderNo, $orderType, true);
    $paid = ($query['result_code'] ?? '') === '000000' && ($query['trans_stat'] ?? '') === 'SUCCESS';

    $result['success'] = true;
    $result['paid'] = $paid;
    $result['trans_stat'] = $query['trans_stat'] ?? '';
    $result['result_code'] = $query['result_code'] ?? '';
    $result['result_msg'] = $query['result_msg'] ?? '';
} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
