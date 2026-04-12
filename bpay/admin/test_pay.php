<!-- 测试支付 -->
<div class="page-header">
    <h2>测试支付</h2>
    <p>生成测试订单并查看收款二维码</p>
</div>

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">生成测试订单</h3>
    </div>
    <?php
    $merchantId = $db->getConfig('merchant_id') ?: '1000';
    $merchantKey = $db->getConfig('merchant_key') ?: '';
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }
    $submitUrl = $basePath . '/submit.php';
    $notifyUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/test_notify.php';
    $successBaseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/test_success.php';
    ?>
    <form id="test-order-form" method="POST" action="<?php echo htmlspecialchars($submitUrl); ?>">
        <div class="form-group">
            <label>支付金额（元）</label>
            <input type="number" name="money" id="money-input" class="form-control" placeholder="请输入测试金额，如：0.01" step="0.01" min="0.01" required>
            <small style="color: var(--text-muted); display: block; margin-top: 8px;">
                <i class="ri-information-line"></i> 
                建议输入小额金额（如0.01）进行测试
            </small>
        </div>
        <div class="form-group">
            <label>支付方式</label>
            <select id="pay-type-input" class="form-control" required>
                <option value="alipay">支付宝</option>
                <option value="wxpay">微信支付</option>
            </select>
        </div>
        <input type="hidden" name="pid" id="test-pid">
        <input type="hidden" name="out_trade_no" id="test-out-trade-no">
        <input type="hidden" name="notify_url" id="test-notify-url">
        <input type="hidden" name="return_url" id="test-return-url">
        <input type="hidden" name="name" id="test-name">
        <input type="hidden" name="type" id="test-type">
        <input type="hidden" name="sign" id="test-sign">
        <input type="hidden" name="sign_type" value="MD5">
        <button type="submit" class="btn">
            <i class="ri-test-tube-line"></i> 生成测试订单
        </button>
    </form>
</div>

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">使用说明</h3>
    </div>
    <div style="color: var(--text-secondary); line-height: 1.8;">
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 输入测试金额后点击"生成测试订单"按钮</p>
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 当前页面会像真实商户前端一样，直接提交到 `submit.php` 生成订单</p>
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 系统将自动跳转到支付页面显示收款二维码</p>
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 使用相应的支付APP扫描二维码完成支付</p>
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 测试订单的 `out_trade_no` 以 `TEST_OUT_` 开头，便于识别和清理</p>
        <p style="margin-top: 16px; padding: 12px; background: rgba(255, 193, 7, 0.1); border-radius: 8px; border-left: 3px solid #ffc107;">
            <i class="ri-error-warning-line" style="color: #ffc107;"></i>
            <strong>注意：</strong>测试订单会触发真实的异步通知和回调，只是通知和回调地址是系统预设的测试地址。
        </p>
    </div>
</div>

