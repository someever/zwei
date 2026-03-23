<?php
/**
 * 支付工具类（微信支付 - Native扫码支付）
 */

class Payment {
    private $appid;
    private $mchId;
    private $apiKey;
    private $notifyUrl;
    private $certPath;
    private $keyPath;

    public function __construct() {
        // 使用 config.php 中的常量
        $this->appid = defined('WECHAT_APPID') ? WECHAT_APPID : '';
        $this->mchId = defined('WECHAT_MCH_ID') ? WECHAT_MCH_ID : '';
        $this->apiKey = defined('WECHAT_API_KEY') ? WECHAT_API_KEY : '';
        $this->notifyUrl = defined('WECHAT_NOTIFY_URL') ? WECHAT_NOTIFY_URL : '';
        $this->certPath = defined('WECHAT_CERT_PATH') ? WECHAT_CERT_PATH : '';
        $this->keyPath = defined('WECHAT_KEY_PATH') ? WECHAT_KEY_PATH : '';
    }

    /**
     * 创建 Native 支付订单
     * @param string $orderNo 订单号
     * @param float $amount 金额（元）
     * @param string $description 订单描述
     * @return array 二维码链接等信息
     */
    public function createOrder($orderNo, $amount, $description) {
        $totalFee = intval($amount * 100); // 转换为分
        
        $params = [
            'appid' => $this->appid,
            'mch_id' => $this->mchId,
            'nonce_str' => $this->generateNonceStr(),
            'body' => mb_substr($description, 0, 32),
            'out_trade_no' => $orderNo,
            'total_fee' => $totalFee,
            'spbill_create_ip' => $this->getClientIp(),
            'notify_url' => $this->notifyUrl,
            'trade_type' => 'NATIVE'
        ];

        $params['sign'] = $this->generateSign($params);

        $xml = $this->arrayToXml($params);
        $response = $this->post('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);
        $result = $this->xmlToArray($response);

        if ($result['return_code'] === 'SUCCESS' && $result['result_code'] === 'SUCCESS') {
            return [
                'success' => true,
                'prepay_id' => $result['prepay_id'],
                'code_url' => $result['code_url'], // 二维码链接
                'order_no' => $orderNo,
                'amount' => $amount
            ];
        }

        throw new Exception('创建支付订单失败: ' . ($result['return_msg'] ?? $result['err_code_des'] ?? '未知错误'));
    }

    /**
     * 查询订单状态
     */
    public function queryOrder($orderNo) {
        $params = [
            'appid' => '',
            'mch_id' => $this->mchId,
            'out_trade_no' => $orderNo,
            'nonce_str' => $this->generateNonceStr()
        ];
        
        $params['sign'] = $this->generateSign($params);
        
        $xml = $this->arrayToXml($params);
        $response = $this->post('https://api.mch.weixin.qq.com/pay/orderquery', $xml);
        $result = $this->xmlToArray($response);
        
        return $result;
    }

    /**
     * 验证支付回调签名
     */
    public function verifyNotify($xml) {
        $data = $this->xmlToArray($xml);
        
        if (!isset($data['sign'])) {
            return false;
        }

        $sign = $data['sign'];
        unset($data['sign']);
        
        return $this->generateSign($data) === $sign;
    }

    /**
     * 处理支付回调
     */
    public function processNotify($xml) {
        $data = $this->xmlToArray($xml);
        
        if ($data['return_code'] !== 'SUCCESS') {
            return ['return_code' => 'FAIL', 'return_msg' => '签名失败'];
        }

        // 验证签名
        if (!$this->verifyNotify($xml)) {
            return ['return_code' => 'FAIL', 'return_msg' => '签名验证失败'];
        }

        // 处理订单
        $orderModel = new Order();
        $result = $orderModel->processPayment($data['out_trade_no'], $data['transaction_id'] ?? '');

        if ($result) {
            return ['return_code' => 'SUCCESS', 'return_msg' => 'OK'];
        }

        return ['return_code' => 'FAIL', 'return_msg' => '处理失败'];
    }

    /**
     * 生成签名
     */
    private function generateSign($params) {
        ksort($params);
        $string = '';
        foreach ($params as $key => $value) {
            if ($key && $value !== '' && $key !== 'sign') {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'key=' . $this->apiKey;
        return strtoupper(md5($string));
    }

    /**
     * 生成随机字符串
     */
    private function generateNonceStr($length = 32) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * 获取客户端IP
     */
    private function getClientIp() {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ip[0]);
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return '127.0.0.1';
    }

    /**
     * POST请求（带证书）
     */
    private function post($url, $data, $useCert = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // 客户端证书认证
        if ($useCert && $this->certPath && $this->keyPath) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->certPath);
            curl_setopt($ch, CURLOPT_SSLKEY, $this->keyPath);
        }
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('CURL错误: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        return $response;
    }

    /**
     * 数组转XML
     */
    private function arrayToXml($arr) {
        $xml = '<xml>';
        foreach ($arr as $key => $val) {
            $xml .= '<' . $key . '><![CDATA[' . $val . ']]></' . $key . '>';
        }
        $xml .= '</xml>';
        return $xml;
    }

    /**
     * XML转数组
     */
    private function xmlToArray($xml) {
        $result = [];
        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);
        if (!$obj) {
            return $result;
        }
        $obj = (array)$obj;
        foreach ($obj as $key => $value) {
            $result[$key] = (string)$value;
        }
        return $result;
    }
}
