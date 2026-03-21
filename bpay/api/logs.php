<?php
/**
 * BPay 日志API接口
 * 用于管理后台读取日志数据
 */

require_once '../lib/Logger.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$logger = new Logger();

switch ($action) {
    case 'list':
        // 获取日志列表
        $page = intval($_GET['page'] ?? 1);
        $perPage = intval($_GET['per_page'] ?? 20);
        $type = $_GET['type'] ?? 'all';
        $date = $_GET['date'] ?? null;
        
        $result = $logger->getLogs($page, $perPage, $type, $date);
        echo json_encode([
            'code' => 'success',
            'data' => $result
        ]);
        break;
        
    case 'detail':
        // 获取单条日志详情
        $id = $_GET['id'] ?? '';
        $date = $_GET['date'] ?? null;
        
        if (empty($id)) {
            echo json_encode(['code' => 'error', 'msg' => '缺少日志ID']);
            exit;
        }
        
        $detail = $logger->getLogDetail($id, $date);
        if ($detail) {
            echo json_encode([
                'code' => 'success',
                'data' => $detail
            ]);
        } else {
            echo json_encode(['code' => 'error', 'msg' => '日志不存在']);
        }
        break;
        
    case 'dates':
        // 获取可用日期列表
        $dates = $logger->getLogDates();
        echo json_encode([
            'code' => 'success',
            'data' => $dates
        ]);
        break;
        
    case 'delete':
        // 删除日志
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['code' => 'error', 'msg' => '请求方式错误']);
            exit;
        }
        
        $id = $_POST['id'] ?? '';
        $date = $_POST['date'] ?? null;
        
        if ($logger->deleteLog($id, $date)) {
            echo json_encode(['code' => 'success', 'msg' => '删除成功']);
        } else {
            echo json_encode(['code' => 'error', 'msg' => '删除失败']);
        }
        break;
        
    case 'export':
        // 导出日志
        $date = $_GET['date'] ?? null;
        $format = $_GET['format'] ?? 'json';
        
        $content = $logger->exportLogs($date, $format);
        
        if (empty($content)) {
            echo json_encode(['code' => 'error', 'msg' => '没有日志数据']);
            exit;
        }
        
        $filename = 'bpay_logs_' . ($date ?: date('Y-m-d')) . '.' . $format;
        
        header('Content-Type: ' . ($format === 'txt' ? 'text/plain' : 'application/json'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        
        echo $content;
        break;
        
    default:
        echo json_encode(['code' => 'error', 'msg' => '未知操作']);
}
