<?php
/**
 * BPay 订单查询接口
 * 支付页轮询使用；若支付宝当面付已成功，则补发易支付格式异步通知。
 */

require_once '../db.php';
require_once '../lib/AlipayApp.php';

header('Content-Type: application/json');

$tradeNo = $_GET['trade_no'] ?? '';
if ($tradeNo === '') {
    echo json_encode(['code' => 'error', 'msg' => '订单号不能为空']);
    exit;
}

$db = new BPayDB(__DIR__ . '/../bpay.db');
$order = $db->getOrderByTradeNo($tradeNo);

if (!$order) {
    echo json_encode(['code' => 'error', 'msg' => '订单不存在']);
    exit;
}

if ((int) $order['status'] === 1) {
    echo json_encode([
        'code' => 'success',
        'status' => 1,
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'money' => $order['money'],
        'pay_time' => $order['pay_time']
    ]);
    exit;
}

if ((int) $order['status'] === 2) {
    echo json_encode([
        'code' => 'success',
        'status' => 2,
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'money' => $order['money']
    ]);
    exit;
}

if ($order['type'] === 'alipay') {
    $appId = $db->getConfig('alipay_app_id');
    $privateKey = $db->getConfig('alipay_private_key');

    if (!empty($appId) && !empty($privateKey)) {
        $alipayApp = new AlipayApp($appId, $privateKey);
        $alipayResult = $alipayApp->queryOrder($order['out_trade_no']);

        if ($alipayResult && in_array($alipayResult['status'], ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            $db->updateOrderStatus($order['trade_no'], 1);
            sendNotify($db, $order);

            echo json_encode([
                'code' => 'success',
                'status' => 1,
                'trade_no' => $order['trade_no'],
                'out_trade_no' => $order['out_trade_no'],
                'money' => $order['money'],
                'pay_time' => date('Y-m-d H:i:s')
            ]);
            exit;
        }
    }
}

echo json_encode([
    'code' => 'success',
    'status' => 0,
    'trade_no' => $order['trade_no'],
    'out_trade_no' => $order['out_trade_no'],
    'money' => $order['money']
]);

function sendNotify($db, $order) {
    $notifyUrl = $order['notify_url'];
    if (empty($notifyUrl)) {
        return ['success' => false, 'error' => 'notify_url is empty'];
    }

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

    $url = $notifyUrl . '?' . http_build_query($params);

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

    if ($curlError) {
        return ['success' => false, 'error' => 'CURL错误: ' . $curlError];
    }

    if ($httpCode === 200) {
        $db->updateNotifyStatus($order['trade_no'], 1);
    }

    return [
        'success' => ($httpCode === 200),
        'error' => $httpCode !== 200 ? 'HTTP ' . $httpCode : '',
        'body' => $response
    ];
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
