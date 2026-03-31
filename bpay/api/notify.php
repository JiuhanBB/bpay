<?php
/**
 * BPay Python工具通知接收接口 (MD5签名版本)
 * 接收带签名的收款通知，验证签名后匹配订单并发送异步通知
 */

require_once '../db.php';
require_once '../lib/Logger.php';

header('Content-Type: application/json');

// 初始化日志
$logger = new Logger();

// 初始化数据库（使用正确的路径）
$db = new BPayDB('../bpay.db');

// 获取通信密钥
$notifyKey = $db->getConfig('notify_key');
if (empty($notifyKey)) {
    echo json_encode(['code' => 'error', 'msg' => '通信密钥未配置']);
    exit;
}

// 获取参数 (支持GET和POST)
$params = array_merge($_GET, $_POST);

// 记录收到的通知
$requestData = [
    'params' => $params,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

// 验证必需参数
if (empty($params['sign'])) {
    $error = '缺少签名参数';
    $logger->logIncoming('python_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'sign_check']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

// 获取签名并移除
$sign = $params['sign'];
unset($params['sign']);

// 验证签名
$calculatedSign = generateSign($params, $notifyKey);
if ($sign !== $calculatedSign) {
    $error = '签名验证失败';
    $logger->logIncoming('python_notify', $requestData, [
        'error' => $error,
        'code' => 'error',
        'calculated_sign' => $calculatedSign
    ], ['step' => 'sign_verify']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

// 解析支付信息
$paymentType = $params['payment_type'] ?? '';
$amount = $params['amount'] ?? '';
$paymentTime = $params['payment_time'] ?? '';
$payer = $params['payer'] ?? '';

// 更新请求数据
$requestData['parsed'] = [
    'payment_type' => $paymentType,
    'amount' => $amount,
    'payment_time' => $paymentTime,
    'payer' => $payer
];

if (empty($amount)) {
    $error = '支付金额为空';
    $logger->logIncoming('python_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'amount_check']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

// 根据金额匹配待支付订单
$order = $db->getPendingOrderByMoney($amount);
if (!$order) {
    $error = '未找到匹配的订单';
    $logger->logIncoming('python_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'order_match']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

// 重新查询订单获取完整数据（包括original_money）
$order = $db->getOrderByTradeNo($order['trade_no']);

// 确保 original_money 存在
$originalMoney = $order['original_money'] ?? $order['money'];

// 更新请求数据记录匹配到的订单
$requestData['matched_order'] = [
    'trade_no' => $order['trade_no'],
    'out_trade_no' => $order['out_trade_no'],
    'money' => $order['money'],
    'original_money' => $originalMoney
];

// 更新订单状态
$db->updateOrderStatus($order['trade_no'], 1);

// 记录收到的通知成功
$logger->logIncoming('python_notify', $requestData, [
    'code' => 'success',
    'msg' => '订单匹配成功',
    'trade_no' => $order['trade_no'],
    'original_money' => $originalMoney
], ['step' => 'order_matched', 'status_updated' => 1]);

// 发送异步通知
$notifyResult = sendNotify($order, $db, $logger);

if ($notifyResult['success']) {
    // 标记为已通知
    $db->updateOrderStatus($order['trade_no'], 2);
    echo json_encode(['code' => 'success', 'msg' => '通知发送成功']);
} else {
    echo json_encode(['code' => 'error', 'msg' => '通知发送失败: ' . $notifyResult['error']]);
}

/**
 * 生成签名
 * 算法: MD5(参数按ASCII排序拼接 + 密钥 + 盐值)
 */
function generateSign($params, $key) {
    // 按ASCII排序
    ksort($params);
    
    // 拼接成字符串
    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $k !== 'sign') {
            $string .= $k . '=' . $v . '&';
        }
    }
    
    // 去掉最后一个&并加上密钥和盐值
    $string = rtrim($string, '&') . $key . 'qwer';
    
    return md5($string);
}

/**
 * 发送异步通知
 */
function sendNotify($order, $db, $logger) {
    $merchantKey = $db->getConfig('merchant_key');
    
    // 构建通知参数（使用原始金额，如果不存在则使用微调金额，兼容旧订单）
    $notifyMoney = $order['original_money'] ?? $order['money'];
    
    $params = [
        'pid' => $order['merchant_id'],
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'type' => $order['type'],
        'name' => $order['name'],
        'money' => $notifyMoney,  // 使用商户原始金额
        'trade_status' => 'TRADE_SUCCESS',
        'sign_type' => 'MD5'
    ];
    
    // 生成签名
    $params['sign'] = generateSign($params, $merchantKey);
    
    // 发送GET请求
    $url = $order['notify_url'] . '?' . http_build_query($params);
    
    // 记录发送的请求
    $outgoingRequest = [
        'url' => $order['notify_url'],
        'method' => 'GET',
        'params' => $params,
        'full_url' => $url,
        'trade_no' => $order['trade_no']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $responseData = [
        'http_code' => $httpCode,
        'body' => $response,
        'curl_error' => $curlError ?: null
    ];
    
    // 记录发送出去的异步通知
    $logger->logOutgoing('async_notify', $outgoingRequest, $responseData, [
        'trade_no' => $order['trade_no'],
        'notify_url' => $order['notify_url'],
        'success' => ($httpCode == 200 && !$curlError)
    ]);
    
    if ($curlError) {
        return ['success' => false, 'error' => 'CURL错误: ' . $curlError];
    }
    
    return ['success' => ($httpCode == 200), 'error' => $httpCode != 200 ? 'HTTP ' . $httpCode : ''];
}
