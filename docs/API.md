# BPay 支付系统开发文档

## 系统简介

BPay 是一个集成了支付宝和微信支付的个人支付解决方案，支持支付宝当面付和收款码两种支付方式。

## 对接流程

```
商户系统 → 提交订单(submit.php) → 跳转收银台(pay.php) → 用户支付 → 异步通知(notify.php) → 商户系统
                                    ↓
                              支付完成 → 同步跳转(return_url)
```

## 接口说明

### 1. 提交订单接口

**接口地址：** `POST http://yourdomain/bpay/submit.php`

**请求参数：**

| 参数名 | 必填 | 类型 | 说明 |
|--------|------|------|------|
| pid | 是 | string | 商户ID |
| type | 是 | string | 支付方式：alipay/wxpay |
| out_trade_no | 是 | string | 商户订单号，需唯一 |
| notify_url | 是 | string | 异步通知地址 |
| return_url | 是 | string | 同步跳转地址 |
| name | 是 | string | 商品名称 |
| money | 是 | float | 支付金额（元），支持小数 |
| sign | 是 | string | 签名（见签名算法） |
| sign_type | 是 | string | 签名类型：MD5 |

**返回结果：**

成功时直接跳转到收银台页面，失败时返回 JSON：

```json
{
  "code": "error",
  "msg": "错误信息"
}
```

**示例代码（PHP）：**

```php
<?php
$apiUrl = 'http://yourdomain/bpay/submit.php';
$merchantKey = 'your_merchant_key'; // 商户密钥

$params = [
    'pid' => '10001',
    'type' => 'alipay', // 或 wxpay
    'out_trade_no' => 'ORDER' . time(),
    'notify_url' => 'http://yourdomain/notify.php',
    'return_url' => 'http://yourdomain/success.php',
    'name' => '测试商品',
    'money' => '1.00',
    'sign_type' => 'MD5'
];

// 生成签名
$params['sign'] = getSign($params, $merchantKey);

// 提交订单（自动跳转）
echo '<form id="payform" action="' . $apiUrl . '" method="POST">';
foreach ($params as $key => $val) {
    echo '<input type="hidden" name="' . $key . '" value="' . htmlspecialchars($val) . '">';
}
echo '</form>';
echo '<script>document.getElementById("payform").submit();</script>';

// 签名函数
function getSign($params, $key) {
    // 去除 sign 和 sign_type
    unset($params['sign'], $params['sign_type']);
    
    // 按参数名升序排序
    ksort($params);
    
    // 拼接字符串
    $signStr = '';
    foreach ($params as $k => $v) {
        if ($v !== '') {
            $signStr .= $k . '=' . $v . '&';
        }
    }
    $signStr = rtrim($signStr, '&');
    $signStr .= $key;
    
    return md5($signStr);
}
?>
```

---

### 2. 异步通知接口

用户支付成功后，系统会向商户提供的 `notify_url` 发送异步通知。

**通知方式：** POST

**通知参数：**

| 参数名 | 说明 |
|--------|------|
| trade_no | 平台订单号 |
| out_trade_no | 商户订单号 |
| type | 支付方式 |
| pid | 商户ID |
| name | 商品名称 |
| money | 支付金额 |
| trade_status | 交易状态：TRADE_SUCCESS |
| sign | 签名 |
| sign_type | 签名类型 |

**商户处理逻辑：**

```php
<?php
$merchantKey = 'your_merchant_key';

// 获取通知参数
$params = $_POST;

// 验证签名
$sign = $params['sign'];
unset($params['sign'], $params['sign_type']);

$mySign = getSign($params, $merchantKey);

if ($sign === $mySign && $params['trade_status'] === 'TRADE_SUCCESS') {
    // 签名验证通过且支付成功
    $outTradeNo = $params['out_trade_no'];
    $money = $params['money'];
    
    // TODO: 处理订单逻辑（更新订单状态、发货等）
    // 注意：需要防止重复处理，建议根据 out_trade_no 去重
    
    // 返回 success 表示处理成功
    echo 'success';
} else {
    echo 'fail';
}

function getSign($params, $key) {
    ksort($params);
    $signStr = '';
    foreach ($params as $k => $v) {
        if ($v !== '') {
            $signStr .= $k . '=' . $v . '&';
        }
    }
    $signStr = rtrim($signStr, '&');
    $signStr .= $key;
    return md5($signStr);
}
?>
```

