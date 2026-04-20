<?php
/**
 * AI 解析出生信息接口 - 已停用
 * 请使用手工表单输入出生信息
 */
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'message' => '请使用手工输入表单填写出生信息'
], JSON_UNESCAPED_UNICODE);
