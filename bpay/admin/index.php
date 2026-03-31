<?php
/**
 * BPay 管理后台 - 主题切换 + 日志管理 + JWT认证
 */

require_once '../db.php';
require_once '../lib/Logger.php';

// JWT密钥
$jwtSecret = 'XANOon14r3CuIvJNS8pQ5MkAZJFoGvUDrtF66aj1tjA8ocDlw02oNkkYTKduJnjv8K15Gebvf32aeq8bGmiEkC';

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

// 检查登录
if (empty($_COOKIE['bpay_token']) || !verifyJWT($_COOKIE['bpay_token'], $jwtSecret)) {
    header('Location: login.php');
    exit;
}

$db = new BPayDB('../bpay.db');
$message = '';
$error = '';

// 处理主题切换
if (isset($_GET['toggle_theme'])) {
    $currentTheme = $_COOKIE['bpay_theme'] ?? 'dark';
    $newTheme = $currentTheme === 'dark' ? 'light' : 'dark';
    setcookie('bpay_theme', $newTheme, time() + 86400 * 365, '/');
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

// 获取当前主题
$theme = $_COOKIE['bpay_theme'] ?? 'dark';

// 处理 AJAX 请求
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // 搜索订单
    if ($_GET['ajax'] === 'search_orders') {
        // 先清理超时订单
        $db->cancelExpiredOrders();
        
        $keyword = $_GET['keyword'] ?? '';
        $status = $_GET['status'] ?? '';
        $type = $_GET['type'] ?? '';
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        $page = intval($_GET['p'] ?? 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        
        $orders = $db->searchOrders($keyword, $status, $type, $startDate, $endDate, $perPage, $offset);
        $total = $db->getSearchOrderCount($keyword, $status, $type, $startDate, $endDate);
        
        echo json_encode([
            'success' => true,
            'orders' => $orders,
            'total' => $total,
            'page' => $page,
            'totalPages' => ceil($total / $perPage)
        ]);
        exit;
    }
    
    // 更新订单状态
    if ($_GET['ajax'] === 'update_order_status') {
        $tradeNo = $_POST['trade_no'] ?? '';
        $newStatus = intval($_POST['new_status'] ?? 0);
        
        if (!empty($tradeNo) && in_array($newStatus, [0, 1, 2])) {
            // 获取当前订单信息
            $order = $db->getOrderByTradeNo($tradeNo);
            
            if ($order && $order['status'] == 2 && $newStatus == 0) {
                // 如果是从已取消恢复到待支付，更新创建时间为当前时间
                $result = $db->restoreCancelledOrder($tradeNo);
            } else {
                $result = $db->manualUpdateOrderStatus($tradeNo, $newStatus);
            }
            
            if ($result) {
                $logger = new Logger();
                $logger->log('admin', '手动更新订单状态', [
                    'trade_no' => $tradeNo,
                    'new_status' => $newStatus,
                    'old_status' => $order['status'] ?? 'unknown'
                ]);
                echo json_encode(['success' => true, 'message' => '状态更新成功']);
            } else {
                echo json_encode(['success' => false, 'message' => '更新失败']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '参数错误']);
        }
        exit;
    }
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_config'])) {
    if (!empty($_POST['merchant_key'])) {
        $db->setConfig('merchant_key', $_POST['merchant_key']);
    }
    if (!empty($_POST['admin_password'])) {
        $db->setConfig('admin_password', md5($_POST['admin_password']));
    }
    $message = '配置已更新';
}

// 处理日志设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_log_config'])) {
    $logEnabled = isset($_POST['log_enabled']) && $_POST['log_enabled'] == '1';
    $configFile = '../config.php';
    $configContent = "<?php\n/**\n * BPay 配置文件\n */\n\nreturn [\n    // 日志开关\n    'log_enabled' => " . ($logEnabled ? 'true' : 'false') . ",\n];\n";
    file_put_contents($configFile, $configContent);
    $message = '日志设置已保存';
}

// 处理当面付配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_alipay_config'])) {
    $appId = trim($_POST['alipay_app_id'] ?? '');
    $privateKey = trim($_POST['alipay_private_key'] ?? '');
    $publicKey = trim($_POST['alipay_public_key'] ?? '');
    $payMode = $_POST['alipay_pay_mode'] ?? 'auto';

    $db->setConfig('alipay_app_id', $appId);
    $db->setConfig('alipay_private_key', $privateKey);
    $db->setConfig('alipay_public_key', $publicKey);
    $db->setConfig('alipay_pay_mode', $payMode);

    $message = '支付宝当面付配置已保存';
}

// 处理当面付JSON配置导入
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['alipay_json_config'])) {
    $jsonContent = file_get_contents($_FILES['alipay_json_config']['tmp_name']);
    $config = json_decode($jsonContent, true);

    if ($config && isset($config['app_id']) && isset($config['private_key'])) {
        $db->setConfig('alipay_app_id', $config['app_id']);
        $db->setConfig('alipay_private_key', $config['private_key']);
        $db->setConfig('alipay_public_key', $config['public_key'] ?? '');
        $message = '支付宝配置导入成功';
    } else {
        $error = '配置文件格式错误';
    }
}

// 处理收款码上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['alipay_qrcode'])) {
    $targetDir = '../assets/images/';
    // 自动创建目录
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $target = $targetDir . 'alipay_qrcode.png';
    if (move_uploaded_file($_FILES['alipay_qrcode']['tmp_name'], $target)) {
        $message = '支付宝收款码上传成功';
    } else {
        $error = '上传失败';
    }
}
// 获取当前页面（必须在处理逻辑前）
$page = $_GET['page'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['wxpay_qrcode'])) {
    $targetDir = '../assets/images/';
    // 自动创建目录
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $target = $targetDir . 'wxpay_qrcode.png';
    if (move_uploaded_file($_FILES['wxpay_qrcode']['tmp_name'], $target)) {
        $message = '微信收款码上传成功';
    } else {
        $error = '上传失败';
    }
}

// 处理测试订单生成（必须在header输出前）
if ($page == 'test_pay' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_order'])) {
    $money = floatval($_POST['money'] ?? 0);
    $payType = $_POST['pay_type'] ?? 'alipay';

    if ($money > 0) {
        // 获取唯一金额（自动微调避免重复）
        $uniqueMoney = $db->getUniqueMoney($money);

        // 生成测试订单号（以TEST开头）
        $tradeNo = 'TEST' . date('YmdHis') . rand(1000, 9999);
        $outTradeNo = 'TEST_OUT_' . date('YmdHis') . rand(1000, 9999);
        $merchantId = $db->getConfig('merchant_id') ?: 'TEST_MERCHANT';

        // 创建订单数据 - 使用真实的notify_url和return_url
        $orderData = [
            'trade_no' => $tradeNo,
            'out_trade_no' => $outTradeNo,
            'merchant_id' => $merchantId,
            'name' => '测试支付商品',
            'money' => $uniqueMoney,           // 微调后的金额
            'original_money' => $money,       // 原始金额
            'type' => $payType,
            'notify_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/test_notify.php',
            'return_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/test_success.php'
        ];
        
        // 保存订单到数据库
        try {
            $result = $db->createOrder($orderData);
            if ($result) {
                // 记录日志
                $logger = new Logger();
                $logger->log('incoming', '生成测试订单', [
                    'trade_no' => $tradeNo,
                    'money' => $money,
                    'type' => $payType
                ]);
                
                // 跳转到支付页面
                header('Location: ../pay.php?trade_no=' . $tradeNo);
                exit;
            } else {
                $error = '订单创建失败：数据库插入返回false';
            }
        } catch (Exception $e) {
            $error = '订单创建失败：' . $e->getMessage();
        }
    } else {
        $error = '请输入有效的金额';
    }
}

