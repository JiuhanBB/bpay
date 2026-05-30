<?php

/**
 * BPay 支付页面
 * 双分类支付：官渠道（支付宝当面付/JSAPI + 微信JSAPI）优先，码渠道（静态收款码）兜底
 */
require_once 'db.php';
require_once 'lib/AlipayApp.php';
require_once 'lib/WechatJsapi.php';

$tradeNo = $_GET['trade_no'] ?? '';
if ($tradeNo === '') {
    die('订单号错误');
}

$db = new BPayDB(__DIR__ . '/bpay.db');
$order = $db->getOrderByTradeNo($tradeNo);
if (!$order) {
    die('订单不存在：' . htmlspecialchars($tradeNo));
}

if ((int) $order['status'] === 1) {
    if (strpos($order['trade_no'], 'TEST') === 0) {
        header('Location: test_success.php?trade_no=' . $order['trade_no']);
    } else {
        header('Location: ' . $order['return_url']);
    }
    exit;
}

if ((int) $order['status'] === 2) {
    die('订单已过期，请重新下单');
}

if ($db->isOrderExpired($order)) {
    $db->cancelOrder($tradeNo);
    die('订单已过期，请重新下单');
}

function buildQrImageUrl($content) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&margin=0&data=' . rawurlencode($content);
}

function detectBrowser($ua) {
    return [
        'isWechat' => strpos($ua, 'MicroMessenger') !== false,
        'isAlipayApp' => strpos($ua, 'AlipayClient') !== false,
        'isMobile' => preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile/i', $ua),
    ];
}

// ==== 加载配置 ====
// 官渠道 - 支付宝当面付/JSAPI
$alipayAppId = trim((string) $db->getConfig('alipay_app_id'));
$alipayPrivateKey = trim((string) $db->getConfig('alipay_private_key'));
$alipayPublicKey = trim((string) $db->getConfig('alipay_public_key'));
$hasAlipayOfficial = $alipayAppId !== '' && $alipayPrivateKey !== '';

// 官渠道 - 微信JSAPI
$wxJsapiAppId = trim((string) $db->getConfig('wechat_jsapi_appid'));
$wxJsapiAppSecret = trim((string) $db->getConfig('wechat_jsapi_appsecret'));
$wxJsapiMchId = trim((string) $db->getConfig('wechat_jsapi_mchid'));
$wxJsapiKey = trim((string) $db->getConfig('wechat_jsapi_key'));
$hasWechatJsapi = $wxJsapiAppId !== '' && $wxJsapiAppSecret !== '' && $wxJsapiMchId !== '' && $wxJsapiKey !== '';

// 码渠道 - 静态收款码
$alipayStaticQrPath = __DIR__ . '/assets/images/alipay_qrcode.png';
$wxpayStaticQrPath = __DIR__ . '/assets/images/wxpay_qrcode.png';
$hasAlipayQr = file_exists($alipayStaticQrPath);
$hasWxpayQr = file_exists($wxpayStaticQrPath);

// 支付模式（兼容旧配置）
$payMode = $db->getConfig('alipay_pay_mode') ?: 'auto';

$isAlipay = $order['type'] === 'alipay';
$browser = detectBrowser($_SERVER['HTTP_USER_AGENT'] ?? '');

$payTypeName = $isAlipay ? '支付宝支付' : '微信支付';

// ==== 输出变量 ====
$qrCodeUrl = '';
$payUrl = '';
$merchantNotConfigured = false;
$paymentError = '';
$runtimeFallbackTip = '';
$channelType = ''; // 'official' 或 'qrcode'
$payModeName = ''; // 用于展示：'官渠道' 或 '码渠道'

// 微信JSAPI专用
$jsapiParams = null;
$showWechatGuide = false;
$wechatGuideQr = '';

// ========================================
//  支付路由：官渠道优先，码渠道兜底
// ========================================

