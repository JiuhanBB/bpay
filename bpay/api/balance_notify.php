<?php
/**
 * BPay 余额变动通知接收接口
 * 接收Python工具上报的余额变动，匹配订单并发送异步通知
 */

require_once '../db.php';
require_once '../lib/Logger.php';

header('Content-Type: application/json');

/**
 * 内部通信签名验证函数
 */
function verifyInternalSign($params, $salt = 'qwer') {
    $receivedSign = $params['sign'] ?? '';
    unset($params['sign']);
    
    ksort($params);
    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $string .= $k . '=' . $v . '&';
        }
    }
    $string = rtrim($string, '&') . $salt;
    $calculatedSign = md5($string);
    
    return $receivedSign === $calculatedSign;
}

// 初始化日志
$logger = new Logger();

// 初始化数据库（使用正确的路径）
$db = new BPayDB('../bpay.db');

// 获取JSON输入
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

// 记录收到的通知
$requestData = [
    'body' => $jsonInput,
    'parsed' => $data,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

// 验证必需参数
if (empty($data)) {
    $error = '请求数据为空';
    $logger->logIncoming('balance_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'parse']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

// 验证内部通信签名
if (!verifyInternalSign($data)) {
    $error = '签名验证失败';
    $logger->logIncoming('balance_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'sign_verify']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

// 解析支付信息
$paymentType = $data['payment_type'] ?? '';
$changeAmount = $data['change_amount'] ?? '';
$currentBalance = $data['current_balance'] ?? '';
$changeTime = $data['change_time'] ?? '';

// 更新请求数据
$requestData['parsed'] = [
    'payment_type' => $paymentType,
    'change_amount' => $changeAmount,
    'current_balance' => $currentBalance,
    'change_time' => $changeTime
];

if (empty($changeAmount) || !is_numeric($changeAmount)) {
    $error = '变动金额为空或格式错误';
    $logger->logIncoming('balance_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'amount_check']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

// 将变动金额转换为元（假设Python传来的就是元）
$amount = floatval($changeAmount);

// 根据金额匹配待支付订单（允许小范围误差，比如0.01元）
$order = findOrderByAmount($db, $amount, $logger);

if (!$order) {
    // 调试：查询所有订单
    $allOrders = $db->getOrderList(10);
    $orderList = [];
    foreach ($allOrders as $o) {
        $orderList[] = [
            'trade_no' => $o['trade_no'], 
            'money' => $o['money'], 
            'status' => $o['status'],
            'money_type' => gettype($o['money'])
        ];
    }
    
    $error = '未找到匹配的订单，金额: ¥' . $amount;
    $logger->logIncoming('balance_notify', $requestData, [
        'error' => $error, 
        'code' => 'error',
        'debug' => [
            'search_amount' => $amount,
            'search_amount_type' => gettype($amount),
            'all_orders' => $orderList,
            'db_file' => $db->dbFile ?? 'unknown'
        ]
    ], ['step' => 'order_match']);
    echo json_encode([
        'code' => 'error', 
        'msg' => $error, 
        'debug' => [
            'search_amount' => $amount, 
            'all_orders' => $orderList,
            'db_file' => $db->dbFile ?? 'unknown'
        ]
    ]);
    exit;
}

// 更新请求数据记录匹配到的订单
$requestData['matched_order'] = [
    'trade_no' => $order['trade_no'],
    'out_trade_no' => $order['out_trade_no'],
    'money' => $order['money']
];

// 更新订单状态为已支付
$db->updateOrderStatus($order['trade_no'], 1);

// 记录收到的通知成功
$logger->logIncoming('balance_notify', $requestData, [
    'code' => 'success',
    'msg' => '订单匹配成功',
    'trade_no' => $order['trade_no']
], ['step' => 'order_matched', 'status_updated' => 1]);

// 发送异步通知给商户（包括测试订单）
$notifyResult = sendNotify($order, $db, $logger);

if ($notifyResult['success']) {
    // 标记为已通知（使用notify_status字段，不改变status）
    $db->updateNotifyStatus($order['trade_no'], 1);
    echo json_encode(['code' => 'success', 'msg' => '通知发送成功', 'trade_no' => $order['trade_no']]);
} else {
    echo json_encode(['code' => 'error', 'msg' => '通知发送失败: ' . $notifyResult['error'], 'trade_no' => $order['trade_no']]);
}

/**
 * 根据金额查找待支付订单
 * 允许小范围误差（±0.01元）
 */
function findOrderByAmount($db, $amount, $logger = null) {
    // 确保金额格式统一（保留2位小数）
    $amount = round(floatval($amount), 2);
    
    // 先精确匹配
    $order = $db->getPendingOrderByMoney($amount);
    if ($order) {
        if ($logger) {
            $logger->log('debug', '精确匹配成功', ['amount' => $amount, 'order' => $order['trade_no']]);
        }
        return $order;
    }
    
    // 如果没有精确匹配，尝试模糊匹配（±0.01元）
    $minAmount = $amount - 0.01;
    $maxAmount = $amount + 0.01;
    
    if ($logger) {
        $logger->log('debug', '尝试模糊匹配', ['amount' => $amount, 'min' => $minAmount, 'max' => $maxAmount]);
    }
    
    return $db->getPendingOrderByMoneyRange($minAmount, $maxAmount);
}

/**
 * 发送异步通知
 * 使用易支付格式和签名算法
 */
function sendNotify($order, $db, $logger) {
    $merchantKey = $db->getConfig('merchant_key');

    // 构建通知参数（易支付格式）
    $params = [
        'pid' => $order['merchant_id'],
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'type' => $order['type'],
        'name' => $order['name'],
        'money' => $order['original_money'] ?? $order['money'],
        'trade_status' => 'TRADE_SUCCESS',
        'sign_type' => 'MD5'
    ];

    // 生成易支付格式签名（参数ASCII排序 + 商户密钥，MD5）
    $params['sign'] = generateYiZhiFuSign($params, $merchantKey);
    
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
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 跟随重定向
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // 最多跟随5次重定向
    
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

/**
 * 生成易支付格式签名
 * 算法: MD5(参数按ASCII排序拼接 + 商户密钥)
 * 参考: https://yi-zhifu.com/doc#d8
 */
function generateYiZhiFuSign($params, $key) {
    // 按ASCII排序
    ksort($params);

    // 拼接成字符串
    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $k !== 'sign' && $k !== 'sign_type') {
            $string .= $k . '=' . $v . '&';
        }
    }

    // 去掉最后一个&并加上商户密钥（易支付格式不加盐值）
    $string = rtrim($string, '&') . $key;

    return md5($string);
}

/**
 * 生成签名（内部通信使用，带qwer盐值）
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
