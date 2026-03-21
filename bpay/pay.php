<?php
/**
 * BPay 支付页面
 * 显示收款码和订单信息
 */

require_once 'db.php';
require_once 'lib/AlipayApp.php';

$tradeNo = $_GET['trade_no'] ?? '';
if (empty($tradeNo)) {
    die('订单号错误');
}

$db = new BPayDB();

$order = $db->getOrderByTradeNo($tradeNo);
if (!$order) {
    die('订单不存在：' . htmlspecialchars($tradeNo));
}

if ($order['status'] == 1) {
    if (strpos($order['trade_no'], 'TEST') === 0) {
        header('Location: test_success.php?trade_no=' . $order['trade_no']);
    } else {
        header('Location: ' . $order['return_url']);
    }
    exit;
}

$payTypeName = $order['type'] == 'alipay' ? '支付宝' : '微信支付';

$qrCodeUrl = '';
$payUrl = '';
$payMethod = '';
$merchantNotConfigured = false;

if ($order['type'] == 'alipay') {
    $appId = $db->getConfig('alipay_app_id');
    $privateKey = $db->getConfig('alipay_private_key');
    $payMode = $db->getConfig('alipay_pay_mode') ?: 'auto';

    $hasFaceToFace = !empty($appId) && !empty($privateKey);
    $hasQrcode = file_exists('assets/images/alipay_qrcode.png');

    if ($payMode == 'face') {
        if ($hasFaceToFace) {
            $payMethod = 'face';
        } else {
            $merchantNotConfigured = true;
        }
    } elseif ($payMode == 'qrcode') {
        if ($hasQrcode) {
            $payMethod = 'qrcode';
        } else {
            $merchantNotConfigured = true;
        }
    } else {
        if ($hasFaceToFace) {
            $payMethod = 'face';
        } elseif ($hasQrcode) {
            $payMethod = 'qrcode';
        } else {
            $merchantNotConfigured = true;
        }
    }

    if ($payMethod == 'face') {
        $alipayApp = new AlipayApp($appId, $privateKey);
        $payData = $alipayApp->createPayLink($order['out_trade_no'], $order['money'], $order['name']);
        $qrCodeUrl = $payData['qrcode_url'];
        $payUrl = $payData['pay_url'];
    } elseif ($payMethod == 'qrcode') {
        $qrCodeUrl = 'assets/images/alipay_qrcode.png';
    }
} else {
    $qrCodeFile = 'assets/images/wxpay_qrcode.png';
    if (file_exists($qrCodeFile)) {
        $qrCodeUrl = $qrCodeFile;
        $payMethod = 'qrcode';
    } else {
        $merchantNotConfigured = true;
    }
}

$hasQrcode = !empty($qrCodeUrl);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BPay - <?php echo htmlspecialchars($payTypeName); ?>支付</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .pay-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            max-width: 400px;
            width: 100%;
            padding: 30px;
        }
        .pay-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .pay-header h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
        }
        .pay-type {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        .pay-type.alipay {
            background: #1677ff;
            color: #fff;
        }
        .pay-type.wxpay {
            background: #07c160;
            color: #fff;
        }
        .order-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
        }
        .order-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .order-info-item:last-child {
            margin-bottom: 0;
        }
        .order-info-label {
            color: #666;
        }
        .order-info-value {
            color: #333;
            font-weight: 500;
        }
        .order-amount {
            text-align: center;
            margin-bottom: 25px;
        }
        .order-amount-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .order-amount-value {
            font-size: 36px;
            color: #ff6b6b;
            font-weight: bold;
        }
        .qrcode-container {
            text-align: center;
            margin-bottom: 25px;
        }
        .qrcode {
            width: 200px;
            height: 200px;
            border: 2px solid #eee;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }
        .qrcode img {
            max-width: 180px;
            max-height: 180px;
        }
        .qrcode-placeholder {
            color: #999;
            font-size: 14px;
        }
        .pay-tips {
            background: #fff7e6;
            border: 1px solid #ffd591;
            border-radius: 8px;
            padding: 15px;
            font-size: 13px;
            color: #666;
            line-height: 1.6;
        }
        .pay-tips-title {
            font-weight: 500;
            color: #fa8c16;
            margin-bottom: 8px;
        }
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
            vertical-align: middle;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status-checking {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-top: 15px;
        }
        .btn-alipay {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #1677ff;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: opacity 0.3s;
        }
        .btn-alipay:hover {
            opacity: 0.9;
        }
        .error-message {
            text-align: center;
            padding: 40px 20px;
            color: #ff4d4f;
        }
        .error-message i {
            font-size: 48px;
            display: block;
            margin-bottom: 16px;
        }
        .method-tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 8px;
        }
        .method-tag.face {
            background: #e6f7ff;
            color: #1677ff;
        }
        .method-tag.qrcode {
            background: #f6ffed;
            color: #52c41a;
        }
    </style>