if ($isAlipay) {
    // ===== 支付宝支付 =====
    $officialTried = false;

    // 尝试官渠道
    if ($hasAlipayOfficial && $payMode !== 'qrcode') {
        $officialTried = true;
        $alipayApp = new AlipayApp($alipayAppId, $alipayPrivateKey, $alipayPublicKey);

        if ($browser['isMobile'] && $order['return_url']) {
            // 移动端且有return_url：WAP支付（直接跳转支付宝收银台）
            $wapUrl = $alipayApp->createWapPayUrl(
                $order['out_trade_no'],
                $order['money'],
                $order['name'],
                $order['return_url'],
                $order['notify_url']
            );

            if ($wapUrl !== '') {
                // WAP notify_url -> 商户的notify_url (Alipay异步通知直接发给商户)
                // WAP return_url -> 订单return_url (支付完成后跳转)
                // 直接跳转支付宝，无需轮询
                $channelType = 'official';
                $payModeName = '官渠道';
                $payUrl = $wapUrl;
                ?>
                <!DOCTYPE html>
                <html lang="zh-CN">
                <head><meta charset="UTF-8"><title>正在跳转到支付宝...</title>
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f5f5f5;text-align:center;padding:20px}.card{background:#fff;border-radius:12px;padding:40px 30px;box-shadow:0 2px 12px rgba(0,0,0,.1);max-width:360px;width:100%}.spinner{width:48px;height:48px;border:4px solid #e8e8e8;border-top-color:#1677ff;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 20px}@keyframes spin{to{transform:rotate(360deg)}}h2{color:#333;font-size:18px;margin:0 0 10px}p{color:#999;font-size:14px;margin:0 0 24px;line-height:1.6}.btn{display:inline-block;padding:12px 32px;background:#1677ff;color:#fff;text-decoration:none;border-radius:8px;font-size:15px;transition:background .2s}.btn:hover{background:#0056b3}</style>
                </head>
                <body>
                <div class="card">
                    <div class="spinner"></div>
                    <h2>正在跳转到支付宝...</h2>
                    <p>请稍候，即将跳转到支付宝官方收银台完成支付</p>
                    <a href="<?php echo htmlspecialchars($wapUrl); ?>" class="btn" id="payBtn">立即支付</a>
                </div>
                <script>setTimeout(function(){window.location.href=document.getElementById('payBtn').href},800);</script>
                </body>
                </html>
                <?php
                exit;
            } else {
                $paymentError = '支付宝WAP支付初始化失败';
            }
        }

        // 桌面端或WAP失败：当面付（二维码+支付链接）
        if ($qrCodeUrl === '') {
            $payData = $alipayApp->createPayLink($order['out_trade_no'], $order['money'], $order['name']);

            if (!empty($payData['success']) && !empty($payData['qr_code_content'])) {
                $qrCodeUrl = buildQrImageUrl($payData['qr_code_content']);
                $payUrl = $payData['pay_url'];
                $channelType = 'official';
                $payModeName = '官渠道';
            } else {
                $paymentError = $payData['error_message'] ?? '支付宝下单失败';
            }
        }
    }

    // 码渠道兜底
    if ($qrCodeUrl === '') {
        // 仅官渠道模式：不降级到码渠道
        if ($payMode === 'face') {
            $paymentError = $paymentError ?: '官渠道支付失败';
        } elseif ($hasAlipayQr) {
            $qrCodeUrl = 'assets/images/alipay_qrcode.png';
            $channelType = 'qrcode';
            $payModeName = '码渠道';
            if ($officialTried && $paymentError) {
                $runtimeFallbackTip = '官渠道不可用，已自动切换到码渠道。';
            }
        } elseif (!$officialTried) {
            $merchantNotConfigured = true;
        } elseif ($payMode === 'qrcode') {
            $merchantNotConfigured = true;
        } else {
            $paymentError = $paymentError ?: '未配置任何支付方式';
        }
    }
} else {
    // ===== 微信支付 =====
    $officialTried = false;

    // 尝试微信JSAPI
    if ($hasWechatJsapi) {
        $officialTried = true;

        if ($browser['isWechat']) {
            // 在微信浏览器内：走JSAPI完整流程
            $code = $_GET['code'] ?? '';

            if ($code === '') {
                // 无授权码，跳转微信OAuth
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $currentUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $wechat = new WechatJsapi($wxJsapiAppId, $wxJsapiAppSecret, $wxJsapiMchId, $wxJsapiKey);
                $oauthUrl = $wechat->getOauthUrl($currentUrl, $tradeNo);
                header('Location: ' . $oauthUrl);
                exit;
            }

            // 有授权码，获取openid并统一下单
            $wechat = new WechatJsapi($wxJsapiAppId, $wxJsapiAppSecret, $wxJsapiMchId, $wxJsapiKey);
            $openidResult = $wechat->getOpenId($code);

            if ($openidResult['success']) {
                $notifyUrl = $order['notify_url'];

                $prepayResult = $wechat->createUnifiedOrder(
                    $openidResult['openid'],
                    $order['out_trade_no'],
                    $order['money'],
                    $notifyUrl,
                    $order['name']
                );

                if ($prepayResult['success']) {
                    $jsapiParams = $wechat->getJsapiParameters($prepayResult['prepay_id']);
                    $channelType = 'official';
                    $payModeName = '官渠道';
                } else {
                    $paymentError = $prepayResult['error'];
                }
            } else {
                $paymentError = $openidResult['error'];
            }
        } else {
            // 不在微信浏览器内：显示引导二维码，让用户扫码进入微信支付
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $currentUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $wechatGuideQr = buildQrImageUrl($currentUrl);
            $showWechatGuide = true;
            $channelType = 'official';
            $payModeName = '官渠道';
        }
    }

    // 码渠道兜底（仅当JSAPI未配置时才走）
    if (!$jsapiParams && !$showWechatGuide) {
        if ($hasWxpayQr) {
            $qrCodeUrl = 'assets/images/wxpay_qrcode.png';
            $channelType = 'qrcode';
            $payModeName = '码渠道';
            if ($officialTried && $paymentError) {
                $runtimeFallbackTip = '官渠道不可用，已自动切换到码渠道。';
            }
        } elseif (!$officialTried) {
            $merchantNotConfigured = true;
        } else {
            $paymentError = $paymentError ?: '未配置任何支付方式';
        }
    }
}