**重要提示：**
- 必须返回纯文本 `success`，否则系统会认为通知失败并重试
- 异步通知可能会多次发送，商户需要做好幂等处理（根据订单号去重）
- 建议先验证签名再处理业务逻辑

---

### 3. 同步跳转

用户支付完成后，会跳转到商户提供的 `return_url`。

**跳转方式：** GET

**携带参数：**

| 参数名 | 说明 |
|--------|------|
| out_trade_no | 商户订单号 |
| trade_no | 平台订单号 |
| trade_status | 交易状态 |

**注意：** 同步跳转仅用于展示支付结果，**不要**在此处处理订单逻辑（用户可能直接关闭页面）。订单处理请在异步通知中完成。

---

### 4. 订单查询接口（可选）

**接口地址：** `GET http://yourdomain/bpay/api/query_order.php?trade_no=平台订单号`

**返回结果：**

```json
{
  "code": "success",
  "status": 0,  // 0-待支付, 1-已支付, 2-已取消
  "trade_no": "202401011200001234",
  "out_trade_no": "ORDER123456",
  "money": "1.00"
}
```

---

## 签名算法

### 签名生成步骤

1. **筛选参数**：去除 `sign` 和 `sign_type` 参数
2. **参数排序**：按参数名 ASCII 码升序排序
3. **拼接字符串**：将排序后的参数拼接成 `key1=value1&key2=value2` 格式
4. **追加密钥**：在字符串末尾追加商户密钥 `key`
5. **MD5加密**：对完整字符串进行 MD5 加密，得到签名

### 签名示例

```
原始参数：
pid=10001&type=alipay&out_trade_no=ORDER123&money=1.00&name=测试商品

排序后：
money=1.00&name=测试商品&out_trade_no=ORDER123&pid=10001&type=alipay

拼接密钥：
money=1.00&name=测试商品&out_trade_no=ORDER123&pid=10001&type=alipay商户密钥

MD5加密：
sign = md5("money=1.00&name=测试商品&out_trade_no=ORDER123&pid=10001&type=alipay商户密钥")
```

---

## 常见问题

### Q1: 如何获取商户ID和密钥？

登录管理后台 `http://yourdomain/bpay/admin/login`，在「系统设置」页面查看：
- 商户ID（pid）
- 商户密钥（key）

默认密码：admin123（登录后请立即修改）

### Q2: 支持哪些支付方式？

- **支付宝**：当面付（需配置支付宝开放平台）或收款码
- **微信支付**：收款码（需配合监控软件使用）

### Q3: 支付金额有什么限制？

- 最小金额：0.01 元
- 系统会自动在金额后添加 0.01-0.99 的随机小数，用于区分订单
- 实际支付金额以收银台显示为准

### Q4: 订单有效期多久？

订单有效期为 **5分钟**，超时后订单自动取消，用户需要重新下单。

### Q5: 异步通知没有收到怎么办？

1. 检查 `notify_url` 是否可以外网访问
2. 检查服务器是否拦截了 POST 请求
3. 查看系统日志排查问题
4. 可以使用订单查询接口主动查询订单状态

### Q6: 如何测试支付？

1. 登录管理后台
2. 进入「测试支付」页面
3. 输入金额和支付方式生成测试订单
4. 使用测试模式不会产生真实交易

---

## 安全建议

1. **密钥保管**：商户密钥不要泄露，不要放在前端代码中
2. **签名验证**：务必验证异步通知的签名，防止伪造请求
3. **幂等处理**：同一订单号不要重复处理，防止重复发货
4. **HTTPS**：生产环境建议使用 HTTPS 加密传输
5. **IP白名单**：可以在异步通知中增加 IP 白名单校验（系统通知 IP 为服务器 IP）

---

## 技术支持

如有问题，请通过以下方式联系：
- 项目地址：https://github.com/yourname/bpay
- 问题反馈：提交 GitHub Issue