// 订单状态更新已改为 AJAX 方式，无需表单提交处理

// 获取配置
$merchantId = $db->getConfig('merchant_id');
$merchantKey = $db->getConfig('merchant_key');

// 加载日志配置
$configFile = '../config.php';
$config = file_exists($configFile) ? require $configFile : ['log_enabled' => true];

// 订单分页和搜索
$perPage = 20;
$currentPage = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($currentPage - 1) * $perPage;

// 搜索参数
$searchKeyword = $_GET['keyword'] ?? '';
$searchStatus = $_GET['status'] ?? '';
$searchType = $_GET['type'] ?? '';
$searchStartDate = $_GET['start_date'] ?? '';
$searchEndDate = $_GET['end_date'] ?? '';
$isSearch = !empty($searchKeyword) || $searchStatus !== '' || $searchType !== '' || !empty($searchStartDate) || !empty($searchEndDate);

// 获取订单列表和总数
if ($isSearch) {
    $orders = $db->searchOrders($searchKeyword, $searchStatus, $searchType, $searchStartDate, $searchEndDate, $perPage, $offset);
    $totalOrders = $db->getSearchOrderCount($searchKeyword, $searchStatus, $searchType, $searchStartDate, $searchEndDate);
} else {
    $orders = $db->getOrderListPaged($perPage, $offset);
    $totalOrders = $db->getOrderCount();
}
$totalPages = ceil($totalOrders / $perPage);

// 统计
$pendingOrders = $db->getOrderCountByStatus(0);
$paidOrders = $db->getOrderCountByStatus(1);
$cancelledOrders = $db->getOrderCountByStatus(2);

// 获取日志日期列表（用于日志页面）
$logger = new Logger();
$logDates = $logger->getLogDates();

include 'header.php';
?>

<!-- 侧边栏 -->
<aside class="sidebar">
    <div class="sidebar-header">
        <h1><i class="ri-wallet-3-line"></i> <span>BPay</span></h1>
        <a href="?toggle_theme=1" class="theme-toggle" title="切换主题">
            <i class="ri-<?php echo $theme === 'dark' ? 'sun' : 'moon'; ?>-line"></i>
        </a>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">主菜单</div>
            <a href="?page=dashboard" class="nav-item <?php echo $page == 'dashboard' ? 'active' : ''; ?>">
                <i class="ri-dashboard-line"></i>
                <span>数据概览</span>
            </a>
            <a href="?page=orders" class="nav-item <?php echo $page == 'orders' ? 'active' : ''; ?>">
                <i class="ri-file-list-line"></i>
                <span>订单管理</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">调试</div>
            <a href="?page=logs" class="nav-item <?php echo $page == 'logs' ? 'active' : ''; ?>">
                <i class="ri-bug-line"></i>
                <span>请求日志</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">配置</div>
            <a href="?page=config" class="nav-item <?php echo $page == 'config' ? 'active' : ''; ?>">
                <i class="ri-settings-3-line"></i>
                <span>商户配置</span>
            </a>
            <a href="?page=alipay" class="nav-item <?php echo $page == 'alipay' ? 'active' : ''; ?>">
                <i class="ri-alipay-line"></i>
                <span>支付宝配置</span>
            </a>
            <a href="?page=qrcode" class="nav-item <?php echo $page == 'qrcode' ? 'active' : ''; ?>">
                <i class="ri-qr-code-line"></i>
                <span>收款码</span>
            </a>
            <a href="?page=test_pay" class="nav-item <?php echo $page == 'test_pay' ? 'active' : ''; ?>">
                <i class="ri-test-tube-line"></i>
                <span>测试支付</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">开发</div>
            <a href="?page=docs" class="nav-item <?php echo $page == 'docs' ? 'active' : ''; ?>">
                <i class="ri-book-open-line"></i>
                <span>开发文档</span>
            </a>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn">
            <i class="ri-logout-box-line"></i>
            <span>退出登录</span>
        </a>
    </div>
</aside>

