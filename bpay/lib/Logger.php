<?php
/**
 * BPay 日志记录类
 * 记录所有请求和响应，支持JSON和TXT格式
 */

class Logger {
    private $logDir;
    private $logFile;
    private $loggingEnabled;

    public function __construct() {
        $this->logDir = __DIR__ . '/../logs/' . date('Y-m-d');
        $this->logFile = $this->logDir . '/requests.json';

        // 检查日志开关
        $configFile = __DIR__ . '/../config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $this->loggingEnabled = $config['log_enabled'] ?? true;
        } else {
            $this->loggingEnabled = true;
        }

        // 创建日志目录
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
    }

    /**
     * 检查日志是否启用
     */
    public function isEnabled() {
        return $this->loggingEnabled;
    }
    
    /**
     * 记录请求日志
     *
     * @param string $type 日志类型: incoming(接收) / outgoing(发送)
     * @param string $source 来源: python_notify / alipay_callback / user_notify 等
     * @param array $data 日志数据
     * @return bool
     */
    public function log($type, $source, $data) {
        // 如果日志被禁用，直接返回
        if (!$this->loggingEnabled) {
            return true;
        }

        $logEntry = [
            'id' => $this->generateId(),
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'type' => $type,        // incoming / outgoing
            'source' => $source,    // 来源标识
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'url' => $this->getCurrentUrl(),
                'headers' => $this->getRequestHeaders(),
                'body' => $data['request'] ?? []
            ],
            'response' => [
                'status' => $data['status'] ?? 200,
                'headers' => $data['response_headers'] ?? [],
                'body' => $data['response'] ?? []
            ],
            'metadata' => $data['metadata'] ?? []
        ];

        // 追加到JSON文件
        return $this->appendToJson($logEntry);
    }
    
    /**
     * 记录接收到的请求
     */
    public function logIncoming($source, $requestData, $responseData = [], $metadata = []) {
        return $this->log('incoming', $source, [
            'request' => $requestData,
            'response' => $responseData,
            'metadata' => $metadata
        ]);
    }
    
    /**
     * 记录发送出去的请求
     */
    public function logOutgoing($source, $requestData, $responseData = [], $metadata = []) {
        return $this->log('outgoing', $source, [
            'request' => $requestData,
            'response' => $responseData,
            'metadata' => $metadata
        ]);
    }
    
    /**
     * 获取日志列表（分页）
     * 
     * @param int $page 页码
     * @param int $perPage 每页数量
     * @param string $type 类型过滤: all / incoming / outgoing
     * @param string $date 日期: YYYY-MM-DD
     * @return array
     */
    public function getLogs($page = 1, $perPage = 20, $type = 'all', $date = null) {
        $logFile = $this->getLogFileByDate($date);
        
        if (!file_exists($logFile)) {
            return [
                'logs' => [],
                'total' => 0,
                'pages' => 0
            ];
        }
        
        // 读取所有日志
        $logs = [];
        $handle = fopen($logFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $log = json_decode(trim($line), true);
                if ($log) {
                    // 类型过滤
                    if ($type !== 'all' && $log['type'] !== $type) {
                        continue;
                    }
                    $logs[] = $log;
                }
            }
            fclose($handle);
        }
        
        // 按时间倒序（最新的在前）
        usort($logs, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        $total = count($logs);
        $totalPages = ceil($total / $perPage);
        
        // 分页
        $offset = ($page - 1) * $perPage;
        $pagedLogs = array_slice($logs, $offset, $perPage);
        
        // 简化返回数据（不返回完整的请求体/响应体）
        $simplifiedLogs = array_map(function($log) {
            return [
                'id' => $log['id'],
                'datetime' => $log['datetime'],
                'type' => $log['type'],
                'source' => $log['source'],
                'url' => $log['request']['url'],
                'method' => $log['request']['method'],
                'status' => $log['response']['status'],
                'has_request_body' => !empty($log['request']['body']),
                'has_response_body' => !empty($log['response']['body'])
            ];
        }, $pagedLogs);
        
        return [
            'logs' => $simplifiedLogs,
            'total' => $total,
            'pages' => $totalPages,
            'page' => $page
        ];
    }
    
    /**
     * 获取单条日志详情
     * 
     * @param string $id 日志ID
     * @param string $date 日期
     * @return array|null
     */
    public function getLogDetail($id, $date = null) {
        $logFile = $this->getLogFileByDate($date);
        
        if (!file_exists($logFile)) {
            return null;
        }
        
        $handle = fopen($logFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $log = json_decode(trim($line), true);
                if ($log && $log['id'] === $id) {
                    fclose($handle);
                    return $log;
                }
            }
            fclose($handle);
        }
        
        return null;
    }
    
    /**
     * 删除日志
     * 
     * @param string $id 日志ID，为空则删除全部
     * @param string $date 日期
     * @return bool
     */
    public function deleteLog($id = null, $date = null) {
        $logFile = $this->getLogFileByDate($date);
        
        if (!file_exists($logFile)) {
            return true;
        }
        
        // 删除全部
        if (empty($id)) {
            return unlink($logFile);
        }
        
        // 删除单条
        $logs = [];
        $handle = fopen($logFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $log = json_decode(trim($line), true);
                if ($log && $log['id'] !== $id) {
                    $logs[] = $line;
                }
            }
            fclose($handle);
        }
        
        // 重写文件
        return file_put_contents($logFile, implode('', $logs)) !== false;
    }
    
    /**
     * 导出日志
     * 
     * @param string $date 日期
     * @param string $format 格式: json / txt
     * @return string 文件内容
     */
    public function exportLogs($date = null, $format = 'json') {
        $logFile = $this->getLogFileByDate($date);
        
        if (!file_exists($logFile)) {
            return '';
        }
        
        if ($format === 'txt') {
            // 转换为可读文本格式
            $content = "BPay 日志导出 - " . ($date ?: date('Y-m-d')) . "\n";
            $content .= str_repeat('=', 80) . "\n\n";
            
            $handle = fopen($logFile, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $log = json_decode(trim($line), true);
                    if ($log) {
                        $content .= "[{$log['datetime']}] [{$log['type']}] {$log['source']}\n";
                        $content .= "URL: {$log['request']['url']}\n";
                        $content .= "Method: {$log['request']['method']}\n";
                        $content .= "Status: {$log['response']['status']}\n";
                        if (!empty($log['request']['body'])) {
                            $content .= "Request: " . json_encode($log['request']['body'], JSON_UNESCAPED_UNICODE) . "\n";
                        }
                        if (!empty($log['response']['body'])) {
                            $content .= "Response: " . json_encode($log['response']['body'], JSON_UNESCAPED_UNICODE) . "\n";
                        }
                        $content .= str_repeat('-', 80) . "\n\n";
                    }
                }
                fclose($handle);
            }
            
            return $content;
        }
        
        // 返回JSON格式
        return file_get_contents($logFile);
    }
    
    /**
     * 获取可用日志日期列表
     * 
     * @return array
     */
    public function getLogDates() {
        $logDir = __DIR__ . '/../logs';
        $dates = [];
        
        if (is_dir($logDir)) {
            $dirs = glob($logDir . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $date = basename($dir);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $dates[] = $date;
                }
            }
        }
        
        rsort($dates);
        return $dates;
    }
    
    /**
     * 追加到JSON文件
     */
    private function appendToJson($data) {
        $line = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        return file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX) !== false;
    }
    
    /**
     * 根据日期获取日志文件路径
     */
    private function getLogFileByDate($date = null) {
        if (empty($date)) {
            return $this->logFile;
        }
        return __DIR__ . '/../logs/' . $date . '/requests.json';
    }
    
    /**
     * 生成唯一ID
     */
    private function generateId() {
        return date('YmdHis') . uniqid();
    }
    
    /**
     * 获取当前URL
     */
    private function getCurrentUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $protocol . '://' . $host . $uri;
    }
    
    /**
     * 获取请求头
     */
    private function getRequestHeaders() {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
}
