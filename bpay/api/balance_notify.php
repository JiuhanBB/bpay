<?php
/**
 * Python 余额/收款通知接收接口。
 * 当前主链为：Python -> balance_notify.php -> 易支付格式异步通知商户。
 */

require_once '../db.php';
require_once '../lib/Logger.php';

header('Content-Type: application/json');

$logger = new Logger();
$db = new BPayDB(__DIR__ . '/../bpay.db');

$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

$requestData = [
    'body' => $jsonInput,
    'parsed' => $data,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

if (empty($data) || !is_array($data)) {
    $error = '请求数据为空';
    $logger->logIncoming('balance_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'parse']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

if (!verifyInternalSign($data)) {
    $error = '签名验证失败';
    $logger->logIncoming('balance_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'sign_verify']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

$paymentType = $data['payment_type'] ?? '';
$changeAmount = $data['change_amount'] ?? '';
$currentBalance = $data['current_balance'] ?? '';
$changeTime = $data['change_time'] ?? '';

$requestData['parsed'] = [
    'payment_type' => $paymentType,
    'change_amount' => $changeAmount,
    'current_balance' => $currentBalance,
    'change_time' => $changeTime
];

if ($changeAmount === '' || !is_numeric($changeAmount)) {
    $error = '变动金额为空或格式错误';
    $logger->logIncoming('balance_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'amount_check']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

$amount = round((float) $changeAmount, 2);
$order = findOrderByAmount($db, $amount, $logger);

if (!$order) {
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

    $error = '未找到匹配的订单，金额: ' . $amount;
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

$requestData['matched_order'] = [
    'trade_no' => $order['trade_no'],
    'out_trade_no' => $order['out_trade_no'],
    'money' => $order['money'],
    'original_money' => $order['original_money'] ?? $order['money']
];

$db->updateOrderStatus($order['trade_no'], 1);

$logger->logIncoming('balance_notify', $requestData, [
    'code' => 'success',
    'msg' => '订单匹配成功',
    'trade_no' => $order['trade_no']
], ['step' => 'order_matched', 'status_updated' => 1]);

$notifyResult = sendNotify($order, $db, $logger);

if ($notifyResult['success']) {
    $db->updateNotifyStatus($order['trade_no'], 1);
    echo json_encode(['code' => 'success', 'msg' => '通知发送成功', 'trade_no' => $order['trade_no']]);
} else {
    echo json_encode(['code' => 'error', 'msg' => '通知发送失败: ' . $notifyResult['error'], 'trade_no' => $order['trade_no']]);
}

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

    return $receivedSign === md5(rtrim($string, '&') . $salt);
}

function findOrderByAmount($db, $amount, $logger = null) {
    $amount = round((float) $amount, 2);

    $order = $db->getPendingOrderByMoney($amount);
    if ($order) {
        if ($logger) {
            $logger->log('debug', '精确匹配成功', ['amount' => $amount, 'order' => $order['trade_no']]);
        }
        return $order;
    }

    $minAmount = $amount - 0.01;
    $maxAmount = $amount + 0.01;
    if ($logger) {
        $logger->log('debug', '尝试模糊匹配', ['amount' => $amount, 'min' => $minAmount, 'max' => $maxAmount]);
    }

    return $db->getPendingOrderByMoneyRange($minAmount, $maxAmount);
}

function sendNotify($order, $db, $logger) {
    $merchantKey = $db->getConfig('merchant_key');
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
    $params['sign'] = generateYiZhiFuSign($params, $merchantKey);

    $url = $order['notify_url'] . '?' . http_build_query($params);
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
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $responseData = [
        'http_code' => $httpCode,
        'body' => $response,
        'curl_error' => $curlError ?: null
    ];

    $logger->logOutgoing('async_notify', $outgoingRequest, $responseData, [
        'trade_no' => $order['trade_no'],
        'notify_url' => $order['notify_url'],
        'success' => ($httpCode == 200 && !$curlError)
    ]);

    if ($curlError) {
        return ['success' => false, 'error' => 'CURL错误: ' . $curlError];
    }

    return ['success' => ($httpCode === 200), 'error' => $httpCode !== 200 ? 'HTTP ' . $httpCode : ''];
}

function generateYiZhiFuSign($params, $key) {
    ksort($params);

    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $k !== 'sign' && $k !== 'sign_type') {
            $string .= $k . '=' . $v . '&';
        }
    }

    return md5(rtrim($string, '&') . $key);
}
