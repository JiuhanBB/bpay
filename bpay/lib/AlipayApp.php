<?php

/**
 * BPay 支付宝应用支付类
 * 通过支付宝开放平台 API 生成付款二维码
 */
class AlipayApp {
    private $appId;
    private $privateKey;
    private $publicKey;
    private $gatewayUrl = 'https://openapi.alipay.com/gateway.do';

    public function __construct($appId, $privateKey, $publicKey = '') {
        $this->appId = trim((string) $appId);
        $this->privateKey = trim((string) $privateKey);
        $this->publicKey = trim((string) $publicKey);
    }

    /**
     * 生成支付宝付款链接/二维码
     *
     * @param string $orderId 订单号
     * @param float $amount 金额
     * @param string $subject 商品标题
     * @param string $body 商品描述
     * @return array 包含二维码内容、支付链接和错误信息
     */
    public function createPayLink($orderId, $amount, $subject = '', $body = '') {
        $subject = $this->normalizeText($subject, '订单支付-' . $orderId);
        $body = $this->normalizeText($body ?: $subject, $subject);

        $bizContent = [
            'out_trade_no' => $orderId,
            'total_amount' => number_format((float) $amount, 2, '.', ''),
            'subject' => $subject,
            'body' => $body,
            'product_code' => 'FACE_TO_FACE_PAYMENT',
            'timeout_express' => '5m'
        ];

        $result = $this->precreateOrder($bizContent);
        if (!empty($result['success'])) {
            return [
                'success' => true,
                'qr_code_content' => $result['qr_code'],
                'pay_url' => $result['qr_code'],
                'order_id' => $orderId,
                'amount' => $amount,
                'is_fallback' => false
            ];
        }

        return [
            'success' => false,
            'qr_code_content' => '',
            'pay_url' => '',
            'order_id' => $orderId,
            'amount' => $amount,
            'is_fallback' => false,
            'error_message' => $result['error_message'] ?? 'Alipay precreate failed',
            'error_code' => $result['error_code'] ?? '',
            'raw_response' => $result['response'] ?? []
        ];
    }

    /**
     * 调用支付宝预下单接口并提取二维码内容
     *
     * @param array $bizContent 业务参数
     * @return array
     */
    private function precreateOrder($bizContent) {
        $params = [
            'app_id' => $this->appId,
            'method' => 'alipay.trade.precreate',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];

        $sign = $this->generateSign($params);
        if ($sign === false) {
            return [
                'success' => false,
                'error_message' => 'Alipay private key is invalid'
            ];
        }
        $params['sign'] = $sign;

        $requestResult = $this->request($params);
        if (!$requestResult['success']) {
            return [
                'success' => false,
                'error_message' => $requestResult['error_message']
            ];
        }

        $result = json_decode($requestResult['body'], true);
        if (!is_array($result)) {
            return [
                'success' => false,
                'error_message' => 'Invalid response from Alipay gateway',
                'response' => $requestResult['body']
            ];
        }

        $responseData = $result['alipay_trade_precreate_response'] ?? null;
        if (!is_array($responseData)) {
            return [
                'success' => false,
                'error_message' => 'Missing precreate response payload',
                'response' => $result
            ];
        }

        if (($responseData['code'] ?? '') === '10000' && !empty($responseData['qr_code'])) {
            return [
                'success' => true,
                'qr_code' => $responseData['qr_code'],
                'out_trade_no' => $responseData['out_trade_no'] ?? ($bizContent['out_trade_no'] ?? '')
            ];
        }

        return [
            'success' => false,
            'error_code' => $responseData['sub_code'] ?? ($responseData['code'] ?? ''),
            'error_message' => $responseData['sub_msg'] ?? ($responseData['msg'] ?? 'Alipay precreate failed'),
            'response' => $responseData
        ];
    }

    /**
     * 向支付宝网关发送 POST 请求
     *
     * @param array $params 请求参数
     * @return array
     */
    private function request($params) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8'
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'error_message' => 'Failed to request Alipay gateway: ' . $curlError
            ];
        }

        return [
            'success' => true,
            'body' => $response
        ];
    }

    /**
     * 生成支付宝要求的 RSA2 签名
     *
     * @param array $params 待签名参数
     * @return string|false
     */
    private function generateSign($params) {
        $filteredParams = array_filter($params, function($value, $key) {
            return $value !== '' && $value !== null && $key !== 'sign';
        }, ARRAY_FILTER_USE_BOTH);

        ksort($filteredParams);

        $stringToSign = '';
        foreach ($filteredParams as $key => $value) {
            $stringToSign .= $key . '=' . $value . '&';
        }
        $stringToSign = rtrim($stringToSign, '&');

        $privateKeyResource = null;
        foreach ($this->buildPrivateKeyCandidates() as $privateKey) {
            $privateKeyResource = openssl_pkey_get_private($privateKey);
            if ($privateKeyResource !== false) {
                break;
            }
        }

        if ($privateKeyResource === false || $privateKeyResource === null) {
            return false;
        }

        $signature = '';
        $result = openssl_sign($stringToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);

        if (!$result) {
            return false;
        }

        return base64_encode($signature);
    }

    /**
     * 统一将文本转换为 UTF-8，避免支付宝侧出现乱码
     *
     * @param string $value 原始文本
     * @param string $fallback 兜底文本
     * @return string
     */
    private function normalizeText($value, $fallback = '') {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($value, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'ISO-8859-1'], true);
            if ($encoding !== false) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
                if ($converted !== false && $converted !== '') {
                    return trim($converted);
                }
            }
        }

        foreach (['GB18030', 'GBK', 'BIG5', 'ISO-8859-1'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);
            if ($converted !== false && $converted !== '') {
                return trim($converted);
            }
        }

        $cleanUtf8 = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($cleanUtf8 !== false && $cleanUtf8 !== '') {
            return trim($cleanUtf8);
        }

        return $fallback !== '' ? $fallback : $value;
    }

    /**
     * 兼容 PKCS8 和 PKCS1 两种私钥格式
     *
     * @return array
     */
    private function buildPrivateKeyCandidates() {
        $rawKey = trim($this->privateKey);
        if ($rawKey === '') {
            return [];
        }

        $candidates = [$rawKey];
        $keyBody = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $rawKey);

        if ($keyBody !== '') {
            $candidates[] = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($keyBody, 64, "\n") . "-----END PRIVATE KEY-----";
            $candidates[] = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($keyBody, 64, "\n") . "-----END RSA PRIVATE KEY-----";
        }

        return array_values(array_unique($candidates));
    }

    /**
     * 查询订单支付结果
     *
     * @param string $orderId 订单号
     * @return array|null
     */
    public function queryOrder($orderId) {
        $bizContent = [
            'out_trade_no' => $orderId
        ];

        $params = [
            'app_id' => $this->appId,
            'method' => 'alipay.trade.query',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];

        $sign = $this->generateSign($params);
        if ($sign === false) {
            return null;
        }
        $params['sign'] = $sign;

        $requestResult = $this->request($params);
        if (!$requestResult['success']) {
            return null;
        }

        $result = json_decode($requestResult['body'], true);
        $responseData = $result['alipay_trade_query_response'] ?? null;

        if (is_array($responseData) && ($responseData['code'] ?? '') === '10000') {
            return [
                'status' => $responseData['trade_status'],
                'buyer' => $responseData['buyer_user_id'] ?? '',
                'amount' => $responseData['total_amount'] ?? 0,
                'pay_time' => $responseData['send_pay_date'] ?? ''
            ];
        }

        return null;
    }
}
