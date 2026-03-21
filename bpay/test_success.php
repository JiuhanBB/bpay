<?php
require_once 'db.php';

// 获取订单号
$tradeNo = $_GET['trade_no'] ?? '';

// 查询订单信息
$order = null;
if ($tradeNo) {
    $db = new BPayDB('bpay.db');
    $order = $db->getOrderByTradeNo($tradeNo);
}

// 如果没有找到订单，显示默认信息
$amount = $order ? $order['money'] : '0.00';
$payTime = $order ? ($order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : date('Y-m-d H:i:s')) : date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>支付成功（测试）</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .success-container {
            background: #fff;
            border-radius: 20px;
            padding: 50px 40px;
            text-align: center;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.5s ease-out;
        }
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #52c41a 0%, #389e0d 100%);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.5s ease-out 0.2s both;
        }
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
        .success-icon::after {
            content: '✓';
            color: #fff;
            font-size: 50px;
            font-weight: bold;
        }
        .success-title {
            font-size: 28px;
            color: #333;
            margin-bottom: 30px;
            font-weight: 600;
        }
        .info-list {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            text-align: left;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e8e8e8;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #666;
            font-size: 14px;
        }
        .info-value {
            color: #333;
            font-size: 14px;
            font-weight: 500;
        }
        .amount {
            color: #f5222d;
            font-size: 20px;
            font-weight: 700;
        }
        .back-btn {
            display: inline-block;
            padding: 15px 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            text-decoration: none;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        .back-btn:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon"></div>
        <h1 class="success-title">支付成功</h1>
        <div class="info-list">
            <div class="info-item">
                <span class="info-label">订单金额</span>
                <span class="info-value amount">¥<?php echo number_format($amount, 2); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">订单号</span>
                <span class="info-value"><?php echo htmlspecialchars($tradeNo ?: 'N/A'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">支付时间</span>
                <span class="info-value"><?php echo $payTime; ?></span>
            </div>
        </div>
        <a href="admin/?page=test_pay" class="back-btn">返回测试页面</a>
    </div>
</body>
</html>
