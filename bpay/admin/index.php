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
            'money' => $uniqueMoney,
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

// 处理订单状态更新
if ($page == 'orders' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $tradeNo = $_POST['trade_no'] ?? '';
    $newStatus = intval($_POST['new_status'] ?? 0);

    if (!empty($tradeNo) && in_array($newStatus, [0, 1])) {
        $result = $db->manualUpdateOrderStatus($tradeNo, $newStatus);
        if ($result) {
            // 初始化日志记录器
            require_once '../lib/Logger.php';
            $logger = new Logger();
            $logger->log('admin', '手动更新订单状态', [
                'trade_no' => $tradeNo,
                'new_status' => $newStatus
            ]);
        }
    }
    // 刷新页面
    header('Location: ?page=orders&p=' . $currentPage);
    exit;
}

// 获取配置
$merchantId = $db->getConfig('merchant_id');
$merchantKey = $db->getConfig('merchant_key');

// 加载日志配置
$configFile = '../config.php';
$config = file_exists($configFile) ? require $configFile : ['log_enabled' => true];

// 订单分页
$perPage = 20;
$currentPage = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($currentPage - 1) * $perPage;

// 获取订单列表和总数
$orders = $db->getOrderListPaged($perPage, $offset);
$totalOrders = $db->getOrderCount();
$totalPages = ceil($totalOrders / $perPage);

// 统计
$pendingOrders = $db->getOrderCountByStatus(0);
$paidOrders = $db->getOrderCountByStatus(1);

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
                    <th>通知状态</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?php echo substr($order['trade_no'], -16); ?></td>
                    <td><?php echo htmlspecialchars($order['out_trade_no']); ?></td>
                    <td><?php echo htmlspecialchars($order['name']); ?></td>
                    <td>¥<?php echo $order['money']; ?></td>
                    <td><?php echo $order['type'] == 'alipay' ? '<i class="ri-alipay-line" style="color:#1677ff"></i> 支付宝' : '<i class="ri-wechat-pay-line" style="color:#07c160"></i> 微信'; ?></td>
                    <td>
                        <?php if ($order['status'] == 0): ?>
                            <span class="status-badge pending"><i class="ri-time-line"></i> 待支付</span>
                        <?php elseif ($order['status'] == 1): ?>
                            <span class="status-badge paid"><i class="ri-check-line"></i> 已支付</span>
                        <?php else: ?>
                            <span class="status-badge notified"><i class="ri-send-plane-line"></i> 已通知</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($order['notify_status'] == 1): ?>
                            <span class="status-badge paid"><i class="ri-check-line"></i> 已通知</span>
                        <?php else: ?>
                            <span class="status-badge pending"><i class="ri-time-line"></i> 未通知</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('Y-m-d H:i:s', $order['create_time']); ?></td>
                    <td>
                        <form method="POST" action="?page=orders&p=<?php echo $currentPage; ?>" style="display:inline;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="trade_no" value="<?php echo $order['trade_no']; ?>">
                            <select name="new_status" class="form-control" style="width:auto;display:inline-block;padding:4px 8px;font-size:12px;" onchange="this.form.submit()">
                                <option value="0" <?php echo $order['status'] == 0 ? 'selected' : ''; ?>>待支付</option>
                                <option value="1" <?php echo $order['status'] == 1 ? 'selected' : ''; ?>>已支付</option>
                            </select>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($currentPage > 1): ?>
                <a href="?page=orders&p=<?php echo $currentPage - 1; ?>" class="page-btn"><i class="ri-arrow-left-line"></i></a>
            <?php else: ?>
                <span class="page-btn disabled"><i class="ri-arrow-left-line"></i></span>
            <?php endif; ?>
            
            <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                <?php if ($i == $currentPage): ?>
                    <span class="page-btn active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?page=orders&p=<?php echo $i; ?>" class="page-btn"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($currentPage < $totalPages): ?>
                <a href="?page=orders&p=<?php echo $currentPage + 1; ?>" class="page-btn"><i class="ri-arrow-right-line"></i></a>
            <?php else: ?>
                <span class="page-btn disabled"><i class="ri-arrow-right-line"></i></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
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
</script>

</body>
</html>
