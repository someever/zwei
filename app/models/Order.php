<?php
/**
 * 订单模型
 */

class Order {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * 创建订单
     */
    public function create($data) {
        $orderNo = 'Z' . date('YmdHis') . rand(1000, 9999);
        
        $typeMap = ['single' => '单次解读', 'bundle' => '四次打包', 'monthly' => '月卡'];

        return $this->db->insert('orders', [
            'order_no' => $data['order_no'] ?? $orderNo,
            'user_id' => $data['user_id'] ?? 0,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'description' => $data['description'] ?? ($typeMap[$data['type']] ?? $data['type']),
            'status' => $data['status'] ?? 'pending'
        ]);
    }

    public function getByOrderNo($orderNo) {
        return $this->db->fetch("SELECT * FROM orders WHERE order_no = ?", [$orderNo]);
    }

    public function markAsPaid($orderNo) {
        return $this->db->update('orders', ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')], 'order_no = :orderNo', ['orderNo' => $orderNo]);
    }

    public function cancel($orderNo) {
        return $this->db->update('orders', ['status' => 'cancelled'], 'order_no = :orderNo', ['orderNo' => $orderNo]);
    }

    public function getUserOrders($userId, $limit = 20) {
        return $this->db->fetchAll("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$userId, $limit]);
    }

    /**
     * 处理支付成功
     */
    public function processPayment($orderNo, $transactionId = '') {
        $order = $this->getByOrderNo($orderNo);
        if (!$order || $order['status'] !== 'pending') {
            return false;
        }

        $userModel = new User();
        
        // 更新订单状态
        $updateData = ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')];
        if ($transactionId) {
            $updateData['transaction_id'] = $transactionId;
        }
        $this->db->update('orders', $updateData, 'order_no = :orderNo', ['orderNo' => $orderNo]);

        // 根据订单类型处理
        switch ($order['type']) {
            case 'single':
                // 单次：增加余额（用于解锁解读）
                // 余额在购买时已经通过 purchased_types 解锁，这里只记录
                break;
            case 'bundle':
                // 打包：增加余额
                break;
            case 'monthly':
                // 月卡：开通月卡
                $userModel->activateMonthlyCard($order['user_id'], 30);
                break;
        }

        // 更新用户消费总额
        $user = $userModel->getById($order['user_id']);
        if ($user) {
            $this->db->update('users', 
                ['total_spend' => ($user['total_spend'] ?? 0) + $order['amount']], 
                'id = :id', 
                ['id' => $order['user_id']]
            );
        }

        return true;
    }
}