<!-- 主内容 -->
<main class="main-content">
    <?php if ($message): ?>
    <div class="alert alert-success">
        <i class="ri-checkbox-circle-line"></i>
        <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="ri-error-warning-line"></i>
        <?php echo $error; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($page == 'dashboard'): ?>
    <!-- 数据概览 -->
    <div class="page-header">
        <h2>数据概览</h2>
        <p>实时监控您的支付数据</p>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="ri-file-list-3-line"></i>
            </div>
            <div class="stat-value"><?php echo number_format($totalOrders); ?></div>
            <div class="stat-label">总订单数</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="ri-time-line"></i>
            </div>
            <div class="stat-value"><?php echo number_format($pendingOrders); ?></div>
            <div class="stat-label">待支付</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="ri-check-double-line"></i>
            </div>
            <div class="stat-value"><?php echo number_format($paidOrders); ?></div>
            <div class="stat-label">已支付</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(255, 71, 87, 0.1); color: #ff4757;">
                <i class="ri-close-circle-line"></i>
            </div>
            <div class="stat-value"><?php echo number_format($cancelledOrders); ?></div>
            <div class="stat-label">已取消</div>
        </div>
    </div>
    
    <!-- 最近订单 -->
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">最近订单</h3>
            <a href="?page=orders" class="btn btn-outline" style="padding: 10px 20px; font-size: 13px;">
                查看全部 <i class="ri-arrow-right-line"></i>
            </a>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>订单号</th>
                    <th>商品</th>
                    <th>金额</th>
                    <th>状态</th>
                    <th>时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($orders, 0, 5) as $order): ?>
                <tr>
                    <td><?php echo substr($order['trade_no'], -12); ?>...</td>
                    <td><?php echo htmlspecialchars(mb_substr($order['name'], 0, 20)); ?></td>
                    <td>¥<?php echo $order['money']; ?></td>
                    <td>
                        <?php if ($order['status'] == 0): ?>
                            <span class="status-badge pending"><i class="ri-time-line"></i> 待支付</span>
                        <?php elseif ($order['status'] == 1): ?>
                            <span class="status-badge paid"><i class="ri-check-line"></i> 已支付</span>
                        <?php elseif ($order['status'] == 2): ?>
                            <span class="status-badge" style="background: rgba(255, 71, 87, 0.1); color: #ff4757;"><i class="ri-close-line"></i> 已取消</span>
                        <?php else: ?>
                            <span class="status-badge notified"><i class="ri-send-plane-line"></i> 已通知</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('m-d H:i', $order['create_time']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php elseif ($page == 'orders'): ?>
    <!-- 订单管理 -->
    <div class="page-header">
        <h2>订单管理</h2>
        <p>查看和管理所有订单</p>
    </div>

    <!-- 搜索和导出工具栏 -->
    <div class="content-card">
        <div class="card-header" style="flex-wrap: wrap; gap: 15px;">
            <h3 class="card-title"><i class="ri-search-line"></i> 订单搜索</h3>
            <button class="btn btn-outline" onclick="openExportModal()" style="margin-left: auto;">
                <i class="ri-download-line"></i> 导出订单
            </button>
        </div>
        
        <div class="search-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div class="form-group" style="margin-bottom: 0;">
                <input type="text" id="searchKeyword" class="form-control" placeholder="订单号/商品名称" style="padding: 12px 16px;" onkeypress="if(event.key==='Enter')searchOrders()">
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <select id="searchStatus" class="form-control" style="padding: 12px 16px;" onchange="searchOrders()">
                    <option value="">所有状态</option>
                    <option value="0">待支付</option>
                    <option value="1">已支付</option>
                    <option value="2">已取消</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <select id="searchType" class="form-control" style="padding: 12px 16px;" onchange="searchOrders()">
                    <option value="">所有支付方式</option>
                    <option value="alipay">支付宝</option>
                    <option value="wxpay">微信支付</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <input type="date" id="searchStartDate" class="form-control" placeholder="开始日期" style="padding: 12px 16px;" onchange="searchOrders()">
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <input type="date" id="searchEndDate" class="form-control" placeholder="结束日期" style="padding: 12px 16px;" onchange="searchOrders()">
            </div>
            
            <div class="form-group" style="margin-bottom: 0; display: flex; gap: 8px;">
                <button type="button" class="btn btn-sm" onclick="searchOrders()" style="padding: 10px 16px;">
                    <i class="ri-search-line"></i> 搜索
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="resetSearch()" style="padding: 10px 16px;">
                    <i class="ri-reset-left-line"></i> 重置
                </button>
            </div>
        </div>
        
        <div id="searchResultInfo" class="alert alert-success" style="margin-bottom: 20px; display: none;">
            <i class="ri-information-line"></i>
            <span id="searchResultText"></span>
        </div>
    </div>

    <div class="content-card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>平台订单号</th>
                    <th>商户订单号</th>
                    <th>商品名称</th>
                    <th>金额</th>
                    <th>支付方式</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="orderTableBody">
                <!-- AJAX 加载 -->
            </tbody>
        </table>
        
        <!-- 分页 -->
        <div class="pagination" id="orderPagination">
            <!-- AJAX 加载 -->
        </div>
    </div>
    
    <script>
    // 当前页码
    let currentPageNum = 1;
    let totalPageNum = 1;
    
    // 状态映射
    const statusMap = {
        0: { text: '待支付', class: 'pending', icon: 'ri-time-line' },
        1: { text: '已支付', class: 'paid', icon: 'ri-check-line' },
        2: { text: '已取消', class: '', icon: 'ri-close-line', style: 'background: rgba(255, 71, 87, 0.1); color: #ff4757;' }
    };
    
    // 加载订单列表
    function loadOrders(page = 1) {
        currentPageNum = page;
        
        const keyword = document.getElementById('searchKeyword').value;
        const status = document.getElementById('searchStatus').value;
        const type = document.getElementById('searchType').value;
        const startDate = document.getElementById('searchStartDate').value;
        const endDate = document.getElementById('searchEndDate').value;
        
        // 显示加载动画
        const tbody = document.getElementById('orderTableBody');
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 60px;"><div class="loading-spinner"></div><p style="margin-top: 16px; color: var(--text-secondary);">加载中...</p></td></tr>';
        
        // 构建 URL
        let url = `?ajax=search_orders&p=${page}`;
        if (keyword) url += `&keyword=${encodeURIComponent(keyword)}`;
        if (status) url += `&status=${status}`;
        if (type) url += `&type=${type}`;
        if (startDate) url += `&start_date=${startDate}`;
        if (endDate) url += `&end_date=${endDate}`;
        
        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderOrders(data.orders);
                    renderPagination(data.page, data.totalPages);
                    totalPageNum = data.totalPages;
                    
                    // 显示搜索结果
                    const isSearch = keyword || status || type || startDate || endDate;
                    if (isSearch) {
                        document.getElementById('searchResultInfo').style.display = 'block';
                        document.getElementById('searchResultText').textContent = `搜索到 ${data.total} 条订单`;
                    } else {
                        document.getElementById('searchResultInfo').style.display = 'none';
                    }
                }
            })
            .catch(err => {
                console.error('加载订单失败:', err);
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--danger);"><i class="ri-error-warning-line" style="font-size: 32px; display: block; margin-bottom: 10px;"></i>加载失败，请重试</td></tr>';
            });
    }
    
    // 渲染订单列表
    function renderOrders(orders) {
        const tbody = document.getElementById('orderTableBody');
        
        if (orders.length === 0) {
            tbody.innerHTML = '<tr class="fade-in"><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-secondary);">暂无订单</td></tr>';
            return;
        }
        
        tbody.innerHTML = orders.map((order, index) => {
            const status = statusMap[order.status] || statusMap[0];
            const payType = order.type === 'alipay' 
                ? '<i class="ri-alipay-line" style="color:#1677ff"></i> 支付宝'
                : '<i class="ri-wechat-pay-line" style="color:#07c160"></i> 微信';
            
            // 添加延迟动画
            const delay = index * 50;
            
            return `
                <tr class="slide-in" style="animation-delay: ${delay}ms">
                    <td>${order.trade_no.slice(-16)}</td>
                    <td>${escapeHtml(order.out_trade_no)}</td>
                    <td>${escapeHtml(order.name)}</td>
                    <td>¥${order.money}</td>
                    <td>${payType}</td>
                    <td>
                        <span class="status-badge ${status.class}" style="${status.style || ''}">
                            <i class="${status.icon}"></i> ${status.text}
                        </span>
                    </td>
                    <td>${formatDate(order.create_time)}</td>
                    <td>
                        <select class="form-control order-status-select" data-trade-no="${order.trade_no}" style="width:auto;display:inline-block;padding:4px 8px;font-size:12px;">
                            <option value="0" ${order.status == 0 ? 'selected' : ''}>待支付</option>
                            <option value="1" ${order.status == 1 ? 'selected' : ''}>已支付</option>
                            <option value="2" ${order.status == 2 ? 'selected' : ''}>已取消</option>
                        </select>
                    </td>
                </tr>
            `;
        }).join('');
        
        // 绑定状态变更事件
        document.querySelectorAll('.order-status-select').forEach(select => {
            select.addEventListener('change', function() {
                updateOrderStatus(this.dataset.tradeNo, this.value, this);
            });
        });
    }
    
    // 渲染分页
    function renderPagination(currentPage, totalPages) {
        const pagination = document.getElementById('orderPagination');
        
        if (totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }
        
        let html = '';
        
        // 上一页
        if (currentPage > 1) {
            html += `<button class="page-btn" onclick="loadOrders(${currentPage - 1})"><i class="ri-arrow-left-line"></i></button>`;
        } else {
            html += `<span class="page-btn disabled"><i class="ri-arrow-left-line"></i></span>`;
        }
        
        // 页码
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        for (let i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                html += `<span class="page-btn active">${i}</span>`;
            } else {
                html += `<button class="page-btn" onclick="loadOrders(${i})">${i}</button>`;
            }
        }
        
        // 下一页
        if (currentPage < totalPages) {
            html += `<button class="page-btn" onclick="loadOrders(${currentPage + 1})"><i class="ri-arrow-right-line"></i></button>`;
        } else {
            html += `<span class="page-btn disabled"><i class="ri-arrow-right-line"></i></span>`;
        }
        
        pagination.innerHTML = html;
    }
    
    // 更新订单状态
    function updateOrderStatus(tradeNo, newStatus, selectElement) {
        const statusNames = { '0': '待支付', '1': '已支付', '2': '已取消' };
        
        if (!confirm(`确定要将订单状态改为"${statusNames[newStatus]}"吗？\n\n注意：手动修改状态会影响对账，请谨慎操作！`)) {
            // 恢复原状态
            loadOrders(currentPageNum);
            return;
        }
        
        fetch('?ajax=update_order_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `trade_no=${encodeURIComponent(tradeNo)}&new_status=${newStatus}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // 刷新当前页
                loadOrders(currentPageNum);
            } else {
                alert(data.message || '更新失败');
                loadOrders(currentPageNum);
            }
        })
        .catch(err => {
            console.error('更新失败:', err);
            loadOrders(currentPageNum);
        });
    }
    
    // 搜索订单
    function searchOrders() {
        loadOrders(1);
    }
    
    // 重置搜索
    function resetSearch() {
        document.getElementById('searchKeyword').value = '';
        document.getElementById('searchStatus').value = '';
        document.getElementById('searchType').value = '';
        document.getElementById('searchStartDate').value = '';
        document.getElementById('searchEndDate').value = '';
        document.getElementById('searchResultInfo').style.display = 'none';
        loadOrders(1);
    }
    
    // 工具函数：转义 HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // 工具函数：格式化日期
    function formatDate(timestamp) {
        const date = new Date(timestamp * 1000);
        return date.toLocaleString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        }).replace(/\//g, '-');
    }
    
    // 页面加载时初始化
    document.addEventListener('DOMContentLoaded', function() {
        loadOrders(1);
    });
    </script>
    
    <?php elseif ($page == 'logs'): ?>
    <!-- 请求日志 -->
    <div class="page-header">
        <h2>请求日志</h2>
        <p>查看所有请求和响应记录</p>
    </div>
    
    <div class="content-card">
        <div class="filter-bar">
            <select class="filter-select" id="logDate">
                <option value="">今天</option>
                <?php foreach ($logDates as $date): ?>
                <option value="<?php echo $date; ?>"><?php echo $date; ?></option>
                <?php endforeach; ?>
            </select>
            <select class="filter-select" id="logType">
                <option value="all">全部类型</option>
                <option value="incoming">接收</option>
                <option value="outgoing">发送</option>
            </select>
            <button class="btn btn-outline btn-sm" onclick="refreshLogs()">
                <i class="ri-refresh-line"></i> 刷新
            </button>
            <button class="btn btn-outline btn-sm" onclick="exportLogs()">
                <i class="ri-download-line"></i> 导出
            </button>
            <button class="btn btn-danger btn-sm" onclick="clearLogs()">
                <i class="ri-delete-bin-line"></i> 清空
            </button>
        </div>
        
        <div id="logsContainer">
            <div style="text-align: center; padding: 60px; color: var(--text-muted);">
                <i class="ri-loader-4-line" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                正在加载日志...
            </div>
        </div>
        
        <div class="pagination" id="logsPagination"></div>
    </div>
    
    <!-- 日志详情模态框 -->
    <div class="modal" id="logModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">日志详情</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="modal-body" id="logDetailContent">
                <!-- 动态加载 -->
            </div>
        </div>
    </div>
    
    <script>
        let currentLogPage = 1;
        let currentLogDate = '';
        let currentLogType = 'all';
        
        // 加载日志列表
        function loadLogs(page = 1) {
            currentLogPage = page;
            const container = document.getElementById('logsContainer');
            const pagination = document.getElementById('logsPagination');
            
            container.innerHTML = '<div style="text-align: center; padding: 60px; color: var(--text-muted);"><i class="ri-loader-4-line" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>正在加载日志...</div>';
            
            fetch(`../api/logs.php?action=list&page=${page}&per_page=10&date=${currentLogDate}&type=${currentLogType}`)
                .then(res => res.json())
                .then(data => {
                    if (data.code === 'success' && data.data.logs.length > 0) {
                        renderLogs(data.data.logs);
                        renderPagination(data.data.page, data.data.pages);
                    } else {
                        container.innerHTML = '<div style="text-align: center; padding: 60px; color: var(--text-muted);"><i class="ri-inbox-line" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>暂无日志记录</div>';
                        pagination.innerHTML = '';
                    }
                })
                .catch(err => {
                    container.innerHTML = '<div style="text-align: center; padding: 60px; color: var(--danger);"><i class="ri-error-warning-line" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>加载失败</div>';
                });
        }
        
        // 渲染日志列表
        function renderLogs(logs) {
            const container = document.getElementById('logsContainer');
            let html = '';
            
            logs.forEach(log => {
                const typeClass = log.type === 'incoming' ? 'incoming' : 'outgoing';
                const typeText = log.type === 'incoming' ? '接收' : '发送';
                const typeIcon = log.type === 'incoming' ? 'ri-download-line' : 'ri-upload-line';
                
                html += `
                    <div class="log-card" onclick="showLogDetail('${log.id}')">
                        <div class="log-header">
                            <span class="status-badge ${typeClass}">
                                <i class="${typeIcon}"></i> ${typeText}
                            </span>
                            <span class="log-time">${log.datetime}</span>
                        </div>
                        <div class="log-url">${log.url}</div>
                        <div class="log-meta">
                            <span><i class="ri-code-box-line"></i> ${log.method}</span>
                            <span><i class="ri-folder-line"></i> ${log.source}</span>
                            <span><i class="ri-checkbox-circle-line"></i> ${log.status}</span>
                            ${log.has_request_body ? '<span><i class="ri-file-text-line"></i> 有请求体</span>' : ''}
                            ${log.has_response_body ? '<span><i class="ri-reply-line"></i> 有响应体</span>' : ''}
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // 渲染分页
        function renderPagination(current, total) {
            const pagination = document.getElementById('logsPagination');
            if (total <= 1) {
                pagination.innerHTML = '';
                return;
            }
            
            let html = '';
            
            // 上一页
            if (current > 1) {
                html += `<a href="javascript:loadLogs(${current - 1})" class="page-btn"><i class="ri-arrow-left-line"></i></a>`;
            } else {
                html += `<span class="page-btn disabled"><i class="ri-arrow-left-line"></i></span>`;
            }
            
            // 页码
            for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
                if (i === current) {
                    html += `<span class="page-btn active">${i}</span>`;
                } else {
                    html += `<a href="javascript:loadLogs(${i})" class="page-btn">${i}</a>`;
                }
            }
            
            // 下一页
            if (current < total) {
                html += `<a href="javascript:loadLogs(${current + 1})" class="page-btn"><i class="ri-arrow-right-line"></i></a>`;
            } else {
                html += `<span class="page-btn disabled"><i class="ri-arrow-right-line"></i></span>`;
            }
            
            pagination.innerHTML = html;
        }
        
        // 显示日志详情
        function showLogDetail(id) {
            const modal = document.getElementById('logModal');
            const content = document.getElementById('logDetailContent');
            
            modal.classList.add('active');
            content.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="ri-loader-4-line" style="font-size: 32px;"></i></div>';
            
            fetch(`../api/logs.php?action=detail&id=${id}&date=${currentLogDate}`)
                .then(res => res.json())
                .then(data => {
                    if (data.code === 'success') {
                        renderLogDetail(data.data);
                    } else {
                        content.innerHTML = '<div style="color: var(--danger);">加载失败</div>';
                    }
                });
        }
        
        // 渲染日志详情
        function renderLogDetail(log) {
            const content = document.getElementById('logDetailContent');
            
            let html = `
                <div style="margin-bottom: 24px;">
                    <div class="section-title"><i class="ri-time-line"></i> 基本信息</div>
                    <div class="code-block">时间: ${log.datetime}
类型: ${log.type}
来源: ${log.source}</div>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <div class="section-title"><i class="ri-global-line"></i> 请求信息</div>
                    <div class="code-block">${log.request.method} ${log.request.url}

Headers:
${JSON.stringify(log.request.headers, null, 2)}

Body:
${log.request.body ? JSON.stringify(log.request.body, null, 2) : '(空)'}</div>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <div class="section-title"><i class="ri-reply-line"></i> 响应信息</div>
                    <div class="code-block">Status: ${log.response.status}

Headers:
${JSON.stringify(log.response.headers, null, 2)}

Body:
${log.response.body ? JSON.stringify(log.response.body, null, 2) : '(空)'}</div>
                </div>
            `;
            
            content.innerHTML = html;
        }
        
        // 关闭模态框
        function closeModal() {
            document.getElementById('logModal').classList.remove('active');
        }
        
        // 刷新日志
        function refreshLogs() {
            loadLogs(1);
        }
        
        // 导出日志
        function exportLogs() {
            const format = confirm('导出为TXT格式？\n确定=TXT, 取消=JSON') ? 'txt' : 'json';
            window.open(`../api/logs.php?action=export&date=${currentLogDate}&format=${format}`);
        }
        
        // 清空日志
        function clearLogs() {
            if (!confirm('确定要清空日志吗？此操作不可恢复。')) return;
            
            fetch('../api/logs.php?action=delete', { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (data.code === 'success') {
                        loadLogs(1);
                    } else {
                        alert('清空失败: ' + data.msg);
                    }
                });
        }
        
        // 日期筛选
        document.getElementById('logDate')?.addEventListener('change', function() {
            currentLogDate = this.value;
            loadLogs(1);
        });
        
        // 类型筛选
        document.getElementById('logType')?.addEventListener('change', function() {
            currentLogType = this.value;
            loadLogs(1);
        });
        
        // 点击模态框外部关闭
        document.getElementById('logModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        
        // 初始加载
        loadLogs();
    </script>
    
    <?php elseif ($page == 'docs'): ?>
    <!-- 开发文档 -->
    <div class="page-header">
        <h2>开发文档</h2>
        <p>商户对接 API 接口文档</p>
    </div>

    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title"><i class="ri-book-open-line"></i> 对接流程</h3>
        </div>
        <div style="background: var(--bg-secondary); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
            <pre style="margin: 0; font-family: 'Monaco', 'Consolas', monospace; font-size: 13px; line-height: 1.6; color: var(--text-secondary);">商户系统 → 提交订单(submit.php) → 跳转收银台(pay.php) → 用户支付 → 异步通知(notify.php) → 商户系统
                                    ↓
                              支付完成 → 同步跳转(return_url)</pre>
        </div>
    </div>

    <!-- 接口信息卡片 -->
    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
        <div class="stat-card" style="cursor: pointer;" onclick="showDocSection('submit')">
            <div class="stat-icon blue">
                <i class="ri-send-plane-line"></i>
            </div>
            <div class="stat-value" style="font-size: 20px;">提交订单</div>
            <div class="stat-label">submit.php</div>
        </div>
        <div class="stat-card" style="cursor: pointer;" onclick="showDocSection('notify')">
            <div class="stat-icon green">
                <i class="ri-notification-3-line"></i>
            </div>
            <div class="stat-value" style="font-size: 20px;">异步通知</div>
            <div class="stat-label">notify.php</div>
        </div>
        <div class="stat-card" style="cursor: pointer;" onclick="showDocSection('query')">
            <div class="stat-icon purple">
                <i class="ri-search-line"></i>
            </div>
            <div class="stat-value" style="font-size: 20px;">订单查询</div>
            <div class="stat-label">query_order.php</div>
        </div>
    </div>

    <!-- 商户信息 -->
    <div class="content-card" style="margin-top: 24px;">
        <div class="card-header">
            <h3 class="card-title"><i class="ri-key-line"></i> 商户信息</h3>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 13px;">商户ID (pid)</label>
                <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px; font-family: monospace; font-size: 14px;">
                    <?php echo $merchantId; ?>
                </div>
            </div>
            <div>
                <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 13px;">商户密钥 (key)</label>
                <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px; font-family: monospace; font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
                    <span id="merchantKeyDisplay">********</span>
                    <button type="button" onclick="toggleKeyDisplay()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer;">
                        <i class="ri-eye-line" id="keyToggleIcon"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 接口详情 -->
    <div class="content-card" id="docSubmit" style="margin-top: 24px;">
        <div class="card-header">
            <h3 class="card-title"><i class="ri-send-plane-line"></i> 1. 提交订单接口</h3>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 13px;">接口地址</label>
            <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px; font-family: monospace; font-size: 14px;">
                POST <?php echo 'http://' . $_SERVER['HTTP_HOST'] . '/bpay/submit.php'; ?>
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 12px; color: var(--text-secondary); font-size: 13px;">请求参数</label>
            <table class="data-table" style="font-size: 13px;">
                <thead>
                    <tr>
                        <th>参数名</th>
                        <th>必填</th>
                        <th>类型</th>
                        <th>说明</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>pid</td><td>是</td><td>string</td><td>商户ID</td></tr>
                    <tr><td>type</td><td>是</td><td>string</td><td>支付方式：alipay/wxpay</td></tr>
                    <tr><td>out_trade_no</td><td>是</td><td>string</td><td>商户订单号，需唯一</td></tr>
                    <tr><td>notify_url</td><td>是</td><td>string</td><td>异步通知地址</td></tr>
                    <tr><td>return_url</td><td>是</td><td>string</td><td>同步跳转地址</td></tr>
                    <tr><td>name</td><td>是</td><td>string</td><td>商品名称</td></tr>
                    <tr><td>money</td><td>是</td><td>float</td><td>支付金额（元）</td></tr>
                    <tr><td>sign</td><td>是</td><td>string</td><td>签名（见下方算法）</td></tr>
                    <tr><td>sign_type</td><td>是</td><td>string</td><td>签名类型：MD5</td></tr>
                </tbody>
            </table>
        </div>

        <div>
            <label style="display: block; margin-bottom: 12px; color: var(--text-secondary); font-size: 13px;">PHP 示例代码</label>
            <div class="code-block-wrapper">
                <div class="code-header">
                    <span class="code-lang">PHP</span>
                    <button class="code-copy" onclick="copyCode(this)">
                        <i class="ri-file-copy-line"></i> 复制
                    </button>
                </div>
                <div class="code-block">
                    <pre><code class="language-php">&lt;?php
// ============================================
// 配置信息
// ============================================
$apiUrl = 'http://<?php echo $_SERVER['HTTP_HOST']; ?>/submit.php';
$merchantKey = '<?php echo $merchantKey; ?>';

// ============================================
// 订单参数
// ============================================
$params = [
    'pid' => '<?php echo $merchantId; ?>',              // 商户ID
    'type' => 'alipay',                                // 支付方式：alipay/wxpay
    'out_trade_no' => 'ORDER' . time(),                // 商户订单号（需唯一）
    'notify_url' => 'http://yourdomain/notify.php',    // 异步通知地址
    'return_url' => 'http://yourdomain/success.php',   // 同步跳转地址
    'name' => '测试商品',                               // 商品名称
    'money' => '1.00',                                 // 支付金额（元）
    'sign_type' => 'MD5'                               // 签名类型
];

// ============================================
// 生成签名并提交
// ============================================
$params['sign'] = getSign($params, $merchantKey);

// 自动提交表单
echo '&lt;form id="payform" action="' . $apiUrl . '" method="POST"&gt;';
foreach ($params as $key => $val) {
    echo '&lt;input type="hidden" name="' . $key . '" value="' . htmlspecialchars($val) . '"&gt;';
}
echo '&lt;/form&gt;';
echo '&lt;script&gt;document.getElementById("payform").submit();&lt;/script&gt;';

// ============================================
// 签名函数
// ============================================
function getSign($params, $key) {
    // 1. 去除 sign 和 sign_type 参数
    unset($params['sign'], $params['sign_type']);
    
    // 2. 按参数名 ASCII 码升序排序
    ksort($params);
    
    // 3. 拼接成字符串
    $signStr = '';
    foreach ($params as $k => $v) {
        if ($v !== '') $signStr .= $k . '=' . $v . '&';
    }
    
    // 4. 去掉最后一个 & 并追加密钥
    $signStr = rtrim($signStr, '&') . $key;
    
    // 5. MD5 加密
    return md5($signStr);
}
?&gt;</code></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="content-card" id="docNotify" style="margin-top: 24px;">
        <div class="card-header">
            <h3 class="card-title"><i class="ri-notification-3-line"></i> 2. 异步通知接口</h3>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 13px;">通知方式</label>
            <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px;">
                POST 请求到商户提供的 notify_url
            </div>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 12px; color: var(--text-secondary); font-size: 13px;">通知参数</label>
            <table class="data-table" style="font-size: 13px;">
                <thead>
                    <tr><th>参数名</th><th>说明</th></tr>
                </thead>
                <tbody>
                    <tr><td>trade_no</td><td>平台订单号</td></tr>
                    <tr><td>out_trade_no</td><td>商户订单号</td></tr>
                    <tr><td>type</td><td>支付方式</td></tr>
                    <tr><td>pid</td><td>商户ID</td></tr>
                    <tr><td>name</td><td>商品名称</td></tr>
                    <tr><td>money</td><td>支付金额</td></tr>
                    <tr><td>trade_status</td><td>交易状态：TRADE_SUCCESS</td></tr>
                    <tr><td>sign</td><td>签名</td></tr>
                </tbody>
            </table>
        </div>

        <div>
            <label style="display: block; margin-bottom: 12px; color: var(--text-secondary); font-size: 13px;">处理示例</label>
            <div class="code-block-wrapper">
                <div class="code-header">
                    <span class="code-lang">PHP</span>
                    <button class="code-copy" onclick="copyCode(this)">
                        <i class="ri-file-copy-line"></i> 复制
                    </button>
                </div>
                <div class="code-block">
                    <pre><code class="language-php">&lt;?php
// ============================================
// 配置信息
// ============================================
$merchantKey = '<?php echo $merchantKey; ?>';  // 商户密钥

// ============================================
// 接收通知参数
// ============================================
$params = $_POST;
$sign = $params['sign'];
unset($params['sign'], $params['sign_type']);

// ============================================
// 验证签名
// ============================================
$mySign = getSign($params, $merchantKey);

if ($sign === $mySign && $params['trade_status'] === 'TRADE_SUCCESS') {
    // TODO: 处理订单（注意幂等性，防止重复处理）
    // 建议：根据 out_trade_no 查询订单是否已处理
    
    echo 'success';  // 必须返回 success，否则系统会重试
} else {
    echo 'fail';
}

// ============================================
// 签名函数（与请求时相同）
// ============================================
function getSign($params, $key) {
    unset($params['sign'], $params['sign_type']);
    ksort($params);
    $signStr = '';
    foreach ($params as $k => $v) {
        if ($v !== '') $signStr .= $k . '=' . $v . '&';
    }
    return md5(rtrim($signStr, '&') . $key);
}
?&gt;</code></pre>
                </div>
            </div>
        </div>

        <div style="margin-top: 20px; padding: 16px; background: rgba(251, 146, 60, 0.1); border: 1px solid rgba(251, 146, 60, 0.2); border-radius: 8px; color: #fb923c;">
            <i class="ri-error-warning-line"></i> <strong>重要提示：</strong><br>
            1. 必须返回纯文本 "success"，否则系统会认为通知失败并重试<br>
            2. 异步通知可能会多次发送，需要根据订单号去重<br>
            3. 建议先验证签名再处理业务逻辑
        </div>
    </div>

    <div class="content-card" id="docQuery" style="margin-top: 24px;">
        <div class="card-header">
            <h3 class="card-title"><i class="ri-search-line"></i> 3. 订单查询接口（可选）</h3>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 13px;">接口地址</label>
            <div style="background: var(--bg-secondary); padding: 12px 16px; border-radius: 8px; font-family: monospace; font-size: 14px;">
                GET <?php echo 'http://' . $_SERVER['HTTP_HOST'] . '/bpay/api/query_order.php?trade_no=平台订单号'; ?>
            </div>
        </div>

        <div>
            <label style="display: block; margin-bottom: 12px; color: var(--text-secondary); font-size: 13px;">返回结果</label>
            <div class="code-block-wrapper">
                <div class="code-header">
                    <span class="code-lang">JSON</span>
                    <button class="code-copy" onclick="copyCode(this)">
                        <i class="ri-file-copy-line"></i> 复制
                    </button>
                </div>
                <div class="code-block">
                    <pre><code class="language-json">{
  "code": "success",
  "status": 0,
  "trade_no": "202401011200001234",
  "out_trade_no": "ORDER123456",
  "money": "1.00"
}</code></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="content-card" style="margin-top: 24px;">
        <div class="card-header">
            <h3 class="card-title"><i class="ri-shield-keyhole-line"></i> 签名算法</h3>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 12px; color: var(--text-secondary); font-size: 13px;">签名步骤</label>
            <ol style="color: var(--text-secondary); line-height: 2; padding-left: 20px;">
                <li>筛选参数：去除 sign 和 sign_type 参数</li>
                <li>参数排序：按参数名 ASCII 码升序排序</li>
                <li>拼接字符串：将排序后的参数拼接成 key1=value1&key2=value2 格式</li>
                <li>追加密钥：在字符串末尾追加商户密钥 key</li>
                <li>MD5加密：对完整字符串进行 MD5 加密，得到签名</li>
            </ol>
        </div>

        <div>
            <label style="display: block; margin-bottom: 12px; color: var(--text-secondary); font-size: 13px;">签名示例</label>
            <div class="code-block-wrapper">
                <div class="code-header">
                    <span class="code-lang">示例</span>
                    <button class="code-copy" onclick="copyCode(this)">
                        <i class="ri-file-copy-line"></i> 复制
                    </button>
                </div>
                <div class="code-block">
                    <pre><code>原始参数：
pid=10001&type=alipay&out_trade_no=ORDER123&money=1.00&name=测试商品

排序后：
money=1.00&name=测试商品&out_trade_no=ORDER123&pid=10001&type=alipay

拼接密钥：
money=1.00&name=测试商品&out_trade_no=ORDER123&pid=10001&type=alipay<?php echo $merchantKey; ?>

MD5加密：
sign = md5("money=1.00&name=测试商品&out_trade_no=ORDER123&pid=10001&type=alipay<?php echo $merchantKey; ?>")</code></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 初始化代码高亮
        hljs.highlightAll();
        
        // 复制代码功能
        function copyCode(btn) {
            const codeBlock = btn.closest('.code-block-wrapper').querySelector('code');
            const text = codeBlock.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="ri-check-line"></i> 已复制';
                btn.style.color = '#22c55e';
                
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.color = '';
                }, 2000);
            }).catch(err => {
                console.error('复制失败:', err);
                alert('复制失败，请手动复制');
            });
        }
        
        // 切换密钥显示
        function toggleKeyDisplay() {
            const display = document.getElementById('merchantKeyDisplay');
            const icon = document.getElementById('keyToggleIcon');
            const key = '<?php echo $merchantKey; ?>';
            
            if (display.textContent === '********') {
                display.textContent = key;
                icon.className = 'ri-eye-off-line';
            } else {
                display.textContent = '********';
                icon.className = 'ri-eye-line';
            }
        }
        
        // 显示指定文档章节
        function showDocSection(section) {
            const sections = ['submit', 'notify', 'query'];
            sections.forEach(s => {
                const el = document.getElementById('doc' + s.charAt(0).toUpperCase() + s.slice(1));
                if (el) {
                    el.style.display = s === section ? 'block' : 'none';
                }
            });
            
            // 滚动到对应章节
            const target = document.getElementById('doc' + section.charAt(0).toUpperCase() + section.slice(1));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    </script>
    
    <?php elseif ($page == 'config'): ?>
    <!-- 商户配置 -->
    <div class="page-header">
        <h2>商户配置</h2>
        <p>配置支付参数和密钥</p>
    </div>

    <!-- 手动配置 -->
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">商户配置</h3>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>商户ID</label>
                <input type="text" class="form-control" value="<?php echo $merchantId; ?>" disabled>
            </div>
            <div class="form-group">
                <label>商户密钥</label>
                <input type="text" name="merchant_key" class="form-control" value="<?php echo $merchantKey; ?>" placeholder="输入商户密钥">
            </div>
            <div class="form-group">
                <label>修改管理密码</label>
                <input type="password" name="admin_password" class="form-control" placeholder="留空则不修改">
            </div>
            <button type="submit" name="update_config" class="btn">
                <i class="ri-save-line"></i> 保存配置
            </button>
        </form>
    </div>

    <!-- 日志开关 -->
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">日志设置</h3>
        </div>
        <form method="POST" action="?page=config">
            <div class="form-group">
                <label>
                    <input type="checkbox" name="log_enabled" value="1" <?php echo ($config['log_enabled'] ?? true) ? 'checked' : ''; ?>>
                    启用请求日志记录
                </label>
                <small style="color: var(--text-muted); display: block; margin-top: 8px;">
                    <i class="ri-information-line"></i>
                    关闭后不再记录请求和响应日志，可提高性能
                </small>
            </div>
            <button type="submit" name="update_log_config" class="btn">
                <i class="ri-save-line"></i> 保存日志设置
            </button>
        </form>
    </div>
    
    <?php elseif ($page == 'alipay'): ?>
    <!-- 支付宝当面付配置 -->
    <div class="page-header">
        <h2>支付宝当面付配置</h2>
        <p>配置支付宝开放平台应用，支持当面付功能</p>
    </div>

    <?php
    $alipayAppId = $db->getConfig('alipay_app_id') ?: '';
    $alipayPrivateKey = $db->getConfig('alipay_private_key') ?: '';
    $alipayPublicKey = $db->getConfig('alipay_public_key') ?: '';
    $alipayPayMode = $db->getConfig('alipay_pay_mode') ?: 'auto';
    ?>

    <!-- JSON配置导入 -->
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">JSON配置导入</h3>
        </div>
        <p style="margin-bottom: 16px; color: var(--text-muted);">
            上传Python工具生成的服务端配置文件，自动填充下方表单
        </p>
        <form id="jsonUploadForm" method="POST" enctype="multipart/form-data">
            <div class="json-upload-wrapper">
                <div class="json-upload-area">
                    <input type="file" id="config_json" name="alipay_json_config" accept=".json" onchange="this.form.submit()">
                    <i class="ri-upload-cloud-line"></i>
                    <h4>点击或拖拽上传配置文件</h4>
                    <p>支持 .json 格式文件</p>
                </div>
            </div>
        </form>
    </div>

    <!-- 手动配置 -->
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">手动配置</h3>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>应用ID (AppId)</label>
                <input type="text" name="alipay_app_id" class="form-control" value="<?php echo htmlspecialchars($alipayAppId); ?>" placeholder="支付宝开放平台的应用ID">
                <small style="color: var(--text-muted);">在支付宝开放平台创建应用后获取</small>
            </div>
            <div class="form-group">
                <label>应用私钥 (Private Key)</label>
                <textarea name="alipay_private_key" class="form-control" rows="4" placeholder="应用私钥，用于生成请求签名"><?php echo htmlspecialchars($alipayPrivateKey); ?></textarea>
                <small style="color: var(--text-muted);">使用支付宝密钥工具生成的应用私钥</small>
            </div>
            <div class="form-group">
                <label>支付宝公钥 (Public Key)</label>
                <textarea name="alipay_public_key" class="form-control" rows="3" placeholder="支付宝公钥，用于验证响应签名"><?php echo htmlspecialchars($alipayPublicKey); ?></textarea>
                <small style="color: var(--text-muted);">在支付宝开放平台上传应用公钥后获取的支付宝公钥</small>
            </div>
            <div class="form-group">
                <label>支付方式</label>
                <select name="alipay_pay_mode" class="form-control">
                    <option value="auto" <?php echo $alipayPayMode == 'auto' ? 'selected' : ''; ?>>自动选择（优先当面付）</option>
                    <option value="face" <?php echo $alipayPayMode == 'face' ? 'selected' : ''; ?>>仅当面付</option>
                    <option value="qrcode" <?php echo $alipayPayMode == 'qrcode' ? 'selected' : ''; ?>>仅收款码</option>
                </select>
                <small style="color: var(--text-muted);">
                    自动选择：配置了当面付就用当面付，没配置就用收款码<br>
                    仅当面付：只使用支付宝当面付API生成二维码<br>
                    仅收款码：只使用上传的收款码图片
                </small>
            </div>
            <button type="submit" name="update_alipay_config" class="btn">
                <i class="ri-save-line"></i> 保存配置
            </button>
        </form>
    </div>

    <!-- 配置说明 -->
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">配置说明</h3>
        </div>
        <div style="color: var(--text-muted); line-height: 1.8;">
            <p><strong>1. 当面付优势</strong></p>
            <p>通过支付宝官方API生成订单，可准确查询订单支付状态，避免余额监控方式在多笔同时收款时的不准确问题。</p>

            <p style="margin-top: 16px;"><strong>2. 配置步骤</strong></p>
            <ol style="margin-left: 20px; margin-top: 8px;">
                <li>登录支付宝开放平台 (open.alipay.com)</li>
                <li>创建应用并开通"当面付"接口权限</li>
                <li>使用密钥工具生成应用密钥对</li>
                <li>上传应用公钥到开放平台</li>
                <li>复制应用ID、应用私钥、支付宝公钥到上方表单</li>
            </ol>

            <p style="margin-top: 16px;"><strong>3. 收款码 vs 当面付</strong></p>
            <ul style="margin-left: 20px; margin-top: 8px;">
                <li><strong>收款码</strong>：上传静态收款码图片，通过监控软件检测收款</li>
                <li><strong>当面付</strong>：动态生成订单二维码，通过API轮询查询状态</li>
            </ul>
        </div>
    </div>

    <?php elseif ($page == 'qrcode'): ?>
    <!-- 收款码 -->
    <div class="page-header">
        <h2>收款码管理</h2>
        <p>上传微信和支付宝收款码</p>
    </div>
    
    <div class="upload-grid">
        <div class="upload-card" onclick="document.getElementById('alipay_qrcode').click()">
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="alipay_qrcode" id="alipay_qrcode" accept="image/*" onchange="this.form.submit()">
                <?php if (file_exists('../assets/images/alipay_qrcode.png')): ?>
                    <img src="../assets/images/alipay_qrcode.png" class="upload-preview" alt="支付宝收款码">
                <?php else: ?>
                    <div class="upload-placeholder">
                        <i class="ri-alipay-line"></i>
                    </div>
                <?php endif; ?>
                <h4>支付宝收款码</h4>
                <p>点击上传或更换</p>
            </form>
        </div>
        
        <div class="upload-card" onclick="document.getElementById('wxpay_qrcode').click()">
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="wxpay_qrcode" id="wxpay_qrcode" accept="image/*" onchange="this.form.submit()">
                <?php if (file_exists('../assets/images/wxpay_qrcode.png')): ?>
                    <img src="../assets/images/wxpay_qrcode.png" class="upload-preview" alt="微信收款码">
                <?php else: ?>
                    <div class="upload-placeholder">
                        <i class="ri-wechat-pay-line"></i>
                    </div>
                <?php endif; ?>
                <h4>微信收款码</h4>
                <p>点击上传或更换</p>
            </form>
        </div>
    </div>
    
    <?php elseif ($page == 'test_pay'): ?>
    <!-- 测试支付 -->
    <?php include 'test_pay.php'; ?>
    
    <?php endif; ?>
</main>

<script>
    // JSON文件拖拽上传 - 兼容Safari
    document.addEventListener('DOMContentLoaded', function() {
        const uploadArea = document.querySelector('.json-upload-area');
        const fileInput = document.getElementById('config_json');
        const form = document.getElementById('jsonUploadForm');
        
        if (uploadArea && fileInput) {
            // 点击上传
            uploadArea.addEventListener('click', function() {
                fileInput.click();
            });
            
            // 拖拽事件
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.borderColor = 'var(--text-primary)';
                this.style.background = 'var(--bg-hover)';
            });
            
            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.borderColor = '';
                this.style.background = '';
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.style.borderColor = '';
                this.style.background = '';
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    // 兼容Safari
                    if (fileInput.files) {
                        fileInput.files = files;
                    } else {
                        // Safari旧版本兼容
                        const file = files[0];
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        fileInput.files = dataTransfer.files;
                    }
                    form.submit();
                }
            });
            
            // 防止表单默认提交
            form.addEventListener('submit', function(e) {
                // 正常提交，不需要阻止
            });
        }
    });

    // 侧边栏切换
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    }

    // 点击导航项后自动关闭侧边栏（移动端）
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        });
    });

    // 订单状态变更确认
    function confirmStatusChange(select) {
        const form = select.form;
        const newStatus = select.value;
        const currentStatus = select.querySelector('option[selected]')?.value || '0';
        
        if (newStatus === currentStatus) {
            return false;
        }
        
        const statusNames = {
            '0': '待支付',
            '1': '已支付',
            '2': '已取消'
        };
        
        const confirmMsg = `确定要将订单状态改为"${statusNames[newStatus]}"吗？\n\n注意：手动修改状态会影响对账，请谨慎操作！`;
        
        if (confirm(confirmMsg)) {
            return true;
        } else {
            // 恢复原选中状态
            select.value = currentStatus;
            return false;
        }
    }

    // 打开导出弹窗
    function openExportModal() {
        document.getElementById('exportModal').classList.add('active');
    }

    // 关闭导出弹窗
    function closeExportModal() {
        document.getElementById('exportModal').classList.remove('active');
    }

    // 切换自定义日期显示
    function toggleCustomDate() {
        const range = document.getElementById('exportRange').value;
        const customDateDiv = document.getElementById('customDateRange');
        if (range === 'custom') {
            customDateDiv.style.display = 'block';
        } else {
            customDateDiv.style.display = 'none';
        }
    }

    // 执行导出
    function exportOrders() {
        const range = document.getElementById('exportRange').value;
        let url = 'export_orders.php?range=' + range;
        
        if (range === 'custom') {
            const startDate = document.getElementById('exportStartDate').value;
            const endDate = document.getElementById('exportEndDate').value;
            
            if (!startDate || !endDate) {
                alert('请选择开始和结束日期');
                return;
            }
            
            url += '&start_date=' + startDate + '&end_date=' + endDate;
        }
        
        window.location.href = url;
        closeExportModal();
    }
</script>

<!-- 导出订单弹窗 -->
<div class="modal" id="exportModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="ri-download-line"></i> 导出订单</h3>
            <button class="modal-close" onclick="closeExportModal()">
                <i class="ri-close-line"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>选择时间范围</label>
                <select id="exportRange" class="form-control" onchange="toggleCustomDate()" style="padding: 14px 18px;">
                    <option value="7days">最近7天</option>
                    <option value="30days">最近30天</option>
                    <option value="1year">最近一年</option>
                    <option value="all">全部订单</option>
                    <option value="custom">自定义日期</option>
                </select>
            </div>
            
            <div id="customDateRange" style="display: none; margin-top: 20px;">
                <div class="form-group">
                    <label>开始日期</label>
                    <input type="date" id="exportStartDate" class="form-control" style="padding: 14px 18px;">
                </div>
                <div class="form-group">
                    <label>结束日期</label>
                    <input type="date" id="exportEndDate" class="form-control" style="padding: 14px 18px;">
                </div>
            </div>
            
            <div style="margin-top: 24px; display: flex; gap: 12px;">
                <button class="btn" onclick="exportOrders()" style="flex: 1;">
                    <i class="ri-download-line"></i> 导出CSV
                </button>
                <button class="btn btn-outline" onclick="closeExportModal()" style="flex: 1;">
                    取消
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