<script>
const merchantId = <?php echo json_encode($merchantId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const merchantKey = <?php echo json_encode($merchantKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const notifyUrl = <?php echo json_encode($notifyUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const successBaseUrl = <?php echo json_encode($successBaseUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

document.getElementById('test-order-form').addEventListener('submit', async function (event) {
    event.preventDefault();

    const moneyInput = document.getElementById('money-input');
    const payTypeInput = document.getElementById('pay-type-input');
    const amount = Number.parseFloat(moneyInput.value || '0');

    if (!Number.isFinite(amount) || amount <= 0) {
        alert('请输入有效金额');
        moneyInput.focus();
        return;
    }

    moneyInput.value = amount.toFixed(2);

    const outTradeNo = buildOutTradeNo();
    const params = {
        pid: merchantId,
        type: payTypeInput.value,
        out_trade_no: outTradeNo,
        notify_url: notifyUrl,
        return_url: `${successBaseUrl}?out_trade_no=${encodeURIComponent(outTradeNo)}`,
        name: '测试支付商品',
        money: moneyInput.value
    };

    document.getElementById('test-pid').value = params.pid;
    document.getElementById('test-type').value = params.type;
    document.getElementById('test-out-trade-no').value = params.out_trade_no;
    document.getElementById('test-notify-url').value = params.notify_url;
    document.getElementById('test-return-url').value = params.return_url;
    document.getElementById('test-name').value = params.name;
    document.getElementById('test-sign').value = md5(buildSignString(params) + merchantKey);

    event.target.submit();
});

function buildOutTradeNo() {
    const now = new Date();
    const pad = (value, size = 2) => String(value).padStart(size, '0');
    const timestamp = [
        now.getFullYear(),
        pad(now.getMonth() + 1),
        pad(now.getDate()),
        pad(now.getHours()),
        pad(now.getMinutes()),
        pad(now.getSeconds())
    ].join('');
    const random = Math.floor(Math.random() * 9000) + 1000;
    return `TEST_OUT_${timestamp}${random}`;
}

function buildSignString(params) {
    return Object.keys(params)
        .sort()
        .filter((key) => params[key] !== '' && params[key] !== null && params[key] !== undefined)
        .map((key) => `${key}=${params[key]}`)
        .join('&');
}

function md5(string) {
    function safeAdd(x, y) {
        const lsw = (x & 0xffff) + (y & 0xffff);
        const msw = (x >>> 16) + (y >>> 16) + (lsw >>> 16);
        return (msw << 16) | (lsw & 0xffff);
    }

    function bitRotateLeft(num, cnt) {
        return (num << cnt) | (num >>> (32 - cnt));
    }

    function md5cmn(q, a, b, x, s, t) {
        return safeAdd(bitRotateLeft(safeAdd(safeAdd(a, q), safeAdd(x, t)), s), b);
    }

    function md5ff(a, b, c, d, x, s, t) {
        return md5cmn((b & c) | ((~b) & d), a, b, x, s, t);
    }

    function md5gg(a, b, c, d, x, s, t) {
        return md5cmn((b & d) | (c & (~d)), a, b, x, s, t);
    }

    function md5hh(a, b, c, d, x, s, t) {
        return md5cmn(b ^ c ^ d, a, b, x, s, t);
    }

    function md5ii(a, b, c, d, x, s, t) {
        return md5cmn(c ^ (b | (~d)), a, b, x, s, t);
    }

    function binlMD5(x, len) {
        x[len >> 5] |= 0x80 << (len % 32);
        x[(((len + 64) >>> 9) << 4) + 14] = len;

        let i;
        let olda;
        let oldb;
        let oldc;
        let oldd;
        let a = 1732584193;
        let b = -271733879;
        let c = -1732584194;
        let d = 271733878;

        for (i = 0; i < x.length; i += 16) {
            olda = a;
            oldb = b;
            oldc = c;
            oldd = d;

            a = md5ff(a, b, c, d, x[i], 7, -680876936);
            d = md5ff(d, a, b, c, x[i + 1], 12, -389564586);
            c = md5ff(c, d, a, b, x[i + 2], 17, 606105819);
            b = md5ff(b, c, d, a, x[i + 3], 22, -1044525330);
            a = md5ff(a, b, c, d, x[i + 4], 7, -176418897);
            d = md5ff(d, a, b, c, x[i + 5], 12, 1200080426);
            c = md5ff(c, d, a, b, x[i + 6], 17, -1473231341);
            b = md5ff(b, c, d, a, x[i + 7], 22, -45705983);
            a = md5ff(a, b, c, d, x[i + 8], 7, 1770035416);
            d = md5ff(d, a, b, c, x[i + 9], 12, -1958414417);
            c = md5ff(c, d, a, b, x[i + 10], 17, -42063);
            b = md5ff(b, c, d, a, x[i + 11], 22, -1990404162);
            a = md5ff(a, b, c, d, x[i + 12], 7, 1804603682);
            d = md5ff(d, a, b, c, x[i + 13], 12, -40341101);
            c = md5ff(c, d, a, b, x[i + 14], 17, -1502002290);
            b = md5ff(b, c, d, a, x[i + 15], 22, 1236535329);

            a = md5gg(a, b, c, d, x[i + 1], 5, -165796510);
            d = md5gg(d, a, b, c, x[i + 6], 9, -1069501632);
            c = md5gg(c, d, a, b, x[i + 11], 14, 643717713);
            b = md5gg(b, c, d, a, x[i], 20, -373897302);
            a = md5gg(a, b, c, d, x[i + 5], 5, -701558691);
            d = md5gg(d, a, b, c, x[i + 10], 9, 38016083);
            c = md5gg(c, d, a, b, x[i + 15], 14, -660478335);
            b = md5gg(b, c, d, a, x[i + 4], 20, -405537848);
            a = md5gg(a, b, c, d, x[i + 9], 5, 568446438);
            d = md5gg(d, a, b, c, x[i + 14], 9, -1019803690);
            c = md5gg(c, d, a, b, x[i + 3], 14, -187363961);
            b = md5gg(b, c, d, a, x[i + 8], 20, 1163531501);
            a = md5gg(a, b, c, d, x[i + 13], 5, -1444681467);
            d = md5gg(d, a, b, c, x[i + 2], 9, -51403784);
            c = md5gg(c, d, a, b, x[i + 7], 14, 1735328473);
            b = md5gg(b, c, d, a, x[i + 12], 20, -1926607734);

            a = md5hh(a, b, c, d, x[i + 5], 4, -378558);
            d = md5hh(d, a, b, c, x[i + 8], 11, -2022574463);
            c = md5hh(c, d, a, b, x[i + 11], 16, 1839030562);
            b = md5hh(b, c, d, a, x[i + 14], 23, -35309556);
            a = md5hh(a, b, c, d, x[i + 1], 4, -1530992060);
            d = md5hh(d, a, b, c, x[i + 4], 11, 1272893353);
            c = md5hh(c, d, a, b, x[i + 7], 16, -155497632);
            b = md5hh(b, c, d, a, x[i + 10], 23, -1094730640);
            a = md5hh(a, b, c, d, x[i + 13], 4, 681279174);
            d = md5hh(d, a, b, c, x[i], 11, -358537222);
            c = md5hh(c, d, a, b, x[i + 3], 16, -722521979);
            b = md5hh(b, c, d, a, x[i + 6], 23, 76029189);
            a = md5hh(a, b, c, d, x[i + 9], 4, -640364487);
            d = md5hh(d, a, b, c, x[i + 12], 11, -421815835);
            c = md5hh(c, d, a, b, x[i + 15], 16, 530742520);
            b = md5hh(b, c, d, a, x[i + 2], 23, -995338651);

            a = md5ii(a, b, c, d, x[i], 6, -198630844);
            d = md5ii(d, a, b, c, x[i + 7], 10, 1126891415);
            c = md5ii(c, d, a, b, x[i + 14], 15, -1416354905);
            b = md5ii(b, c, d, a, x[i + 5], 21, -57434055);
            a = md5ii(a, b, c, d, x[i + 12], 6, 1700485571);
            d = md5ii(d, a, b, c, x[i + 3], 10, -1894986606);
            c = md5ii(c, d, a, b, x[i + 10], 15, -1051523);
            b = md5ii(b, c, d, a, x[i + 1], 21, -2054922799);
            a = md5ii(a, b, c, d, x[i + 8], 6, 1873313359);
            d = md5ii(d, a, b, c, x[i + 15], 10, -30611744);
            c = md5ii(c, d, a, b, x[i + 6], 15, -1560198380);
            b = md5ii(b, c, d, a, x[i + 13], 21, 1309151649);
            a = md5ii(a, b, c, d, x[i + 4], 6, -145523070);
            d = md5ii(d, a, b, c, x[i + 11], 10, -1120210379);
            c = md5ii(c, d, a, b, x[i + 2], 15, 718787259);
            b = md5ii(b, c, d, a, x[i + 9], 21, -343485551);

            a = safeAdd(a, olda);
            b = safeAdd(b, oldb);
            c = safeAdd(c, oldc);
            d = safeAdd(d, oldd);
        }

        return [a, b, c, d];
    }

    function binl2rstr(input) {
        let i;
        let output = '';
        const length32 = input.length * 32;
        for (i = 0; i < length32; i += 8) {
            output += String.fromCharCode((input[i >> 5] >>> (i % 32)) & 0xff);
        }
        return output;
    }

    function rstr2binl(input) {
        let i;
        const output = [];
        output[(input.length >> 2) - 1] = undefined;
        for (i = 0; i < output.length; i += 1) {
            output[i] = 0;
        }
        const length8 = input.length * 8;
        for (i = 0; i < length8; i += 8) {
            output[i >> 5] |= (input.charCodeAt(i / 8) & 0xff) << (i % 32);
        }
        return output;
    }

    function rstrMD5(input) {
        return binl2rstr(binlMD5(rstr2binl(input), input.length * 8));
    }

    function rstr2hex(input) {
        const hexTab = '0123456789abcdef';
        let output = '';
        let x;
        let i;
        for (i = 0; i < input.length; i += 1) {
            x = input.charCodeAt(i);
            output += hexTab.charAt((x >>> 4) & 0x0f) + hexTab.charAt(x & 0x0f);
        }
        return output;
    }

    function str2rstrUTF8(input) {
        return unescape(encodeURIComponent(input));
    }

    return rstr2hex(rstrMD5(str2rstrUTF8(string)));
}
</script>
