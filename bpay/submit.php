<?php
/**
 * BPay 订单提交接口
 */

require_once 'db.php';
require_once 'lib/Logger.php';

$logger = new Logger();
$params = array_merge($_GET, $_POST);

$requestData = [
    'params' => $params,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

$required = ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'sign'];
foreach ($required as $field) {
    if (empty($params[$field])) {
        $error = "缺少必要参数: {$field}";
        $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'validation']);
        jsonError($error);
    }
}

$db = new BPayDB(__DIR__ . '/bpay.db');

$merchantId = $db->getConfig('merchant_id') ?: '1000';
if ($params['pid'] !== $merchantId) {
    $error = '商户ID错误';
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'merchant_check']);
    jsonError($error);
}

$merchantKey = $db->getConfig('merchant_key');
if (!$merchantKey) {
    $error = '商户配置错误';
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'config_check']);
    jsonError($error);
}

$sign = $params['sign'];
unset($params['sign'], $params['sign_type']);

$calculatedSign = generateSign($params, $merchantKey);
if ($sign !== $calculatedSign) {
    $error = '签名验证失败';
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'sign_check', 'calculated_sign' => $calculatedSign]);
    jsonError($error);
}

if (!is_numeric($params['money']) || $params['money'] <= 0) {
    $error = '金额格式错误';
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'money_check']);
    jsonError($error);
}

if (!in_array($params['type'], ['alipay', 'wxpay'], true)) {
    $error = '支付方式错误';
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'type_check']);
    jsonError($error);
}

$tradeNo = $db->generateTradeNo();
$baseMoney = (float) $params['money'];
$finalMoney = $db->getUniqueMoney($baseMoney);
$normalizedName = normalizeRequestText($params['name']);

$orderData = [
    'trade_no' => $tradeNo,
    'out_trade_no' => $params['out_trade_no'],
    'merchant_id' => $params['pid'],
    'name' => $normalizedName,
    'money' => $finalMoney,
    'original_money' => $params['money'],
    'type' => $params['type'],
    'notify_url' => $params['notify_url'],
    'return_url' => $params['return_url'],
];

if (!$db->createOrder($orderData)) {
    $error = '订单创建失败';
    $logger->logIncoming('submit_request', $requestData, ['error' => $error, 'code' => 'error'], ['step' => 'order_create']);
    jsonError($error);
}

$logger->logIncoming('submit_request', $requestData, [
    'trade_no' => $tradeNo,
    'redirect_to' => 'pay.php?trade_no=' . $tradeNo,
    'code' => 'success'
], ['step' => 'success', 'order_created' => true]);

header('Location: pay.php?trade_no=' . $tradeNo);
exit;

/**
 * 商户下单签名
 * 算法: MD5(参数按 ASCII 排序拼接 + merchant_key)
 */
function generateSign($params, $key) {
    ksort($params);

    $string = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $string .= $k . '=' . $v . '&';
        }
    }

    return md5(rtrim($string, '&') . $key);
}

function jsonError($msg) {
    header('Content-Type: application/json');
    echo json_encode(['code' => 'error', 'msg' => $msg]);
    exit;
}

function normalizeRequestText($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return $value;
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

    return $value;
}
