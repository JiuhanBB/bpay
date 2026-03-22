<?php
/**
 * BPay 订单导出功能
 * 支持导出为 CSV 格式
 */

require_once '../db.php';

// JWT密钥
$jwtSecret = 'XANOon14r3CuIvJNS8pQ5MkAZJFoGvUDrtF66aj1tjA8ocDlw02oNkkYTKduJnjv8K15Gebvf32aeq8bGmiEkC';

// 验证登录
if (!isset($_COOKIE['bpay_token'])) {
    die('未登录');
}

$token = $_COOKIE['bpay_token'];
if (!verifyJWT($token, $jwtSecret)) {
    die('登录已过期');
}

// 获取导出参数
$range = $_GET['range'] ?? 'all'; // 7days, 30days, 1year, all, custom
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// 计算时间范围
$endTime = time();
$startTime = 0;

switch ($range) {
    case '7days':
        $startTime = strtotime('-7 days');
        break;
    case '30days':
        $startTime = strtotime('-30 days');
        break;
    case '1year':
        $startTime = strtotime('-1 year');
        break;
    case 'custom':
        if (!empty($startDate) && !empty($endDate)) {
            $startTime = strtotime($startDate);
            $endTime = strtotime($endDate . ' 23:59:59');
        }
        break;
    case 'all':
    default:
        $startTime = 0;
        break;
}

// 获取订单数据
$db = new BPayDB('../bpay.db');
$orders = $db->getOrdersByTimeRange($startTime, $endTime);

// 设置 CSV 文件名
$filename = 'orders_' . date('Ymd_His') . '.csv';

// 设置响应头
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// 添加 BOM 以支持中文
echo "\xEF\xBB\xBF";

// 打开输出流
$output = fopen('php://output', 'w');

// 写入 CSV 表头
$headers = ['平台订单号', '商户订单号', '商品名称', '金额', '支付方式', '状态', '创建时间', '支付时间'];
fputcsv($output, $headers);

// 状态映射
$statusMap = [
    0 => '待支付',
    1 => '已支付',
    2 => '已取消'
];

// 支付方式映射
$typeMap = [
    'alipay' => '支付宝',
    'wxpay' => '微信支付'
];

// 写入订单数据
foreach ($orders as $order) {
    $row = [
        $order['trade_no'],
        $order['out_trade_no'],
        $order['name'],
        $order['money'],
        $typeMap[$order['type']] ?? $order['type'],
        $statusMap[$order['status']] ?? '未知',
        date('Y-m-d H:i:s', $order['create_time']),
        $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '-'
    ];
    fputcsv($output, $row);
}

fclose($output);
exit;

/**
 * 验证JWT
 */
function verifyJWT($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
    if (!$payload || !isset($payload['exp'])) return false;
    
    if ($payload['exp'] < time()) return false;
    
    $signature = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return hash_equals($base64Signature, $parts[2]);
}
