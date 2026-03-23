<?php
/**
 * 支付宝支付工具类 (手机网站支付 WAP)
 */

class Alipay
{
    private $appId;
    private $privateKey;
    private $publicKey;
    private $notifyUrl;
    private $gateway = "https://openapi.alipay.com/gateway.do";

    public function __construct()
    {
        $this->appId = defined('ALIPAY_APPID') ? ALIPAY_APPID : '';
        $this->privateKey = defined('ALIPAY_PRIVATE_KEY') ? ALIPAY_PRIVATE_KEY : '';
        $this->publicKey = defined('ALIPAY_PUBLIC_KEY') ? ALIPAY_PUBLIC_KEY : '';
        $this->notifyUrl = defined('ALIPAY_NOTIFY_URL') ? ALIPAY_NOTIFY_URL : '';
    }

    /**
     * 创建支付订单 (WAP/手机网站)
     */
    public function createOrder($orderNo, $amount, $description)
    {
        $bizContent = [
            'out_trade_no' => $orderNo,
            'total_amount' => number_format($amount, 2, '.', ''),
            'subject' => mb_substr($description, 0, 100),
            'product_code' => 'QUICK_WAP_WAY',
            'timeout_express' => '30m'
        ];

        $params = [
            'app_id' => $this->appId,
            'method' => 'alipay.trade.wap.pay',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->notifyUrl,
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE)
        ];

        $params['sign'] = $this->generateSign($params);

        // 生成重定向 URL 或 表单
        $url = $this->gateway . '?' . http_build_query($params);
        return [
            'success' => true,
            'pay_url' => $url,
            'order_no' => $orderNo
        ];
    }

    /**
     * 验证异步通知
     */
    public function verifyNotify($params)
    {
        if (empty($params['sign']))
            return false;

        $sign = $params['sign'];
        unset($params['sign']);
        unset($params['sign_type']);

        ksort($params);
        $content = "";
        foreach ($params as $k => $v) {
            $content .= ($content === "" ? "" : "&") . $k . "=" . stripslashes($v);
        }

        $res = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($this->publicKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        $result = (bool) openssl_verify($content, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);
        return $result;
    }

    /**
     * 生成签名
     */
    private function generateSign($params)
    {
        ksort($params);
        $content = "";
        foreach ($params as $k => $v) {
            if ($v !== "" && $v !== null) {
                $content .= ($content === "" ? "" : "&") . $k . "=" . $v;
            }
        }

        $res = "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($this->privateKey, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
        $sign = "";
        openssl_sign($content, $sign, $res, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }
}
