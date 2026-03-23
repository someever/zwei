# 紫微斗数算命系统 - 项目规格说明书

## 1. 项目概述

- **项目名称**: 紫微命理
- **项目类型**: Web 应用 (PHP + MySQL)
- **核心功能**: 紫微斗数排盘、命盘解读、付费算命服务
- **目标用户**: 对紫微斗数感兴趣的用户

## 2. 功能流程

```
┌─────────────────────────────────────────────────────────────────┐
│  首页 → 输入出生信息（姓名、性别、出生年月日时分、出生地）         │
└─────────────────────────────┬───────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  支付选择                                                       │
│  ├─ 单次解读 10元  (事业/合婚/财运/健康 四选一)                  │
│  ├─ 四次打包 30元 (事业+合婚+财运+健康)                          │
│  └─ 月卡 666元 (30天内无限次解读)                               │
└─────────────────────────────┬───────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  排盘 + 命盘整体解读 (免费)                                      │
└─────────────────────────────┬───────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  支付验证                                                       │
└─────────────────────────────┬───────────────────────────────────┘
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  详细解读（事业/合婚/财运/健康）                                  │
└─────────────────────────────────────────────────────────────────┘
```

## 3. 数据库设计

### 3.1 用户表 (users)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT | 主键 |
| openid | VARCHAR(64) | 微信OpenID |
| username | VARCHAR(50) | 用户名 |
| balance | DECIMAL(10,2) | 余额 |
| monthly_card | DATETIME | 月卡到期时间 |
| created_at | DATETIME | 注册时间 |
| updated_at | DATETIME | 更新时间 |

### 3.2 订单表 (orders)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT | 主键 |
| user_id | INT | 用户ID |
| order_no | VARCHAR(64) | 订单号 |
| type | ENUM('single','bundle','monthly') | 订单类型 |
| amount | DECIMAL(10,2) | 金额 |
| status | ENUM('pending','paid','cancelled') | 状态 |
| created_at | DATETIME | 创建时间 |
| paid_at | DATETIME | 支付时间 |

### 3.3 算命记录表 (readings)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT | 主键 |
| user_id | INT | 用户ID |
| name | VARCHAR(50) | 姓名 |
| gender | ENUM('male','female') | 性别 |
| birth_year | YEAR | 出生年 |
| birth_month | TINYINT | 出生月 |
| birth_day | TINYINT | 出生日 |
| birth_hour | TINYINT | 出生时 |
| birth_minute | TINYINT | 出生分 |
| birth_location | VARCHAR(100) | 出生地 |
| pan_data | JSON | 命盘数据 |
| reading_type | ENUM('overall','career','marriage','wealth','health') | 解读类型 |
| reading_content | TEXT | 解读内容 |
| created_at | DATETIME | 算命时间 |

### 3.4 支付配置表 (payment_config)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT | 主键 |
| config_key | VARCHAR(50) | 配置键 |
| config_value | TEXT | 配置值 |

## 4. 支付方案

| 产品 | 价格 | 内容 | 有效期 |
|------|------|------|--------|
| 单次 | ¥10 | 四选一（事业/合婚/财运/健康） | 永久 |
| 打包 | ¥30 | 全部四次 | 永久 |
| 月卡 | ¥666 | 30天内无限次 | 30天 |

## 5. 页面设计

### 5.1 首页 (index.php)
- 顶部：Logo + 标题
- 表单：姓名、性别、出生日期时间、出生地
- 底部：支付方案选择 + 开始算命按钮

### 5.2 结果页 (result.php)
- 命盘展示（表格形式）
- 命盘整体解读
- 付费项目选择（事业/合婚/财运/健康）
- 支付按钮

### 5.3 个人中心 (profile.php)
- 余额查询
- 月卡状态
- 算命历史记录
- 充值入口

## 6. API 接口

| 接口 | 方法 | 说明 |
|------|------|------|
| /api/pan.php | POST | 排盘计算 |
| /api/reading.php | POST | 获取解读 |
| /api/payment/create.php | POST | 创建订单 |
| /api/payment/notify.php | POST | 支付回调 |
| /api/user/balance.php | GET | 查询余额 |

## 7. 技术栈

- **后端**: PHP 8.0+ (原生)
- **数据库**: MySQL 8.0
- **前端**: HTML5 + CSS3 + 原生JS
- **支付**: 微信支付（需要商户号）
- **AI**: Gemini API（调用 OpenAI 兼容接口）

## 8. 文件结构

```
zwei/
├── app/
│   ├── controllers/
│   │   ├── IndexController.php
│   │   ├── ResultController.php
│   │   ├── PaymentController.php
│   │   └── UserController.php
│   ├── models/
│   │   ├── User.php
│   │   ├── Order.php
│   │   └── Reading.php
│   ├── views/
│   │   ├── index.php
│   │   ├── result.php
│   │   ├── profile.php
│   │   └── payment.php
│   └── utils/
│       ├── Database.php
│       ├── PanCalculator.php
│       ├── GeminiClient.php
│       └── Payment.php
├── public/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── main.js
├── database/
│   └── schema.sql
├── config.php
└── index.php
```
