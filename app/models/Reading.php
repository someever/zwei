<?php
/**
 * 算命记录模型
 */

class Reading {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function create($data) {
        return $this->db->insert('readings', [
            'user_id' => $data['user_id'] ?? null,
            'session_id' => $data['session_id'] ?? session_id(),
            'name' => $data['name'] ?? '',
            'gender' => $data['gender'],
            'birth_year' => $data['birth_year'],
            'birth_month' => $data['birth_month'],
            'birth_day' => $data['birth_day'],
            'birth_hour' => $data['birth_hour'],
            'birth_minute' => $data['birth_minute'],
            'birth_location' => $data['birth_location'] ?? '',
            'lunar_date' => $data['lunar_date'] ?? '',
            'zhongshu' => $data['zhongshu'] ?? '',
            'shichen' => $data['shichen'] ?? '',
            'pan_data' => json_encode($data['pan_data'], JSON_UNESCAPED_UNICODE),
            'overall_reading' => $data['overall_reading'] ?? '',
            'status' => $data['status'] ?? 'pending'
        ]);
    }

    public function updateReading($readingId, $type, $content) {
        $field = $type . '_reading';
        return $this->db->update('readings', [$field => $content], 'id = :id', ['id' => $readingId]);
    }

    public function getById($readingId) {
        $row = $this->db->fetch("SELECT * FROM readings WHERE id = ?", [$readingId]);
        if ($row && $row['pan_data']) {
            $row['pan_data'] = json_decode($row['pan_data'], true);
        }
        return $row;
    }

    public function getUserReadings($userId, $limit = 20) {
        return $this->db->fetchAll("SELECT id, name, gender, birth_year, birth_month, birth_day, overall_reading, career_reading, marriage_reading, wealth_reading, health_reading, career_wealth_reading, romance_reading, created_at FROM readings WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$userId, $limit]);
    }

    public function getBySessionId($sessionId) {
        $row = $this->db->fetch("SELECT * FROM readings WHERE session_id = ? ORDER BY created_at DESC LIMIT 1", [$sessionId]);
        if ($row && $row['pan_data']) {
            $row['pan_data'] = json_decode($row['pan_data'], true);
        }
        return $row;
    }

    public function getLatestByUserId($userId) {
        $row = $this->db->fetch("SELECT * FROM readings WHERE user_id = ? ORDER BY created_at DESC LIMIT 1", [$userId]);
        if ($row && $row['pan_data']) {
            $row['pan_data'] = json_decode($row['pan_data'], true);
        }
        return $row;
    }

    public function getUserReadingsList($userId, $limit = 10) {
        return $this->db->fetchAll("SELECT id, name, status, created_at FROM readings WHERE user_id = ? ORDER BY created_at DESC LIMIT ?", [$userId, $limit]);
    }

    public function updateStatus($readingId, $status) {
        return $this->db->update('readings', ['status' => $status], 'id = :id', ['id' => $readingId]);
    }

    public function updateOverallReading($readingId, $content) {
        return $this->db->update('readings', ['overall_reading' => $content, 'status' => 'completed'], 'id = :id', ['id' => $readingId]);
    }

    public function getPendingReadings($limit = 10) {
        return $this->db->fetchAll("SELECT * FROM readings WHERE status IN ('pending', 'processing') ORDER BY created_at ASC LIMIT ?", [$limit]);
    }
}
