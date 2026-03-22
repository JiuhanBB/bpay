<?php
/**
 * BPay 订单查询接口
 * 用于支付页面轮询检查订单状态
 */

require_once '../db.php';
require_once '../lib/AlipayApp.php';

header('Content-Type: application/json');

$tradeNo = $_GET['trade_no'] ?? '';

if (empty($tradeNo)) {
    echo json_encode(['code' => 'error', 'msg' => '订单号不能为空']);
    exit;
}

$db = new BPayDB('../bpay.db');

$order = $db->getOrderByTradeNo($tradeNo);

if (!$order) {
    echo json_encode(['code' => 'error', 'msg' => '订单不存在']);
    exit;
}

// 如果订单已支付或已取消，直接返回
if ($order['status'] == 1) {
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

// 如果订单已取消
if ($order['status'] == 2) {
    echo json_encode([
        'code' => 'success',
        'status' => 2,
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'money' => $order['money']
    ]);
    exit;
}

// 如果是支付宝订单且配置了当面付，主动查询支付宝接口
if ($order['type'] == 'alipay') {
    $appId = $db->getConfig('alipay_app_id');
    $privateKey = $db->getConfig('alipay_private_key');

    if (!empty($appId) && !empty($privateKey)) {
        $alipayApp = new AlipayApp($appId, $privateKey);
        $alipayResult = $alipayApp->queryOrder($order['out_trade_no']);

        if ($alipayResult && in_array($alipayResult['status'], ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            // 支付成功，更新订单状态
            $db->updateOrderStatus($order['trade_no'], 1);

            // 发送异步通知
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

// 返回未支付状态
echo json_encode([
    'code' => 'success',
    'status' => 0,
    'trade_no' => $order['trade_no'],
    'out_trade_no' => $order['out_trade_no'],
    'money' => $order['money']
]);

/**
 * 发送异步通知
 */
function sendNotify($db, $order) {
    $notifyUrl = $order['notify_url'];
    if (empty($notifyUrl)) {
        return;
    }

    $merchantKey = $db->getConfig('merchant_key');

    $notifyData = [
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'money' => $order['money'],
        'type' => $order['type'],
        'status' => 1,
        'time' => time()
    ];

    // 生成签名
    ksort($notifyData);
    $signStr = http_build_query($notifyData) . '&key=' . $merchantKey;
    $notifyData['sign'] = md5($signStr);

    // 异步发送通知
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $notifyUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($notifyData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);
}
