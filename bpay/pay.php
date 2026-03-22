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

// 检查订单是否已支付
if ($order['status'] == 1) {
    if (strpos($order['trade_no'], 'TEST') === 0) {
        header('Location: test_success.php?trade_no=' . $order['trade_no']);
    } else {
        header('Location: ' . $order['return_url']);
    }
    exit;
}

// 检查订单是否已取消
if ($order['status'] == 2) {
    die('订单已过期，请重新下单');
}

// 检查订单是否超时
if ($db->isOrderExpired($order)) {
    $db->cancelOrder($tradeNo);
    die('订单已过期，请重新下单');
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

// 计算剩余时间
$expireTime = $order['create_time'] + 300;
$remainingSeconds = $expireTime - time();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>BPay - <?php echo htmlspecialchars($payTypeName); ?>支付</title>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 10px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }
        .pay-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
            padding: 20px;
            margin-top: 10px;
        }
        .pay-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        .pay-header h1 {
            font-size: 20px;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .pay-type {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 15px;
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
        .countdown {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
            font-size: 14px;
            color: #ff4d4f;
            font-weight: 500;
        }
        .countdown.expired {
            color: #999;
        }
        .order-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
        }
        .order-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
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
            text-align: right;
            flex: 1;
            margin-left: 10px;
            word-break: break-all;
        }
        .order-amount {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #fff5f5;
            border-radius: 8px;
            border: 1px solid #ffe0e0;
        }
        .order-amount-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }
        .order-amount-value {
            font-size: 32px;
            color: #ff4d4f;
            font-weight: bold;
        }
        .amount-notice {
            font-size: 11px;
            color: #ff6b6b;
            margin-top: 8px;
            padding: 8px;
            background: #fff;
            border-radius: 4px;
            line-height: 1.4;
        }
        .qrcode-container {
            text-align: center;
            margin-bottom: 20px;
        }
        .qrcode {
            width: 100%;
            max-width: 220px;
            aspect-ratio: 1;
            border: 2px solid #eee;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            margin: 0 auto;
            overflow: hidden;
            position: relative;
        }
        .qrcode img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 10px;
        }
        .qrcode-placeholder {
            color: #999;
            font-size: 14px;
            padding: 20px;
        }
        .pay-tips {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            border-radius: 8px;
            padding: 12px;
            font-size: 12px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .pay-tips-title {
            font-weight: 600;
            color: #fa8c16;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .pay-tips p {
            margin-bottom: 5px;
            padding-left: 5px;
        }
        .loading {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #52c41a;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status-checking {
            text-align: center;
            color: #52c41a;
            font-size: 13px;
            padding: 12px;
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-alipay {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            background: #1677ff;
            color: #fff;
            text-decoration: none;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            width: 100%;
            max-width: 220px;
        }
        .btn-alipay:hover {
            background: #0056b3;
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

        /* 响应式优化 */
        @media (max-width: 480px) {
            body {
                padding: 0;
                background: #fff;
            }
            .pay-container {
                border-radius: 0;
                box-shadow: none;
                max-width: 100%;
                margin-top: 0;
                padding: 15px;
                min-height: 100vh;
            }
            .pay-header h1 {
                font-size: 18px;
            }
            .order-amount-value {
                font-size: 28px;
            }
            .qrcode {
                max-width: 200px;
            }
        }

        @media (max-width: 360px) {
            .pay-container {
                padding: 12px;
            }
            .order-amount-value {
                font-size: 24px;
            }
            .qrcode {
                max-width: 180px;
            }
        }
    </style>
</head>
<body>
    <div class="pay-container">
        <div class="pay-header">
            <h1><i class="ri-qr-code-line"></i> 扫码支付</h1>
            <span class="pay-type <?php echo $order['type']; ?>">
                <i class="ri-<?php echo $order['type'] == 'alipay' ? 'alipay' : 'wechat-pay'; ?>-line"></i>
                <?php echo $payTypeName; ?>
            </span>
            <div class="countdown" id="countdown">
                <i class="ri-time-line"></i>
                <span>剩余时间：<span id="timer">05:00</span></span>
            </div>
        </div>

        <div class="order-info">
            <div class="order-info-item">
                <span class="order-info-label"><i class="ri-shopping-bag-line"></i> 商品名称</span>
                <span class="order-info-value"><?php echo htmlspecialchars($order['name']); ?></span>
            </div>
            <div class="order-info-item">
                <span class="order-info-label"><i class="ri-file-list-line"></i> 订单号</span>
                <span class="order-info-value"><?php echo htmlspecialchars($order['out_trade_no']); ?></span>
            </div>
        </div>

        <div class="order-amount">
            <div class="order-amount-label">支付金额</div>
            <div class="order-amount-value">¥<?php echo number_format($order['money'], 2); ?></div>
            <div class="amount-notice">
                <i class="ri-error-warning-line"></i> 实际支付金额可能包含0.01-0.99的随机小数，请按显示金额准确支付
            </div>
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
                            <img src="<?php echo $qrCodeUrl; ?>" alt="支付宝收款码">
                        </a>
                    <?php else: ?>
                        <img src="<?php echo $qrCodeUrl; ?>" alt="收款码">
                    <?php endif; ?>
                <?php else: ?>
                    <span class="qrcode-placeholder"><i class="ri-qr-code-line" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>收款码未配置</span>
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
            <div class="pay-tips-title"><i class="ri-lightbulb-line"></i> 支付说明</div>
            <p>1. 请使用<?php echo $payTypeName; ?>扫描上方二维码</p>
            <p>2. 请确保支付金额与订单金额一致</p>
            <p>3. 支付完成后请等待页面自动跳转</p>
            <p>4. 订单有效期为5分钟，超时请重新下单</p>
        </div>

        <div class="status-checking">
            <i class="ri-refresh-line" style="margin-right: 5px;"></i> 正在检查支付状态<span class="loading"></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$merchantNotConfigured): ?>
    <script>
        const tradeNo = '<?php echo $tradeNo; ?>';
        const returnUrl = '<?php echo htmlspecialchars($order['return_url']); ?>';
        const isTestOrder = tradeNo.startsWith('TEST');
        let remainingSeconds = <?php echo $remainingSeconds; ?>;

        // 倒计时
        function updateCountdown() {
            const timerEl = document.getElementById('timer');
            const countdownEl = document.getElementById('countdown');
            
            if (remainingSeconds <= 0) {
                timerEl.textContent = '00:00';
                countdownEl.classList.add('expired');
                countdownEl.innerHTML = '<i class="ri-time-line"></i><span>订单已过期</span>';
                setTimeout(() => {
                    alert('订单已过期，请重新下单');
                    location.reload();
                }, 1000);
                return;
            }
            
            const minutes = Math.floor(remainingSeconds / 60);
            const seconds = remainingSeconds % 60;
            timerEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            remainingSeconds--;
        }
        
        updateCountdown();
        setInterval(updateCountdown, 1000);

        // 检查支付状态
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
                    } else if (data.status === 2) {
                        alert('订单已过期，请重新下单');
                        location.reload();
                    }
                })
                .catch(err => console.error('查询失败:', err));
        }

        setInterval(checkStatus, 3000);
    </script>
    <?php endif; ?>
</body>
</html>
