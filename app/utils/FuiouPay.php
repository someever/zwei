<?php
/**
 * 富友聚合支付工具类
 */

class FuiouPay
{
    private $mchntCd;
    private $mchntKey;
    private $termId;
    private $notifyUrl;
    private $sandbox;
    private $baseUrl;

    public function __construct($options = [])
    {
        $this->mchntCd = $options['mchnt_cd'] ?? (defined('FUIOU_MCHNT_CD') ? FUIOU_MCHNT_CD : '');
        $this->mchntKey = $options['mchnt_key'] ?? (defined('FUIOU_MCHNT_KEY') ? FUIOU_MCHNT_KEY : '');
        $this->termId = $options['term_id'] ?? (defined('FUIOU_TERM_ID') ? FUIOU_TERM_ID : '88888888');
        $this->notifyUrl = $options['notify_url'] ?? (defined('FUIOU_NOTIFY_URL') ? FUIOU_NOTIFY_URL : '');
        $this->sandbox = array_key_exists('sandbox', $options)
            ? (bool) $options['sandbox']
            : (defined('FUIOU_SANDBOX') ? FUIOU_SANDBOX : true);
        $this->baseUrl = $this->sandbox
            ? 'https://aipaytest.fuioupay.com'
            : 'https://aipay-cloud.fuioupay.com';
    }

    /**
     * 创建主扫二维码订单。
     */
    public function createOrder($orderNo, $amount, $description, $orderType = 'WECHAT')
    {
        $payload = $this->buildPreCreatePayload(
            $orderNo,
            $amount,
            $description,
            $orderType,
            $this->getClientIp(),
            date('YmdHis'),
            $this->generateRandomStr()
        );

        $response = $this->postJson($this->baseUrl . '/aggregatePay/preCreate', $payload);
        $result = json_decode($response, true);

        if (!is_array($result)) {
            throw new Exception('富友支付响应解析失败');
        }

        if (($result['result_code'] ?? '') !== '000000') {
            throw new Exception('富友支付下单失败: ' . ($result['result_msg'] ?? '未知错误'));
        }

        if (!$this->verifyPreCreateResponse($result)) {
            throw new Exception('富友支付下单响应验签失败');
        }

        return array_merge($result, [
            'success' => true,
            'order_no' => $orderNo,
            'amount' => $amount,
            'pay_url' => $result['qr_code'] ?? '',
            'code_url' => $result['qr_code'] ?? ''
        ]);
    }

    /**
     * 创建公众号/服务窗订单，微信内 JSAPI 支付使用。
     */
    public function createJsapiOrder($orderNo, $amount, $description, $subAppid, $subOpenid)
    {
        $payload = $this->buildWxPreCreatePayload(
            $orderNo,
            $amount,
            $description,
            'JSAPI',
            $subAppid,
            $subOpenid,
            $this->getClientIp(),
            date('YmdHis'),
            $this->generateRandomStr()
        );

        $response = $this->postJson($this->baseUrl . '/aggregatePay/wxPreCreate', $payload);
        $result = json_decode($response, true);

        if (!is_array($result)) {
            throw new Exception('富友公众号支付响应解析失败');
        }

        if (($result['result_code'] ?? '') !== '000000') {
            throw new Exception('富友公众号支付下单失败: ' . ($result['result_msg'] ?? '未知错误'));
        }

        if (!$this->verifyPreCreateResponse($result)) {
            throw new Exception('富友公众号支付下单响应验签失败');
        }

        return array_merge($result, [
            'success' => true,
            'order_no' => $orderNo,
            'amount' => $amount,
            'jsapi_params' => $this->formatJsapiParams($result),
            'pay_url' => $result['qr_code'] ?? '',
            'code_url' => $result['qr_code'] ?? ''
        ]);
    }

    /**
     * 主动查询订单状态。trans_stat=SUCCESS 时会幂等更新本地订单。
     */
    public function queryOrder($orderNo, $orderType = 'WECHAT', $updateLocalOrder = true)
    {
        $payload = $this->buildCommonQueryPayload($orderNo, $orderType, $this->generateRandomStr());
        $response = $this->postJson($this->baseUrl . '/aggregatePay/commonQuery', $payload);
        $result = json_decode($response, true);

        if (!is_array($result)) {
            throw new Exception('富友订单查询响应解析失败');
        }

        if (($result['result_code'] ?? '') === '000000' && !$this->verifyQueryResponse($result)) {
            throw new Exception('富友订单查询响应验签失败');
        }

        if ($updateLocalOrder && ($result['result_code'] ?? '') === '000000' && ($result['trans_stat'] ?? '') === 'SUCCESS') {
            $transactionId = $result['transaction_id'] ?? ($result['reserved_channel_order_id'] ?? '');
            $orderModel = new Order();
            $orderModel->processPayment($orderNo, $transactionId);
        }

        return $result;
    }

