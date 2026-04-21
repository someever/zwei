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
    private $returnUrl;
    private $gateway;

    public function __construct()
    {
        $this->appId = defined('ALIPAY_APPID') ? ALIPAY_APPID : '';
        $this->privateKey = defined('ALIPAY_PRIVATE_KEY') ? ALIPAY_PRIVATE_KEY : '';
        $this->publicKey = defined('ALIPAY_PUBLIC_KEY') ? ALIPAY_PUBLIC_KEY : '';
        $this->notifyUrl = defined('ALIPAY_NOTIFY_URL') ? ALIPAY_NOTIFY_URL : '';
        $this->returnUrl = defined('ALIPAY_RETURN_URL') ? ALIPAY_RETURN_URL : '';

        $isSandbox = defined('ALIPAY_SANDBOX') && ALIPAY_SANDBOX;
        $this->gateway = $isSandbox
            ? "https://openapi-sandbox.dl.alipaydev.com/gateway.do"
            : "https://openapi.alipay.com/gateway.do";
    }

    /**
     * 创建支付订单 (WAP/手机网站)
     */
    public function createOrder($orderNo, $amount, $description, $tradeType = 'WAP')
    {
        $bizContent = [
            'out_trade_no' => $orderNo,
            'total_amount' => number_format($amount, 2, '.', ''),
            'subject' => mb_substr($description, 0, 100),
            'product_code' => $tradeType === 'PAGE' ? 'FAST_INSTANT_TRADE_PAY' : 'QUICK_WAP_WAY',
            'timeout_express' => '30m'
        ];

        $params = [
            'app_id' => $this->appId,
            'method' => $tradeType === 'PAGE' ? 'alipay.trade.page.pay' : 'alipay.trade.wap.pay',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->notifyUrl,
        ];

        // 处理 AES 内容加密
        $bizContentJson = json_encode($bizContent, JSON_UNESCAPED_UNICODE);
        $aesKey = defined('ALIPAY_AES_KEY') ? ALIPAY_AES_KEY : '';
        if ($aesKey) {
            $params['encrypt_type'] = 'AES';
            $params['biz_content'] = openssl_encrypt(
                $bizContentJson,
                'AES-128-CBC',
                base64_decode($aesKey),
                0,
                str_repeat("\0", 16)
            );
        } else {
            $params['biz_content'] = $bizContentJson;
        }

        // return_url：支付完成后同步跳回（WAP 支付必须配置）
        if ($this->returnUrl) {
            $params['return_url'] = $this->returnUrl;
        }

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
        $signType = isset($params['sign_type']) ? $params['sign_type'] : '';
        unset($params['sign']);
        unset($params['sign_type']);

        ksort($params);
        $content = "";
        foreach ($params as $k => $v) {
            $content .= ($content === "" ? "" : "&") . $k . "=" . stripslashes($v);
        }

        $res = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($this->publicKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        $result = (bool) openssl_verify($content, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);

        // 如果验签成功且存在 AES 加密内容，则解密
        if ($result && isset($params['encrypt_type']) && $params['encrypt_type'] === 'AES' && isset($params['biz_content'])) {
            $aesKey = defined('ALIPAY_AES_KEY') ? ALIPAY_AES_KEY : '';
            if ($aesKey) {
                $decrypted = openssl_decrypt(
                    $params['biz_content'],
                    'AES-128-CBC',
                    base64_decode($aesKey),
                    0,
                    str_repeat("\0", 16)
                );
                if ($decrypted) {
                    $bizParams = json_decode($decrypted, true);
                    if (is_array($bizParams)) {
                        // 将解密后的业务参数合入，方便调用方使用
                        $params = array_merge($params, $bizParams);
                        // 或者直接将整个解密后的数组作为返回的一部分，但目前返回值是 bool
                        // 为了兼容，可以通过引用传递或额外的方法获取，但暂不改变签名，先放这里备用
                        // $_POST 也能改变，但最佳实践是调用方自己解密或这里返回特殊结构
                    }
                }
            }
        }

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
