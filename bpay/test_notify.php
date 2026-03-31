<?php
/**
 * BPay 测试订单异步通知接收页面
 * 用于接收测试订单的支付结果通知（易支付格式）
 */

require_once 'db.php';
require_once 'lib/Logger.php';

$logger = new Logger();
$db = new BPayDB('bpay.db');

// 获取通知数据（易支付使用GET方式）
$params = $_GET;

// 记录收到的通知
$logger->logIncoming('test_notify', [
    'params' => $params,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
], ['code' => 'success', 'msg' => '测试订单通知接收成功'], ['step' => 'receive']);

// 验证必需参数
$required = ['pid', 'trade_no', 'out_trade_no', 'type', 'name', 'money', 'trade_status', 'sign'];
foreach ($required as $field) {
    if (empty($params[$field])) {
        $logger->log('test_notify', '缺少必要参数', ['field' => $field]);
        echo 'fail';
        exit;
    }
}

// 获取商户密钥
$merchantKey = $db->getConfig('merchant_key');
if (!$merchantKey) {
    $logger->log('test_notify', '商户密钥未配置');
    echo 'fail';
    exit;
}

// 验证签名
$receivedSign = $params['sign'];
unset($params['sign']);
if (isset($params['sign_type'])) {
    unset($params['sign_type']);
}

// 生成签名（易支付算法）
ksort($params);
$string = '';
foreach ($params as $k => $v) {
    if ($v !== '' && $v !== null) {
        $string .= $k . '=' . $v . '&';
    }
}
$string = rtrim($string, '&') . $merchantKey;
$calculatedSign = md5($string);

if ($receivedSign !== $calculatedSign) {
    $logger->log('test_notify', '签名验证失败', [
        'received_sign' => $receivedSign,
        'calculated_sign' => $calculatedSign,
        'string' => $string
    ]);
    echo 'fail';
    exit;
}

// 验证商户ID
if ($params['pid'] !== $db->getConfig('merchant_id')) {
    $logger->log('test_notify', '商户ID错误', ['pid' => $params['pid']]);
    echo 'fail';
    exit;
}

// 验证订单是否存在
$order = $db->getOrderByTradeNo($params['trade_no']);
if (!$order) {
    $logger->log('test_notify', '订单不存在', ['trade_no' => $params['trade_no']]);
    echo 'fail';
    exit;
}

// 验证金额是否匹配（使用原始金额验证）
$verifyMoney = $order['original_money'] ?? $order['money'];
if (floatval($verifyMoney) != floatval($params['money'])) {
    $logger->log('test_notify', '金额不匹配', [
        'order_money' => $order['money'],
        'original_money' => $order['original_money'] ?? 'N/A',
        'verify_money' => $verifyMoney,
        'notify_money' => $params['money']
    ]);
    echo 'fail';
    exit;
}

// 验证支付状态
if ($params['trade_status'] !== 'TRADE_SUCCESS') {
    $logger->log('test_notify', '支付状态错误', ['trade_status' => $params['trade_status']]);
    echo 'fail';
    exit;
}

// 记录成功的通知
$logger->log('test_notify', '异步通知验证成功', [
    'trade_no' => $params['trade_no'],
    'out_trade_no' => $params['out_trade_no'],
    'money' => $params['money']
]);

// 返回success（必须返回纯文本success）
echo 'success';
