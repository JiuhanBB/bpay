<?php
/**
 * 旧版 Python 通知接收接口。
 * 当前主流程已迁移到 balance_notify.php，这里保留兼容。
 */

require_once '../db.php';
require_once '../lib/Logger.php';

header('Content-Type: application/json');

$logger = new Logger();
$db = new BPayDB(__DIR__ . '/../bpay.db');
$notifyKey = $db->getConfig('notify_key');

$params = array_merge($_GET, $_POST);
$requestData = [
    'params' => $params,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

if (empty($params['sign'])) {
    $error = '缺少签名参数';
    $logger->logIncoming('python_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'sign_check']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

$sign = $params['sign'];
unset($params['sign']);

if (!verifyInternalSign($params, $sign, $notifyKey)) {
    $error = '签名验证失败';
    $logger->logIncoming('python_notify', $requestData, [
        'error' => $error,
        'code' => 'error',
        'calculated_sign' => generateInternalSign($params)
    ], ['step' => 'sign_verify']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

$paymentType = $params['payment_type'] ?? '';
$amount = $params['amount'] ?? '';
$paymentTime = $params['payment_time'] ?? '';
$payer = $params['payer'] ?? '';

$requestData['parsed'] = [
    'payment_type' => $paymentType,
    'amount' => $amount,
    'payment_time' => $paymentTime,
    'payer' => $payer
];

if ($amount === '') {
    $error = '支付金额为空';
    $logger->logIncoming('python_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'amount_check']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

$order = $db->getPendingOrderByMoney($amount);
if (!$order) {
    $error = '未找到匹配的订单';
    $logger->logIncoming('python_notify', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'order_match']);
    echo json_encode(['code' => 'error', 'msg' => $error]);
    exit;
}

$order = $db->getOrderByTradeNo($order['trade_no']);
$originalMoney = $order['original_money'] ?? $order['money'];

$requestData['matched_order'] = [
    'trade_no' => $order['trade_no'],
    'out_trade_no' => $order['out_trade_no'],
    'money' => $order['money'],
    'original_money' => $originalMoney
];

$db->updateOrderStatus($order['trade_no'], 1);

$logger->logIncoming('python_notify', $requestData, [
    'code' => 'success',
    'msg' => '订单匹配成功',
    'trade_no' => $order['trade_no'],
    'original_money' => $originalMoney
], ['step' => 'order_matched', 'status_updated' => 1]);

$notifyResult = sendNotify($order, $db, $logger);

if ($notifyResult['success']) {
    $db->updateNotifyStatus($order['trade_no'], 1);
    echo json_encode(['code' => 'success', 'msg' => '通知发送成功']);
} else {
    echo json_encode(['code' => 'error', 'msg' => '通知发送失败: ' . $notifyResult['error']]);
}

function verifyInternalSign($params, $receivedSign, $legacyKey = '') {
    if ($receivedSign === generateInternalSign($params)) {
        return true;
    }

    if ($legacyKey !== '' && $receivedSign === generateLegacyInternalSign($params, $legacyKey)) {
        return true;
    }

    return false;
}

function generateInternalSign($params) {
    ksort($params);

    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $k !== 'sign') {
            $string .= $k . '=' . $v . '&';
        }
    }

    return md5(rtrim($string, '&') . 'qwer');
}

function generateLegacyInternalSign($params, $key) {
    ksort($params);

    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null && $k !== 'sign') {
            $string .= $k . '=' . $v . '&';
        }
    }

    return md5(rtrim($string, '&') . $key . 'qwer');
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

function sendNotify($order, $db, $logger) {
    $merchantKey = $db->getConfig('merchant_key');
    $notifyMoney = $order['original_money'] ?? $order['money'];

    $params = [
        'pid' => $order['merchant_id'],
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'type' => $order['type'],
        'name' => $order['name'],
        'money' => $notifyMoney,
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

    return ['success' => ($httpCode == 200), 'error' => $httpCode != 200 ? 'HTTP ' . $httpCode : ''];
}
