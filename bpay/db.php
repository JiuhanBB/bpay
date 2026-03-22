<?php
/**
 * BPay SQLite数据库操作类
 * 支持虚拟主机部署
 */

class BPayDB {
    private $db;
    public $dbFile;
    
    public function __construct($dbFile = 'bpay.db') {
        $this->dbFile = $dbFile;
        // 自动创建数据库文件所在目录
        $dbDir = dirname($dbFile);
        if ($dbDir !== '.' && !is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }
        $this->connect();
        // 自动初始化表（如果不存在）
        $this->initTables();
    }
    
    /**
     * 连接数据库
     */
    private function connect() {
        try {
            $this->db = new PDO('sqlite:' . $this->dbFile);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 初始化数据库表
     */
    public function initTables() {
        // 订单表
        $this->db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            trade_no VARCHAR(64) UNIQUE,
            out_trade_no VARCHAR(64),
            merchant_id VARCHAR(32),
            name VARCHAR(255),
            money DECIMAL(10,2),
            type VARCHAR(20),
            notify_url VARCHAR(500),
            return_url VARCHAR(500),
            status INTEGER DEFAULT 0,
            notify_status INTEGER DEFAULT 0,
            create_time INTEGER,
            pay_time INTEGER
        )");
        
        // 配置表
        $this->db->exec("CREATE TABLE IF NOT EXISTS config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(50) UNIQUE,
            value TEXT
        )");
        
        // 插入默认配置
        $this->initDefaultConfig();
    }
    
    /**
     * 初始化默认配置
     */
    private function initDefaultConfig() {
        $defaultConfigs = [
            'merchant_id' => '1000',
            'merchant_key' => $this->generateRandomKey(),
            'notify_key' => $this->generateRandomKey(), // Python工具通信密钥
            'admin_password' => md5('admin123'), // 默认密码
        ];
        
        foreach ($defaultConfigs as $name => $value) {
            $stmt = $this->db->prepare("INSERT OR IGNORE INTO config (name, value) VALUES (:name, :value)");
            $stmt->execute([':name' => $name, ':value' => $value]);
        }
    }
    
    /**
     * 生成随机密钥
     */
    private function generateRandomKey() {
        return md5(uniqid() . time() . rand(1000, 9999));
    }
    
    /**
     * 获取配置
     */
    public function getConfig($name) {
        $stmt = $this->db->prepare("SELECT value FROM config WHERE name = :name");
        $stmt->execute([':name' => $name]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : null;
    }
    
    /**
     * 设置配置
     */
    public function setConfig($name, $value) {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO config (name, value) VALUES (:name, :value)");
        return $stmt->execute([':name' => $name, ':value' => $value]);
    }
    
    /**
     * 创建订单
     */
    public function createOrder($data) {
        $stmt = $this->db->prepare("INSERT INTO orders 
            (trade_no, out_trade_no, merchant_id, name, money, type, notify_url, return_url, status, create_time) 
            VALUES 
            (:trade_no, :out_trade_no, :merchant_id, :name, :money, :type, :notify_url, :return_url, :status, :create_time)");
        
        return $stmt->execute([
            ':trade_no' => $data['trade_no'],
            ':out_trade_no' => $data['out_trade_no'],
            ':merchant_id' => $data['merchant_id'],
            ':name' => $data['name'],
            ':money' => $data['money'],
            ':type' => $data['type'],
            ':notify_url' => $data['notify_url'],
            ':return_url' => $data['return_url'],
            ':status' => 0,
            ':create_time' => time()
        ]);
    }
    
    /**
     * 根据订单号获取订单
     */
    public function getOrderByTradeNo($tradeNo) {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE trade_no = :trade_no");
        $stmt->execute([':trade_no' => $tradeNo]);
        return $stmt->fetch();
    }
    
    /**
     * 根据商户订单号获取订单
     */
    public function getOrderByOutTradeNo($outTradeNo) {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE out_trade_no = :out_trade_no");
        $stmt->execute([':out_trade_no' => $outTradeNo]);
        return $stmt->fetch();
    }
    
    /**
     * 根据金额查找待支付订单
     */
    public function getPendingOrderByMoney($money) {
        // 使用CAST确保金额比较时类型一致
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE CAST(money AS REAL) = CAST(:money AS REAL) AND status = 0 ORDER BY create_time DESC LIMIT 1");
        $stmt->execute([':money' => $money]);
        return $stmt->fetch();
    }
    
    /**
     * 更新订单状态
     */
    public function updateOrderStatus($tradeNo, $status) {
        $stmt = $this->db->prepare("UPDATE orders SET status = :status, pay_time = :pay_time WHERE trade_no = :trade_no");
        return $stmt->execute([
            ':status' => $status,
            ':pay_time' => time(),
            ':trade_no' => $tradeNo
        ]);
    }
    
    /**
     * 更新订单通知状态
     */
    public function updateNotifyStatus($tradeNo, $notifyStatus) {
        $stmt = $this->db->prepare("UPDATE orders SET notify_status = :notify_status WHERE trade_no = :trade_no");
        return $stmt->execute([
            ':notify_status' => $notifyStatus,
            ':trade_no' => $tradeNo
        ]);
    }
    
    /**
     * 手动更新订单状态（用于管理员操作）
     */
    public function manualUpdateOrderStatus($tradeNo, $status) {
        $sql = "UPDATE orders SET status = :status";
        $params = [':status' => $status, ':trade_no' => $tradeNo];
        
        // 如果设置为已支付，同时设置支付时间
        if ($status == 1) {
            $sql .= ", pay_time = :pay_time";
            $params[':pay_time'] = time();
        }
        
        $sql .= " WHERE trade_no = :trade_no";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * 获取订单列表
     */
    public function getOrderList($limit = 50) {
        $stmt = $this->db->prepare("SELECT * FROM orders ORDER BY create_time DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * 生成唯一订单号
     */
    public function generateTradeNo() {
        return date('YmdHis') . rand(1000, 9999);
    }
    
    /**
     * 获取分页订单列表
     */
    public function getOrderListPaged($limit, $offset) {
        $stmt = $this->db->prepare("SELECT * FROM orders ORDER BY create_time DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * 获取订单总数
     */
    public function getOrderCount() {
        $stmt = $this->db->query("SELECT COUNT(*) FROM orders");
        return $stmt->fetchColumn();
    }
    
    /**
     * 根据状态获取订单数量
     */
    public function getOrderCountByStatus($status) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE status = :status");
        $stmt->execute([':status' => $status]);
        return $stmt->fetchColumn();
    }
    
    /**
     * 根据金额范围查找待支付订单（模糊匹配）
     */
    public function getPendingOrderByMoneyRange($minMoney, $maxMoney) {
        // 使用CAST确保金额比较时类型一致
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE CAST(money AS REAL) BETWEEN CAST(:min_money AS REAL) AND CAST(:max_money AS REAL) AND status = 0 ORDER BY create_time DESC LIMIT 1");
        $stmt->execute([':min_money' => $minMoney, ':max_money' => $maxMoney]);
        return $stmt->fetch();
    }
    
    /**
     * 检查金额是否已存在（待支付状态）
     */
    public function isMoneyExists($money) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE CAST(money AS REAL) = CAST(:money AS REAL) AND status = 0");
        $stmt->execute([':money' => $money]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * 获取唯一金额（自动微调避免重复）
     * 如果金额已存在，自动增加0.01直到找到唯一金额
     */
    public function getUniqueMoney($baseMoney) {
        $money = round(floatval($baseMoney), 2);
        $maxAttempts = 100; // 最大尝试次数
        $attempt = 0;
        
        while ($this->isMoneyExists($money) && $attempt < $maxAttempts) {
            $money = round($money + 0.01, 2);
            $attempt++;
        }
        
        return $money;
    }
}
