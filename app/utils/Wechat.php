<?php
/**
 * 微信工具类 - 处理 OAuth 登录和 JSAPI 签名
 */

class Wechat {
    private $appid;
    private $appsecret;

    public function __construct() {
        $this->appid = WECHAT_APPID;
        $this->appsecret = WECHAT_APPSECRET;
    }

    /**
     * 获取授权跳转地址
     */
    public function getAuthUrl($redirectUri, $state = 'STATE') {
        $url = "https://open.weixin.qq.com/connect/oauth2/authorize?";
        $params = [
            'appid' => $this->appid,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'snsapi_base',
            'state' => $state
        ];
        return $url . http_build_query($params) . "#wechat_redirect";
    }

    /**
     * 通过 code 获取 OpenID
     */
    public function getOpenidByCode($code) {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?";
        $params = [
            'appid' => $this->appid,
            'secret' => $this->appsecret,
            'code' => $code,
            'grant_type' => 'authorization_code'
        ];
        
        $response = file_get_contents($url . http_build_query($params));
        $data = json_decode($response, true);
        
        return $data['openid'] ?? null;
    }

    /**
     * 生成 JSAPI 支付参数
     */
    public function getJsApiParameters($prepayId) {
        $params = [
            'appId' => $this->appid,
            'timeStamp' => (string)time(),
            'nonceStr' => bin2hex(random_bytes(16)),
            'package' => "prepay_id=" . $prepayId,
            'signType' => 'MD5'
        ];
        
        $params['paySign'] = $this->generateSign($params);
        return $params;
    }

    private function generateSign($params) {
        ksort($params);
        $string = '';
        foreach ($params as $key => $value) {
            if ($key && $value !== '' && $key !== 'paySign') {
                $string .= $key . '=' . $value . '&';
            }
        }
        $string .= 'key=' . WECHAT_API_KEY;
        return strtoupper(md5($string));
    }
}
