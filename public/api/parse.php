<?php
/**
 * 解析自然语言出生信息
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/utils/GeminiClient.php';

header('Content-Type: application/json; charset=utf-8');

$result = ['success' => false, 'message' => ''];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $message = $input['message'] ?? '';
    
    if (!$message) {
        throw new Exception('请输入出生信息');
    }
    
    $prompt = <<<'PROMPT'
请从用户的描述中提取出生信息，并返回JSON格式。

需要提取的字段：
- name: 姓名（字符串）
- gender: 性别（male=男，female=女）
- birthYear: 出生年份（数字）
- birthMonth: 出生月份（1-12）
- birthDay: 出生日期（1-31）
- birthHour: 出生小时（0-23）
- birthMinute: 出生分钟（0-59）
- birthLocation: 出生城市（字符串）

用户可能的描述方式：
- "我1995年8月15日下午3点半在北京出生，男"
- "1995年8月15日15:30 男 北京"
- "女孩，1995年8月15日，亥时，北京"
- "女，1995年8月15日，晚上9点出生，上海"
- "男，95年5月20日，上午10点15分"

请直接返回JSON，不要其他文字。格式如下：
{"name":"张三","gender":"male","birthYear":1995,"birthMonth":8,"birthDay":15,"birthHour":15,"birthMinute":30,"birthLocation":"北京"}

如果无法确定某字段，使用null。
PROMPT;

    $gemini = new GeminiClient();
    $response = $gemini->generate($message, $prompt);
    
    // 解析JSON响应
    $response = trim($response);
    
    // 尝试提取JSON
    if (preg_match('/\{.*\}/s', $response, $matches)) {
        $data = json_decode($matches[0], true);
        
        if ($data && is_array($data)) {
            // 验证必要字段
            if (empty($data['birthYear']) || empty($data['birthMonth']) || empty($data['birthDay'])) {
                throw new Exception('请提供完整的出生年月日信息');
            }
            
            // 格式化日期
            $birthDate = sprintf('%04d-%02d-%02d', 
                $data['birthYear'], 
                $data['birthMonth'], 
                $data['birthDay']
            );
            
            $result['success'] = true;
            $result['data'] = [
                'name' => $data['name'] ?? '',
                'gender' => $data['gender'] ?? 'male',
                'birthDate' => $birthDate,
                'birthHour' => $data['birthHour'] ?? 0,
                'birthMinute' => $data['birthMinute'] ?? 0,
                'birthLocation' => $data['birthLocation'] ?? ''
            ];
        } else {
            throw new Exception('无法解析你的出生信息，请使用表单模式输入');
        }
    } else {
        throw new Exception('无法解析你的出生信息，请使用表单模式输入');
    }
    
} catch (Exception $e) {
    $result['message'] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
