-- 紫微斗数算命系统数据库

CREATE DATABASE IF NOT EXISTS zwei CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE zwei;

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    openid VARCHAR(64) UNIQUE COMMENT '微信OpenID',
    username VARCHAR(50) COMMENT '用户名',
    avatar VARCHAR(255) COMMENT '头像URL',
    balance DECIMAL(10,2) DEFAULT 0.00 COMMENT '余额',
    monthly_card DATETIME NULL COMMENT '月卡到期时间',
    total_spend DECIMAL(10,2) DEFAULT 0.00 COMMENT '累计消费',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_openid (openid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 订单表
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(64) UNIQUE COMMENT '订单号',
    user_id INT NOT NULL COMMENT '用户ID',
    type ENUM('single','bundle','monthly') NOT NULL COMMENT '订单类型',
    amount DECIMAL(10,2) NOT NULL COMMENT '金额',
    status ENUM('pending','paid','cancelled','refunded') DEFAULT 'pending',
    description VARCHAR(255) COMMENT '订单描述',
    paid_at DATETIME NULL COMMENT '支付时间',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_order_no (order_no),
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 算命记录表
CREATE TABLE IF NOT EXISTS readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT COMMENT '用户ID(可为空)',
    session_id VARCHAR(64) COMMENT '会话ID(未登录时)',
    name VARCHAR(50) COMMENT '姓名',
    gender ENUM('male','female') NOT NULL,
    birth_year YEAR NOT NULL,
    birth_month TINYINT NOT NULL,
    birth_day TINYINT NOT NULL,
    birth_hour TINYINT NOT NULL,
    birth_minute TINYINT NOT NULL,
    birth_location VARCHAR(100) COMMENT '出生地',
    lunar_date VARCHAR(50) COMMENT '农历日期',
    zhongshu VARCHAR(50) COMMENT '中气',
    shichen VARCHAR(10) COMMENT '时辰',
    pan_data JSON COMMENT '命盘数据',
    overall_reading TEXT COMMENT '命盘整体解读',
    career_reading TEXT COMMENT '事业解读',
    marriage_reading TEXT COMMENT '合婚解读',
    wealth_reading TEXT COMMENT '财运解读',
    health_reading TEXT COMMENT '健康解读',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 支付配置表
CREATE TABLE IF NOT EXISTS payment_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(50) UNIQUE NOT NULL,
    config_value TEXT,
    description VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始化支付配置
INSERT INTO payment_config (config_key, config_value, description) VALUES
('wechat_appid', '', '微信AppID'),
('wechat_mch_id', '', '微信商户号'),
('wechat_api_key', '', '微信支付API密钥'),
('wechat_notify_url', '', '微信支付回调地址'),
('gemini_api_key', '', 'Gemini API密钥'),
('gemini_model', 'gemini-2.0-flash', 'Gemini模型'),
('single_price', '10', '单次价格(元)'),
('bundle_price', '30', '打包价格(元)'),
('monthly_price', '666', '月卡价格(元)'),
('monthly_days', '30', '月卡天数');
