<?php

/**
 * BPay 微信JSAPI支付类
 * 用于在微信浏览器内直接调起微信支付
 */
class WechatJsapi {
    private $appid;
    private $appsecret;
    private $mchid;
    private $key;
    private $apiUrl = 'https://api.mch.weixin.qq.com';

    public function __construct($appid, $appsecret, $mchid, $key) {
        $this->appid = trim((string) $appid);
        $this->appsecret = trim((string) $appsecret);
        $this->mchid = trim((string) $mchid);
        $this->key = trim((string) $key);
    }

    /**
     * 生成微信OAuth授权URL
     */
    public function getOauthUrl($redirectUri, $state = '') {
        $redirectUri = urlencode($redirectUri);
        return "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$this->appid}&redirect_uri={$redirectUri}&response_type=code&scope=snsapi_base&state={$state}#wechat_redirect";
    }

    /**
     * 通过code获取openid
     */
    public function getOpenId($code) {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->appid}&secret={$this->appsecret}&code={$code}&grant_type=authorization_code";

        $result = $this->httpGet($url);
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error_message']];
        }

        $data = json_decode($result['body'], true);
        if (!empty($data['openid'])) {
            return ['success' => true, 'openid' => $data['openid']];
        }

        return ['success' => false, 'error' => $data['errmsg'] ?? '获取openid失败'];
    }

    /**
     * 统一下单
     */
    public function createUnifiedOrder($openid, $outTradeNo, $totalFee, $notifyUrl, $body, $spbillCreateIp = '') {
        if ($spbillCreateIp === '') {
            $spbillCreateIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }

        $params = [
            'appid' => $this->appid,
            'mch_id' => $this->mchid,
            'nonce_str' => $this->getNonceStr(),
            'body' => $body,
            'out_trade_no' => $outTradeNo,
            'total_fee' => intval(round($totalFee * 100)),
            'spbill_create_ip' => $spbillCreateIp,
            'notify_url' => $notifyUrl,
            'trade_type' => 'JSAPI',
            'openid' => $openid,
        ];

        $params['sign'] = $this->generateSign($params);

        $xml = $this->arrayToXml($params);
        $url = $this->apiUrl . '/pay/unifiedorder';

        $result = $this->postXml($url, $xml);
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error_message']];
        }

        $response = $this->xmlToArray($result['body']);
        if ($response['return_code'] !== 'SUCCESS') {
            return ['success' => false, 'error' => $response['return_msg'] ?? '微信统一下单失败'];
        }

        if ($response['result_code'] !== 'SUCCESS') {
            return ['success' => false, 'error' => $response['err_code_des'] ?? '微信统一下单失败'];
        }

        return [
            'success' => true,
            'prepay_id' => $response['prepay_id'],
            'nonce_str' => $params['nonce_str'],
        ];
    }

    /**
     * 生成JSAPI调起支付参数
     */
    public function getJsapiParameters($prepayId) {
        $params = [
            'appId' => $this->appid,
            'timeStamp' => (string) time(),
            'nonceStr' => $this->getNonceStr(),
            'package' => 'prepay_id=' . $prepayId,
            'signType' => 'MD5',
        ];

        $params['paySign'] = $this->generateSign($params);

        return $params;
    }

    /**
     * 查询订单支付状态
     */
    public function queryOrder($outTradeNo) {
        $params = [
            'appid' => $this->appid,
            'mch_id' => $this->mchid,
            'out_trade_no' => $outTradeNo,
            'nonce_str' => $this->getNonceStr(),
        ];

        $params['sign'] = $this->generateSign($params);
        $xml = $this->arrayToXml($params);
        $url = $this->apiUrl . '/pay/orderquery';

        $result = $this->postXml($url, $xml);
        if (!$result['success']) {
            return ['success' => false];
        }

        $response = $this->xmlToArray($result['body']);
        if ($response['return_code'] !== 'SUCCESS' || $response['result_code'] !== 'SUCCESS') {
            return ['success' => false];
        }

        return [
            'success' => true,
            'trade_state' => $response['trade_state'],
            'total_fee' => $response['total_fee'] ?? 0,
            'transaction_id' => $response['transaction_id'] ?? '',
            'pay_time' => $response['time_end'] ?? '',
        ];
    }

    /**
     * 生成MD5签名
     */
    private function generateSign($params) {
        ksort($params);
        $string = '';
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null && $k !== 'sign') {
                $string .= $k . '=' . $v . '&';
            }
        }
        return strtoupper(md5(rtrim($string, '&') . '&key=' . $this->key));
    }

    /**
     * 生成随机字符串
     */
    private function getNonceStr($length = 32) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * 数组转XML
     */
    private function arrayToXml($arr) {
        $xml = '<xml>';
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<{$key}>{$val}</{$key}>";
            } else {
                $xml .= "<{$key}><![CDATA[{$val}]]></{$key}>";
            }
        }
        $xml .= '</xml>';
        return $xml;
    }

    /**
     * XML转数组
     */
    private function xmlToArray($xml) {
        libxml_disable_entity_loader(true);
        $result = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $result ?: [];
    }

    /**
     * GET请求
     */
    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error_message' => $curlError];
        }

        return ['success' => true, 'body' => $response];
    }

    /**
     * POST XML请求
     */
    private function postXml($url, $xml) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/xml; charset=utf-8',
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error_message' => $curlError];
        }

        return ['success' => true, 'body' => $response];
    }
}