    public function buildPreCreatePayloadForTest(
        $orderNo,
        $amount,
        $description,
        $orderType,
        $termIp,
        $txnBeginTs,
        $randomStr
    ) {
        return $this->buildPreCreatePayload(
            $orderNo,
            $amount,
            $description,
            $orderType,
            $termIp,
            $txnBeginTs,
            $randomStr
        );
    }

    public function buildWxPreCreatePayloadForTest(
        $orderNo,
        $amount,
        $description,
        $tradeType,
        $subAppid,
        $subOpenid,
        $termIp,
        $txnBeginTs,
        $randomStr
    ) {
        return $this->buildWxPreCreatePayload(
            $orderNo,
            $amount,
            $description,
            $tradeType,
            $subAppid,
            $subOpenid,
            $termIp,
            $txnBeginTs,
            $randomStr
        );
    }

    public function buildCommonQueryPayloadForTest($orderNo, $orderType, $randomStr)
    {
        $payload = $this->buildCommonQueryPayload($orderNo, $orderType, $randomStr);
        $payload['__test_endpoint'] = 'commonQuery';
        return $payload;
    }

    /**
     * 验证支付结果通知。
     */
    public function verifyNotify($data)
    {
        if (!is_array($data) || empty($data['sign'])) {
            return false;
        }

        $sign = $this->sign([
            $data['mchnt_cd'] ?? '',
            $data['mchnt_order_no'] ?? '',
            $data['settle_order_amt'] ?? '',
            $data['order_amt'] ?? '',
            $data['txn_fin_ts'] ?? '',
            $data['reserved_fy_settle_dt'] ?? '',
            $data['random_str'] ?? ''
        ]);

        if (!hash_equals($sign, strtolower($data['sign']))) {
            return false;
        }

        if (!empty($data['full_sign'])) {
            $fullSign = $this->sign([
                $data['result_code'] ?? '',
                $data['result_msg'] ?? '',
                $data['mchnt_cd'] ?? '',
                $data['mchnt_order_no'] ?? '',
                $data['settle_order_amt'] ?? '',
                $data['order_amt'] ?? '',
                $data['txn_fin_ts'] ?? '',
                $data['reserved_fy_settle_dt'] ?? '',
                $data['random_str'] ?? ''
            ]);

            return hash_equals($fullSign, strtolower($data['full_sign']));
        }

        return true;
    }

    public function verifyQueryResponse($data)
    {
        if (!is_array($data) || empty($data['sign'])) {
            return false;
        }

        $sign = $this->sign([
            $data['result_code'] ?? '',
            $data['result_msg'] ?? '',
            $data['mchnt_cd'] ?? '',
            $data['order_amt'] ?? '',
            $data['transaction_id'] ?? '',
            $data['mchnt_order_no'] ?? '',
            $data['reserved_fy_settle_dt'] ?? '',
            $data['trans_stat'] ?? '',
            $data['random_str'] ?? ''
        ]);

        return hash_equals($sign, strtolower($data['sign']));
    }

    /**
     * 处理富友支付回调，成功时返回 true，入口文件负责输出 1。
     */
    public function processNotify($data)
    {
        if (($data['result_code'] ?? '') !== '000000') {
            return false;
        }

        if (!$this->verifyNotify($data)) {
            return false;
        }

        $orderNo = $data['mchnt_order_no'] ?? '';
        if ($orderNo === '') {
            return false;
        }

        $transactionId = $data['transaction_id'] ?? ($data['reserved_channel_order_id'] ?? '');
        $orderModel = new Order();
        return $orderModel->processPayment($orderNo, $transactionId);
    }

    private function buildPreCreatePayload($orderNo, $amount, $description, $orderType, $termIp, $txnBeginTs, $randomStr)
    {
        if ($this->mchntCd === '' || $this->mchntKey === '') {
            throw new Exception('富友支付商户号或密钥未配置');
        }

        if ($this->notifyUrl === '') {
            throw new Exception('富友支付回调地址未配置');
        }

        $payload = [
            'version' => '1.0',
            'mchnt_cd' => $this->mchntCd,
            'random_str' => $randomStr,
            'order_type' => $orderType,
            'order_amt' => (string) intval(round($amount * 100)),
            'mchnt_order_no' => $orderNo,
            'txn_begin_ts' => $txnBeginTs,
            'goods_des' => mb_substr($description, 0, 128),
            'term_id' => $this->termId,
            'term_ip' => $termIp,
            'notify_url' => $this->notifyUrl
        ];

        $payload['sign'] = $this->sign([
            $payload['mchnt_cd'],
            $payload['order_type'],
            $payload['order_amt'],
            $payload['mchnt_order_no'],
            $payload['txn_begin_ts'],
            $payload['goods_des'],
            $payload['term_id'],
            $payload['term_ip'],
            $payload['notify_url'],
            $payload['random_str'],
            $payload['version']
        ]);

        return $payload;
    }

