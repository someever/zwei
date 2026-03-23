<?php
/**
 * 数据库工具类 - 支持 SQLite
 */

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        if (DB_TYPE === 'sqlite') {
            $dbPath = DB_PATH;
            // 确保目录存在
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->pdo = new PDO('sqlite:' . $dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // 初始化表
            $this->initTables();
        } else {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    /**
     * 初始化 SQLite 表
     */
    private function initTables() {
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                openid VARCHAR(64) UNIQUE,
                username VARCHAR(50),
                avatar VARCHAR(255),
                balance DECIMAL(10,2) DEFAULT 0.00,
                monthly_card DATETIME,
                total_spend DECIMAL(10,2) DEFAULT 0.00,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_no VARCHAR(64) UNIQUE,
                user_id INTEGER,
                type VARCHAR(20) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                description VARCHAR(255),
                paid_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS readings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                session_id VARCHAR(64),
                name VARCHAR(50),
                gender VARCHAR(10) NOT NULL,
                birth_year INTEGER NOT NULL,
                birth_month INTEGER NOT NULL,
                birth_day INTEGER NOT NULL,
                birth_hour INTEGER NOT NULL,
                birth_minute INTEGER NOT NULL,
                birth_location VARCHAR(100),
                lunar_date VARCHAR(50),
                zhongshu VARCHAR(50),
                shichen VARCHAR(10),
                pan_data TEXT,
                overall_reading TEXT,
                career_reading TEXT,
                marriage_reading TEXT,
                wealth_reading TEXT,
                health_reading TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS payment_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                config_key VARCHAR(50) UNIQUE NOT NULL,
                config_value TEXT,
                description VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ";
        
        $this->pdo->exec($sql);
    }

    // 查找单条记录
    public function fetch($sql, $params = []) {
        if (DB_TYPE === 'sqlite') {
            $sql = str_replace('`', '', $sql);
            // 处理 MySQL 语法转 SQLite
            $sql = preg_replace('/LIMIT \?/i', 'LIMIT $1', $sql);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    // 查找多条记录
    public function fetchAll($sql, $params = []) {
        if (DB_TYPE === 'sqlite') {
            $sql = str_replace('`', '', $sql);
            $sql = preg_replace('/LIMIT \?/i', 'LIMIT $1', $sql);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // 插入记录
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        
        if (DB_TYPE === 'sqlite') {
            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s)",
                $table,
                implode(', ', $fields),
                implode(', ', $placeholders)
            );
        } else {
            $sql = sprintf(
                "INSERT INTO `%s` (%s) VALUES (%s)",
                $table,
                implode(', ', $fields),
                implode(', ', $placeholders)
            );
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return $this->pdo->lastInsertId();
    }

    // 更新记录
    public function update($table, $data, $where, $whereParams = []) {
        $sets = array_map(fn($f) => "$f = :$f", array_keys($data));
        
        if (DB_TYPE === 'sqlite') {
            $sql = sprintf(
                "UPDATE %s SET %s WHERE %s",
                $table,
                implode(', ', $sets),
                $where
            );
        } else {
            $sql = sprintf(
                "UPDATE `%s` SET %s WHERE %s",
                $table,
                implode(', ', $sets),
                $where
            );
        }
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array_merge($data, $whereParams));
    }

    // 删除记录
    public function delete($table, $where, $params = []) {
        $sql = sprintf("DELETE FROM %s WHERE %s", $table, $where);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    // 开启事务
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    // 提交事务
    public function commit() {
        return $this->pdo->commit();
    }

    // 回滚事务
    public function rollBack() {
        return $this->pdo->rollBack();
    }

    // 获取配置
    public function getConfig($key, $default = null) {
        $row = $this->fetch("SELECT config_value FROM payment_config WHERE config_key = ?", [$key]);
        return $row ? $row['config_value'] : $default;
    }

    // 设置配置
    public function setConfig($key, $value) {
        // SQLite upsert
        $exists = $this->fetch("SELECT id FROM payment_config WHERE config_key = ?", [$key]);
        if ($exists) {
            return $this->update(
                'payment_config',
                ['config_value' => $value],
                'config_key = :key',
                ['key' => $key]
            );
        } else {
            return $this->insert('payment_config', [
                'config_key' => $key,
                'config_value' => $value
            ]);
        }
    }
}
