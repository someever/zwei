<?php
/**
 * 用户模型
 */

class User {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * 通过OpenID查找或创建用户
     */
    public function findOrCreateByOpenid($openid, $username = '') {
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE openid = ?",
            [$openid]
        );

        if (!$user) {
            $userId = $this->db->insert('users', [
                'openid' => $openid,
                'username' => $username ?: '用户' . substr($openid, -4)
            ]);
            $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        }

        return $user;
    }

    /**
     * 通过ID获取用户
     */
    public function getById($userId) {
        return $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
    }

    /**
     * 更新用户信息
     */
    public function update($userId, $data) {
        return $this->db->update('users', $data, 'id = :id', ['id' => $userId]);
    }

    /**
     * 检查用户是否有月卡
     */
    public function hasMonthlyCard($user) {
        if (!$user || !$user['monthly_card']) {
            return false;
        }
        return strtotime($user['monthly_card']) > time();
    }

    /**
     * 检查用户是否可以免费解读
     */
    public function canReadFree($user, $readingType) {
        // 月卡用户无限次
        if ($this->hasMonthlyCard($user)) {
            return true;
        }
        return false;
    }

    /**
     * 获取用户余额
     */
    public function getBalance($userId) {
        $user = $this->getById($userId);
        return $user ? $user['balance'] : 0;
    }

    /**
     * 扣除余额
     */
    public function deductBalance($userId, $amount) {
        $user = $this->getById($userId);
        if (!$user || $user['balance'] < $amount) {
            return false;
        }

        return $this->db->update(
            'users',
            ['balance' => $user['balance'] - $amount],
            'id = :id',
            ['id' => $userId]
        );
    }

    /**
     * 增加余额
     */
    public function addBalance($userId, $amount) {
        $user = $this->getById($userId);
        if (!$user) {
            return false;
        }

        $newBalance = $user['balance'] + $amount;
        return $this->db->update(
            'users',
            ['balance' => $newBalance],
            'id = :id',
            ['id' => $userId]
        );
    }

    /**
     * 开通/续费月卡
     */
    public function activateMonthlyCard($userId, $days = 30) {
        $user = $this->getById($userId);
        $currentExpiry = $user['monthly_card'] ? strtotime($user['monthly_card']) : time();
        $newExpiry = max($currentExpiry, time()) + ($days * 86400);

        return $this->db->update(
            'users',
            ['monthly_card' => date('Y-m-d H:i:s', $newExpiry)],
            'id = :id',
            ['id' => $userId]
        );
    }

    /**
     * 创建演示用户
     */
    public function createDemoUser() {
        $sessionId = session_id();
        $user = $this->db->fetch("SELECT * FROM users WHERE openid = ?", [$sessionId]);
        
        if (!$user) {
            $this->db->insert('users', [
                'openid' => $sessionId,
                'username' => '访客'
            ]);
            $user = $this->db->fetch("SELECT * FROM users WHERE openid = ?", [$sessionId]);
        }
        
        return $user;
    }
}