    private function buildWxPreCreatePayload(
        $orderNo,
        $amount,
        $description,
        $tradeType,
        $subAppid,
        $subOpenid,
        $termIp,
        $txnBeginTs,
        $randomStr
    ) {
        if ($this->mchntCd === '' || $this->mchntKey === '') {
            throw new Exception('富友支付商户号或密钥未配置');
        }

        if ($this->notifyUrl === '') {
            throw new Exception('富友支付回调地址未配置');
        }

        if ($tradeType === 'JSAPI' && ($subAppid === '' || $subOpenid === '')) {
            throw new Exception('富友公众号支付需要公众号 AppID 和用户 OpenID');
        }

        $payload = [
            'version' => '1.0',
            'mchnt_cd' => $this->mchntCd,
            'random_str' => $randomStr,
            'order_amt' => (string) intval(round($amount * 100)),
            'mchnt_order_no' => $orderNo,
            'txn_begin_ts' => $txnBeginTs,
            'goods_des' => mb_substr($description, 0, 128),
            'term_id' => $this->termId,
            'term_ip' => $termIp,
            'notify_url' => $this->notifyUrl,
            'trade_type' => $tradeType,
            'sub_appid' => $subAppid,
            'sub_openid' => $subOpenid
        ];

        $payload['sign'] = $this->sign([
            $payload['mchnt_cd'],
            $payload['trade_type'],
            $payload['order_amt'],
            $payload['mchnt_order_no'],
            $payload['txn_begin_ts'],
            $payload['goods_des'],
            $payload['term_id'],
            $payload['term_ip'],
            $payload['notify_url'],
            $payload['random_str'],
            $payload['version']
        ]);

        return $payload;
    }

    private function buildCommonQueryPayload($orderNo, $orderType, $randomStr)
    {
        if ($this->mchntCd === '' || $this->mchntKey === '') {
            throw new Exception('富友支付商户号或密钥未配置');
        }

        $payload = [
            'version' => '1.0',
            'mchnt_cd' => $this->mchntCd,
            'random_str' => $randomStr,
            'order_type' => $orderType,
            'mchnt_order_no' => $orderNo,
            'term_id' => $this->termId
        ];

        $payload['sign'] = $this->sign([
            $payload['mchnt_cd'],
            $payload['order_type'],
            $payload['mchnt_order_no'],
            $payload['term_id'],
            $payload['random_str'],
            $payload['version']
        ]);

        return $payload;
    }

    private function formatJsapiParams($result)
    {
        return [
            'appId' => $result['sdk_appid'] ?? '',
            'timeStamp' => (string) ($result['sdk_timestamp'] ?? ''),
            'nonceStr' => $result['sdk_noncestr'] ?? '',
            'package' => $result['sdk_package'] ?? '',
            'signType' => $result['sdk_signtype'] ?? '',
            'paySign' => $result['sdk_paysign'] ?? ''
        ];
    }

    private function verifyPreCreateResponse($data)
    {
        if (empty($data['sign'])) {
            return true;
        }

        $sign = $this->sign([
            $data['result_code'] ?? '',
            $data['result_msg'] ?? '',
            $data['mchnt_cd'] ?? '',
            $data['reserved_fy_trace_no'] ?? '',
            $data['random_str'] ?? ''
        ]);

        return hash_equals($sign, strtolower($data['sign']));
    }

    private function sign($fields)
    {
        $fields[] = $this->mchntKey;
        return md5(implode('|', $fields));
    }

    private function generateRandomStr()
    {
        return bin2hex(random_bytes(16));
    }

    private function getClientIp()
    {
        return '127.0.0.1';
    }

    private function postJson($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        error_log("Fuiou API Request - URL: {$url}");
        error_log("Fuiou API Request - Data: " . json_encode($data, JSON_UNESCAPED_UNICODE));

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            error_log("Fuiou API Error: " . $error);
            throw new Exception('富友支付请求失败: ' . $error);
        }

        error_log("Fuiou API Response: " . $response);

        return $response;
    }
}
