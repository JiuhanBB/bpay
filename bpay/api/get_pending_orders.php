<?php
/**
 * BPay 获取待监控订单接口
 * 监控端轮询此接口获取所有待支付未超时订单，按需启动 OCR/API 检测
 */

require_once '../db.php';
require_once '../lib/Logger.php';

header('Content-Type: application/json');

$db = new BPayDB(__DIR__ . '/../bpay.db');
$orders = $db->getActivePendingOrders();

$result = [];
foreach ($orders as $order) {
    $result[] = [
        'trade_no' => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'type' => $order['type'],
        'money' => $order['money'],
        'name' => $order['name'],
        'create_time' => (int)$order['create_time'],
        'expire_time' => (int)$order['create_time'] + 300,
    ];
}

echo json_encode([
    'code' => 'success',
    'data' => $result,
    'server_time' => time(),
]);
