<?php
/**
 * BPay 支付宝应用支付类
 * 通过支付宝开放平台API生成付款二维码
 */

class AlipayApp {
    private $appId;
    private $privateKey;
    private $publicKey;
    private $gatewayUrl = 'https://openapi.alipay.com/gateway.do';
    
    public function __construct($appId, $privateKey, $publicKey = '') {
        $this->appId = $appId;
        $this->privateKey = $privateKey;
        $this->publicKey = $publicKey;
    }
    
    /**
     * 生成支付宝付款链接/二维码
     * 
     * @param string $orderId 订单号
     * @param float $amount 金额
     * @param string $subject 商品标题
     * @param string $body 商品描述
     * @return array 包含支付链接和二维码URL
     */
    public function createPayLink($orderId, $amount, $subject = '', $body = '') {
        // 构建scheme链接（用于生成二维码）
        $scheme = $this->buildSchemeUrl($orderId, $amount, $subject);
        
        // 生成二维码URL（使用支付宝的scheme转二维码服务）
        $qrcodeUrl = 'https://render.alipay.com/p/s/i?scheme=' . urlencode($scheme);
        
        // 备用：直接跳转链接
        $payUrl = 'https://ds.alipay.com/?from=mobilecodec&scheme=' . urlencode($scheme);
        
        return [
            'scheme' => $scheme,
            'qrcode_url' => $qrcodeUrl,
            'pay_url' => $payUrl,
            'order_id' => $orderId,
            'amount' => $amount
        ];
    }
    
    /**
     * 构建支付宝scheme链接
     * 使用转账到支付宝账户模式
     */
    private function buildSchemeUrl($orderId, $amount, $subject) {
        // 转账模式 scheme
        // alipays://platformapi/startapp?appId=20000116&actionType=toAccount&goBack=NO&amount=金额&userId=PID&memo=订单号
        
        // 获取PID（需要通过支付宝授权获取，这里使用配置中的PID）
        $pid = $this->getPidFromConfig();
        
        if (empty($pid)) {
            // 如果没有PID，使用通用的收款码模式
            return $this->buildGenericScheme($orderId, $amount, $subject);
        }
        
        // 转账模式
        $params = [
            'appId' => '20000116',
            'actionType' => 'toAccount',
            'goBack' => 'NO',
            'amount' => number_format($amount, 2, '.', ''),
            'userId' => $pid,
            'memo' => $orderId
        ];
        
        $scheme = 'alipays://platformapi/startapp?' . http_build_query($params);
        return $scheme;
    }
    
    /**
     * 构建通用收款scheme（不需要PID）
     * 使用支付宝收款码解析方式
     */
    private function buildGenericScheme($orderId, $amount, $subject) {
        // 使用支付宝的当面付或转账功能
        // 通过支付宝开放平台创建预创建订单
        
        $bizContent = [
            'out_trade_no' => $orderId,
            'total_amount' => number_format($amount, 2, '.', ''),
            'subject' => $subject ?: '订单支付-' . $orderId,
            'product_code' => 'QUICK_MSECURITY_PAY'
        ];
        
        // 调用支付宝预创建接口获取二维码
        $result = $this->precreateOrder($bizContent);
        
        if ($result && !empty($result['qr_code'])) {
            return $result['qr_code'];
        }
        
        // 如果API调用失败，返回备用scheme
        return 'alipays://platformapi/startapp?appId=20000056';
    }
    
    /**
     * 调用支付宝预创建接口
     */
    private function precreateOrder($bizContent) {
        $params = [
            'app_id' => $this->appId,
            'method' => 'alipay.trade.precreate',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode($bizContent)
        ];
        
        // 生成签名
        $params['sign'] = $this->generateSign($params);
        
        // 发送请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if (isset($result['alipay_trade_precreate_response'])) {
            $responseData = $result['alipay_trade_precreate_response'];
            if ($responseData['code'] == '10000') {
                return [
                    'qr_code' => $responseData['qr_code'],
                    'out_trade_no' => $responseData['out_trade_no']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 生成RSA2签名
     */
    private function generateSign($params) {
        // 过滤空值和sign字段
        $filteredParams = array_filter($params, function($v, $k) {
            return $v !== '' && $v !== null && $k !== 'sign';
        }, ARRAY_FILTER_USE_BOTH);
        
        // 按ASCII排序
        ksort($filteredParams);
        
        // 构建签名字符串
        $stringToSign = '';
        foreach ($filteredParams as $k => $v) {
            $stringToSign .= $k . '=' . $v . '&';
        }
        $stringToSign = rtrim($stringToSign, '&');
        
        // RSA签名
        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" . 
            chunk_split($this->privateKey, 64, "\n") . 
            "-----END RSA PRIVATE KEY-----";
        
        openssl_sign($stringToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }
    
    /**
     * 从配置获取PID
     */
    private function getPidFromConfig() {
        // 尝试从数据库或配置文件获取PID
        global $db;
        if (isset($db)) {
            return $db->getConfig('alipay_pid');
        }
        return '';
    }
    
    /**
     * 查询订单状态
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
            'biz_content' => json_encode($bizContent)
        ];
        
        $params['sign'] = $this->generateSign($params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if (isset($result['alipay_trade_query_response'])) {
            $responseData = $result['alipay_trade_query_response'];
            if ($responseData['code'] == '10000') {
                return [
                    'status' => $responseData['trade_status'],
                    'buyer' => $responseData['buyer_user_id'] ?? '',
                    'amount' => $responseData['total_amount'] ?? 0,
                    'pay_time' => $responseData['send_pay_date'] ?? ''
                ];
            }
        }
        
        return null;
    }
}
