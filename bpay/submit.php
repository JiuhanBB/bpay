<?php
/**
 * BPay 页面跳转支付接口
 * 参考易支付文档: https://yi-zhifu.com/doc#d2
 */

require_once 'db.php';
require_once 'lib/Logger.php';

// 初始化日志
$logger = new Logger();

// 获取参数 (支持GET和POST)
$params = array_merge($_GET, $_POST);

// 记录收到的请求
$requestData = [
    'params' => $params,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

// 必需参数
$required = ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'sign'];
foreach ($required as $field) {
    if (empty($params[$field])) {
        $error = "缺少必要参数: {$field}";
        // 记录错误日志
        $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'validation']);
        jsonError($error);
    }
}

// 初始化数据库
$db = new BPayDB();

// 验证商户ID
if ($params['pid'] !== '1000') {
    $error = "商户ID错误";
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'merchant_check']);
    jsonError($error);
}

// 获取商户密钥
$merchantKey = $db->getConfig('merchant_key');
if (!$merchantKey) {
    $error = "商户配置错误";
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'config_check']);
    jsonError($error);
}

// 验证签名
$sign = $params['sign'];
unset($params['sign']);
if (isset($params['sign_type'])) {
    unset($params['sign_type']);
}

$calculatedSign = generateSign($params, $merchantKey);
if ($sign !== $calculatedSign) {
    $error = "签名验证失败";
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'sign_check', 'calculated_sign' => $calculatedSign]);
    jsonError($error);
}

// 验证金额
if (!is_numeric($params['money']) || $params['money'] <= 0) {
    $error = "金额格式错误";
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'money_check']);
    jsonError($error);
}

// 验证支付方式
if (!in_array($params['type'], ['alipay', 'wxpay'])) {
    $error = "支付方式错误";
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'type_check']);
    jsonError($error);
}

// 生成平台订单号
$tradeNo = $db->generateTradeNo();

// 获取唯一金额（自动微调避免重复）
$baseMoney = floatval($params['money']);
$finalMoney = $db->getUniqueMoney($baseMoney);

// 创建订单
$orderData = [
    'trade_no' => $tradeNo,
    'out_trade_no' => $params['out_trade_no'],
    'merchant_id' => $params['pid'],
    'name' => $params['name'],
    'money' => $finalMoney,
    'type' => $params['type'],
    'notify_url' => $params['notify_url'],
    'return_url' => $params['return_url'],
];

if (!$db->createOrder($orderData)) {
    $error = "订单创建失败";
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'order_create']);
    jsonError($error);
}

// 记录成功的请求日志
$logger->logIncoming('submit_request', $requestData, [
    'trade_no' => $tradeNo,
    'redirect_to' => 'pay.php?trade_no=' . $tradeNo,
    'code' => 'success'
], ['step' => 'success', 'order_created' => true]);

// 跳转到支付页面
$payUrl = 'pay.php?trade_no=' . $tradeNo;
header('Location: ' . $payUrl);
exit;

/**
 * 生成签名
 * 算法: MD5(参数按ASCII排序拼接 + 商户密钥 + 盐值)
 */
function generateSign($params, $key) {
    // 按ASCII排序
    ksort($params);
    
    // 拼接成字符串
    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $string .= $k . '=' . $v . '&';
        }
    }
    
    // 去掉最后一个&并加上密钥和盐值
    $string = rtrim($string, '&') . $key . 'qwer';
    
    return md5($string);
}

/**
 * 返回错误信息
 */
function jsonError($msg) {
    header('Content-Type: application/json');
    echo json_encode(['code' => 'error', 'msg' => $msg]);
    exit;
}