$hasQrcode = $qrCodeUrl !== '';
$expireTime = (int) $order['create_time'] + 300;
$remainingSeconds = max(0, $expireTime - time());
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>BPay - <?php echo htmlspecialchars($payTypeName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5; min-height: 100vh; padding: 10px;
            display: flex; justify-content: center; align-items: flex-start;
        }
        .pay-container {
            background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            width: 100%; max-width: 400px; padding: 20px; margin-top: 10px;
        }
        .pay-header { text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f0f0f0; }
        .pay-header h1 { font-size: 20px; color: #333; margin-bottom: 10px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .pay-type {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 20px; border-radius: 20px; font-size: 15px; font-weight: 500;
        }
        .pay-type.alipay { background: #1677ff; color: #fff; }
        .pay-type.wxpay { background: #07c160; color: #fff; }
        .channel-badge {
            display: inline-block; padding: 3px 10px; border-radius: 10px;
            font-size: 11px; font-weight: 500; margin-left: 8px;
            background: rgba(255,255,255,0.25); color: rgba(255,255,255,0.9);
        }
        .countdown { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 10px; font-size: 14px; color: #ff4d4f; font-weight: 500; }
        .countdown.expired { color: #999; }
        .order-info { background: #f8f9fa; border-radius: 8px; padding: 12px; margin-bottom: 15px; }
        .order-info-item { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
        .order-info-item:last-child { margin-bottom: 0; }
        .order-info-label { color: #666; }
        .order-info-value { color: #333; font-weight: 500; text-align: right; flex: 1; margin-left: 10px; word-break: break-all; }
        .order-amount {
            text-align: center; margin-bottom: 20px; padding: 15px;
            background: #fff5f5; border-radius: 8px; border: 1px solid #ffe0e0;
        }
        .order-amount-label { font-size: 13px; color: #666; margin-bottom: 8px; }
        .order-amount-value { font-size: 32px; color: #ff4d4f; font-weight: bold; }
        .amount-notice { font-size: 11px; color: #ff6b6b; margin-top: 8px; padding: 8px; background: #fff; border-radius: 4px; line-height: 1.4; }
        .qrcode-container { text-align: center; margin-bottom: 20px; }
        .qrcode {
            width: 100%; max-width: 220px; aspect-ratio: 1;
            border: 2px solid #eee; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #fff; margin: 0 auto; overflow: hidden; position: relative;
        }
        .qrcode img { width: 100%; height: 100%; object-fit: contain; padding: 10px; }
        .pay-tips {
            background: #fffbe6; border: 1px solid #ffe58f; border-radius: 8px;
            padding: 12px; font-size: 12px; color: #666; line-height: 1.6; margin-bottom: 15px;
        }
        .pay-tips-title { font-weight: 600; color: #fa8c16; margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
        .pay-tips p { margin-bottom: 5px; padding-left: 5px; }
        .pay-alert { margin-bottom: 15px; padding: 12px; border-radius: 8px; font-size: 12px; line-height: 1.6; }
        .pay-alert.warning { background: #fff7e6; border: 1px solid #ffd591; color: #ad6800; }
        .loading {
            display: inline-block; width: 14px; height: 14px;
            border: 2px solid #f3f3f3; border-top: 2px solid #52c41a;
            border-radius: 50%; animation: spin 1s linear infinite; margin-left: 8px; vertical-align: middle;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .status-checking {
            text-align: center; color: #52c41a; font-size: 13px; padding: 12px;
            background: #f6ffed; border: 1px solid #b7eb8f; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .btn-pay {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 14px 24px; background: #07c160; color: #fff; text-decoration: none;
            border: none; border-radius: 24px; font-size: 16px; font-weight: 600;
            cursor: pointer; transition: all 0.3s; width: 100%; max-width: 260px;
        }
        .btn-pay:hover { background: #06ad56; }
        .btn-pay:disabled { background: #ccc; cursor: not-allowed; }
        .btn-alipay {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 24px; background: #1677ff; color: #fff; text-decoration: none;
            border-radius: 20px; font-size: 14px; font-weight: 500;
            transition: all 0.3s; width: 100%; max-width: 220px;
        }
        .btn-alipay:hover { background: #0056b3; }
        .error-message { text-align: center; padding: 40px 20px; color: #ff4d4f; }
        .error-message i { font-size: 48px; display: block; margin-bottom: 16px; }
        .jsapi-section { text-align: center; margin-bottom: 20px; }
        .jsapi-section .pay-icon { font-size: 48px; color: #07c160; margin-bottom: 12px; }
        .wechat-guide-icon { font-size: 56px; color: #07c160; margin-bottom: 16px; text-align: center; }

        @media (max-width: 480px) {
            body { padding: 0; background: #fff; }
            .pay-container { border-radius: 0; box-shadow: none; max-width: 100%; margin-top: 0; padding: 15px; min-height: 100vh; }
            .pay-header h1 { font-size: 18px; }
            .order-amount-value { font-size: 28px; }
            .qrcode { max-width: 200px; }
        }
        @media (max-width: 360px) {
            .pay-container { padding: 12px; }
            .order-amount-value { font-size: 24px; }
            .qrcode { max-width: 180px; }
        }
    </style>
</head>
<body>
    <div class="pay-container">
        <div class="pay-header">
            <h1><i class="ri-qr-code-line"></i> 扫码支付</h1>
            <span class="pay-type <?php echo $isAlipay ? 'alipay' : 'wxpay'; ?>">
                <i class="ri-<?php echo $isAlipay ? 'alipay' : 'wechat-pay'; ?>-line"></i>
                <?php echo htmlspecialchars($payTypeName); ?>
                <?php if ($payModeName): ?>
                <span class="channel-badge"><?php echo htmlspecialchars($payModeName); ?></span>
                <?php endif; ?>
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
            <div class="order-amount-value">￥<?php echo number_format((float) $order['money'], 2); ?></div>
            <div class="amount-notice">
                <i class="ri-error-warning-line"></i> 请按页面显示金额准确支付。
            </div>
        </div>

        <?php if ($merchantNotConfigured): ?>
        <div class="error-message">
            <i class="ri-error-warning-line"></i>
            <h3>商户未配置</h3>
            <p style="margin-top: 8px; color: #999;">请联系管理员完成支付配置。</p>
        </div>

        <?php elseif ($paymentError && !$hasQrcode && !$jsapiParams && !$showWechatGuide): ?>
        <div class="error-message">
            <i class="ri-error-warning-line"></i>
            <h3>支付不可用</h3>
            <p style="margin-top: 8px; color: #999;"><?php echo htmlspecialchars($paymentError ?: '未能生成支付信息'); ?></p>
        </div>

        <?php elseif ($showWechatGuide): ?>
        <!-- 微信JSAPI引导（非微信浏览器） -->
        <div class="qrcode-container">
            <div class="wechat-guide-icon">
                <i class="ri-wechat-pay-line"></i>
            </div>
            <p style="color: #666; margin-bottom: 16px; font-size: 14px; text-align: center; line-height: 1.8;">
                已配置微信官渠道支付<br>
                请使用<strong>微信</strong>扫描下方二维码完成支付
            </p>
            <div class="qrcode" style="border-color: #07c160;">
                <img src="<?php echo htmlspecialchars($wechatGuideQr); ?>" alt="微信支付引导二维码">
            </div>
        </div>

        <div class="pay-tips">
            <div class="pay-tips-title"><i class="ri-lightbulb-line"></i> 支付说明</div>
            <p>1. 打开手机微信"扫一扫"扫描上方二维码。</p>
            <p>2. 在微信内打开页面后自动调起微信支付。</p>
            <p>3. 支付完成后页面会自动跳转。</p>
            <p>4. 订单有效期为 5 分钟，超时请重新下单。</p>
        </div>

        <?php elseif ($jsapiParams): ?>
        <!-- 微信JSAPI支付 -->
        <?php if ($runtimeFallbackTip !== ''): ?>
        <div class="pay-alert warning">
            <i class="ri-error-warning-line"></i> <?php echo htmlspecialchars($runtimeFallbackTip); ?>
        </div>
        <?php endif; ?>

        <div class="jsapi-section">
            <div class="pay-icon"><i class="ri-wechat-pay-line"></i></div>
            <p style="color: #666; margin-bottom: 16px; font-size: 14px;">点击下方按钮在微信内完成支付</p>
            <button class="btn-pay" id="jsapiPayBtn" onclick="callWechatPay()">
                <i class="ri-wechat-pay-line"></i> 确认支付
            </button>
        </div>

        <div class="pay-tips">
            <div class="pay-tips-title"><i class="ri-lightbulb-line"></i> 支付说明</div>
            <p>1. 点击"确认支付"按钮唤起微信支付。</p>
            <p>2. 在微信支付窗口完成付款。</p>
            <p>3. 支付成功后页面会自动跳转。</p>
            <p>4. 订单有效期为 5 分钟，超时请重新下单。</p>
        </div>

        <div class="status-checking">
            <i class="ri-refresh-line" style="margin-right: 5px;"></i> 正在检查支付状态<span class="loading"></span>
        </div>

        <script>
        var jsapiParams = <?php echo json_encode($jsapiParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function callWechatPay() {
            var btn = document.getElementById('jsapiPayBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="ri-loader-4-line" style="animation: spin 1s linear infinite;"></i> 处理中...';

            if (typeof WeixinJSBridge === 'undefined') {
                if (document.addEventListener) {
                    document.addEventListener('WeixinJSBridgeReady', doWechatPay, false);
                } else if (document.attachEvent) {
                    document.attachEvent('WeixinJSBridgeReady', doWechatPay);
                    document.attachEvent('onWeixinJSBridgeReady', doWechatPay);
                }
            } else {
                doWechatPay();
            }
        }

        function doWechatPay() {
            WeixinJSBridge.invoke('getBrandWCPayRequest', {
                appId: jsapiParams.appId,
                timeStamp: jsapiParams.timeStamp,
                nonceStr: jsapiParams.nonceStr,
                package: jsapiParams.package,
                signType: jsapiParams.signType,
                paySign: jsapiParams.paySign
            }, function(res) {
                if (res.err_msg === 'get_brand_wcpay_request:ok') {
                    // 支付成功，等待轮询跳转
                    var btn = document.getElementById('jsapiPayBtn');
                    btn.innerHTML = '<i class="ri-check-line"></i> 支付成功';
                } else if (res.err_msg === 'get_brand_wcpay_request:cancel') {
                    alert('已取消支付');
                    var btn = document.getElementById('jsapiPayBtn');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="ri-wechat-pay-line"></i> 确认支付';
                } else {
                    alert('支付失败: ' + (res.err_msg || '未知错误'));
                    var btn = document.getElementById('jsapiPayBtn');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="ri-wechat-pay-line"></i> 确认支付';
                }
            });
        }
        </script>

        <?php elseif ($hasQrcode): ?>
        <!-- 码渠道 / 当面付 -->
        <?php if ($runtimeFallbackTip !== ''): ?>
        <div class="pay-alert warning">
            <i class="ri-error-warning-line"></i> <?php echo htmlspecialchars($runtimeFallbackTip); ?>
        </div>
        <?php endif; ?>

        <div class="qrcode-container">
            <div class="qrcode">
                <img src="<?php echo htmlspecialchars($qrCodeUrl); ?>" alt="支付二维码">
            </div>
            <?php if ($isAlipay && $payUrl !== ''): ?>
            <div style="margin-top: 15px;">
                <a href="<?php echo htmlspecialchars($payUrl); ?>" class="btn-alipay" target="_blank" rel="noopener noreferrer">
                    <i class="ri-alipay-line"></i> 点击打开支付宝
                </a>
            </div>
            <?php endif; ?>
        </div>

        <div class="pay-tips">
            <div class="pay-tips-title"><i class="ri-lightbulb-line"></i> 支付说明</div>
            <p>1. 请使用 <?php echo htmlspecialchars($payTypeName); ?> 扫描上方二维码。</p>
            <p>2. 请确保支付金额与订单金额一致。</p>
            <p>3. 支付成功后页面会自动跳转。</p>
            <p>4. 订单有效期为 5 分钟，超时请重新下单。</p>
        </div>

        <div class="status-checking">
            <i class="ri-refresh-line" style="margin-right: 5px;"></i> 正在检查支付状态<span class="loading"></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($hasQrcode || $jsapiParams || $showWechatGuide): ?>
    <script>
        var tradeNo = <?php echo json_encode($tradeNo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var returnUrl = <?php echo json_encode($order['return_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var isTestOrder = tradeNo.startsWith('TEST');
        var remainingSeconds = <?php echo (int) $remainingSeconds; ?>;

        function updateCountdown() {
            var timerEl = document.getElementById('timer');
            var countdownEl = document.getElementById('countdown');
            if (!timerEl || !countdownEl) return;

            if (remainingSeconds <= 0) {
                timerEl.textContent = '00:00';
                countdownEl.classList.add('expired');
                countdownEl.innerHTML = '<i class="ri-time-line"></i><span>订单已过期</span>';
                setTimeout(function() { alert('订单已过期，请重新下单'); location.reload(); }, 1000);
                return;
            }

            var minutes = Math.floor(remainingSeconds / 60);
            var seconds = remainingSeconds % 60;
            timerEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            remainingSeconds -= 1;
        }

        function checkStatus() {
            fetch('api/query_order.php?trade_no=' + encodeURIComponent(tradeNo))
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.status === 1) {
                        if (isTestOrder) {
                            window.location.href = 'test_success.php?trade_no=' + encodeURIComponent(tradeNo);
                        } else {
                            window.location.href = returnUrl;
                        }
                    } else if (data.status === 2) {
                        alert('订单已过期，请重新下单');
                        location.reload();
                    }
                })
                .catch(function(err) { console.error('查询失败:', err); });
        }

        updateCountdown();
        setInterval(updateCountdown, 1000);
        setInterval(checkStatus, 3000);
    </script>
    <?php endif; ?>
</body>
</html>
