<!-- 测试支付 -->
<div class="page-header">
    <h2>测试支付</h2>
    <p>生成测试订单并查看收款二维码</p>
</div>

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">生成测试订单</h3>
    </div>
    <form method="POST" action="?page=test_pay">
        <div class="form-group">
            <label>支付金额（元）</label>
            <input type="number" name="money" class="form-control" placeholder="请输入测试金额，如：0.01" step="0.01" min="0.01" required>
            <small style="color: var(--text-muted); display: block; margin-top: 8px;">
                <i class="ri-information-line"></i> 
                建议输入小额金额（如0.01）进行测试
            </small>
        </div>
        <div class="form-group">
            <label>支付方式</label>
            <select name="pay_type" class="form-control" required>
                <option value="alipay">支付宝</option>
                <option value="wxpay">微信支付</option>
            </select>
        </div>
        <button type="submit" name="generate_order" class="btn">
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
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 系统将自动跳转到支付页面显示收款二维码</p>
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 使用相应的支付APP扫描二维码完成支付</p>
        <p><i class="ri-check-line" style="color: var(--success, #10b981);"></i> 测试订单以 TEST 开头，便于识别和清理</p>
        <p style="margin-top: 16px; padding: 12px; background: rgba(255, 193, 7, 0.1); border-radius: 8px; border-left: 3px solid #ffc107;">
            <i class="ri-error-warning-line" style="color: #ffc107;"></i>
            <strong>注意：</strong>测试订单会触发真实的异步通知和回调，只是通知和回调地址是系统预设的测试地址。
        </p>
    </div>
</div>