</head>
<body>
    <div class="pay-container">
        <div class="pay-header">
            <h1>扫码支付</h1>
            <span class="pay-type <?php echo $order['type']; ?>">
                <?php echo $payTypeName; ?>
                <?php if ($payMethod == 'face'): ?>
                <span class="method-tag face">当面付</span>
                <?php elseif ($payMethod == 'qrcode'): ?>
                <span class="method-tag qrcode">收款码</span>
                <?php endif; ?>
            </span>
        </div>

        <div class="order-info">
            <div class="order-info-item">
                <span class="order-info-label">商品名称</span>
                <span class="order-info-value"><?php echo htmlspecialchars($order['name']); ?></span>
            </div>
            <div class="order-info-item">
                <span class="order-info-label">订单号</span>
                <span class="order-info-value"><?php echo htmlspecialchars($order['out_trade_no']); ?></span>
            </div>
        </div>

        <div class="order-amount">
            <div class="order-amount-label">支付金额</div>
            <div class="order-amount-value">¥<?php echo number_format($order['money'], 2); ?></div>
            <div style="font-size: 12px; color: #ff6b6b; margin-top: 5px;">实际支付金额可能包含0.01-0.99的随机小数，请按显示金额准确支付</div>
        </div>

        <?php if ($merchantNotConfigured): ?>
        <div class="error-message">
            <i class="ri-error-warning-line"></i>
            <h3>商户未配置</h3>
            <p style="margin-top: 8px; color: #999;">请联系管理员配置收款方式</p>
        </div>
        <?php else: ?>
        <div class="qrcode-container">
            <div class="qrcode">
                <?php if ($qrCodeUrl): ?>
                    <?php if ($order['type'] == 'alipay' && !empty($payUrl)): ?>
                        <a href="<?php echo $payUrl; ?>" target="_blank">
                            <img src="<?php echo $qrCodeUrl; ?>" alt="支付宝收款码" style="max-width: 180px; max-height: 180px;">
                        </a>
                    <?php else: ?>
                        <img src="<?php echo $qrCodeUrl; ?>" alt="收款码">
                    <?php endif; ?>
                <?php else: ?>
                    <span class="qrcode-placeholder">收款码未配置</span>
                <?php endif; ?>
            </div>
            <?php if ($order['type'] == 'alipay' && !empty($payUrl)): ?>
            <div style="margin-top: 15px;">
                <a href="<?php echo $payUrl; ?>" class="btn-alipay" target="_blank">
                    <i class="ri-alipay-line"></i> 点击打开支付宝
                </a>
            </div>
            <?php endif; ?>
        </div>

        <div class="pay-tips">
            <div class="pay-tips-title">支付说明</div>
            <p>1. 请使用<?php echo $payTypeName; ?>扫描上方二维码</p>
            <p>2. 请确保支付金额与订单金额一致</p>
            <p>3. 支付完成后请等待页面自动跳转</p>
            <p style="color: #ff6b6b; font-weight: bold;"><i class="ri-error-warning-line"></i> 实际支付金额可能包含0.01-0.99的随机小数，请按显示金额准确支付</p>
            <?php if ($payMethod == 'face'): ?>
            <p style="margin-top: 10px; color: #1677ff;"><i class="ri-information-line"></i> 使用支付宝当面付，支付状态实时同步</p>
            <?php endif; ?>
        </div>

        <div class="status-checking">
            正在检查支付状态<span class="loading"></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$merchantNotConfigured): ?>
    <script>
        const tradeNo = '<?php echo $tradeNo; ?>';
        const returnUrl = '<?php echo htmlspecialchars($order['return_url']); ?>';
        const isTestOrder = tradeNo.startsWith('TEST');

        function checkStatus() {
            fetch('api/query_order.php?trade_no=' + tradeNo)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 1) {
                        if (isTestOrder) {
                            window.location.href = 'test_success.php?trade_no=' + tradeNo;
                        } else {
                            window.location.href = returnUrl;
                        }
                    }
                })
                .catch(err => console.error('查询失败:', err));
        }

        setInterval(checkStatus, 3000);
    </script>
    <?php endif; ?>
</body>
</html>
