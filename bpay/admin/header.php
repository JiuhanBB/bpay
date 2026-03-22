<?php
/**
 * BPay 管理后台头部 - 包含主题切换 + 响应式布局
 */

// 获取当前主题
$theme = $_COOKIE['bpay_theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>BPay 管理后台</title>
    <link rel="stylesheet" href="https://unpkg.com/remixicon@3.5.0/fonts/remixicon.css">
    <style>
        /* 暗黑主题（默认） */
        :root {
            --bg-primary: #0a0a0a;
            --bg-secondary: #141414;
            --bg-card: #1a1a1a;
            --bg-hover: #252525;
            --text-primary: #ffffff;
            --text-secondary: #a0a0a0;
            --text-muted: #666666;
            --border-color: #2a2a2a;
            --accent: #ffffff;
            --success: #00d084;
            --warning: #ffb800;
            --danger: #ff4757;
            --info: #3b82f6;
        }
        
        /* 明亮主题 */
        [data-theme="light"] {
            --bg-primary: #f5f5f5;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --bg-hover: #f0f0f0;
            --text-primary: #1a1a1a;
            --text-secondary: #666666;
            --text-muted: #999999;
            --border-color: #e0e0e0;
            --accent: #1a1a1a;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background 0.3s, color 0.3s;
        }
        
        /* 移动端菜单按钮 */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-primary);
            font-size: 20px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        /* 侧边栏 */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        .sidebar-header {
            padding: 30px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sidebar-header h1 {
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 2px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .theme-toggle {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s;
        }
        
        .theme-toggle:hover {
            border-color: var(--text-primary);
            color: var(--text-primary);
        }
        
        .sidebar-nav {
            flex: 1;
            padding: 20px 16px;
            overflow-y: auto;
        }
        
        .nav-section {
            margin-bottom: 24px;
        }
        
        .nav-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            padding: 0 12px;
            margin-bottom: 12px;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border-radius: 12px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 15px;
            transition: all 0.3s ease;
            margin-bottom: 4px;
        }
        
        .nav-item:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }
        
        .nav-item.active {
            background: var(--text-primary);
            color: var(--bg-primary);
        }
        
        .nav-item i {
            font-size: 20px;
            min-width: 20px;
        }
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            background: transparent;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            border-color: var(--danger);
            color: var(--danger);
        }
        
        /* 主内容区 */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            padding: 40px;
        }
        
        .page-header {
            margin-bottom: 40px;
        }
        
        .page-header h2 {
            font-size: 32px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .page-header p {
            color: var(--text-secondary);
            font-size: 15px;
        }
        
        /* 统计卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 28px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            border-color: var(--text-muted);
            transform: translateY(-2px);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 20px;
        }
        
        .stat-icon.blue { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .stat-icon.orange { background: rgba(251, 146, 60, 0.1); color: #fb923c; }
        .stat-icon.green { background: rgba(34, 197, 94, 0.1); color: #22c55e; }
        .stat-icon.purple { background: rgba(168, 85, 247, 0.1); color: #a855f7; }
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 15px;
        }
        
        /* 内容卡片 */
        .content-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 24px;
            overflow-x: auto;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
        }
        
        /* 表单样式 */
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-control {
            width: 100%;
            padding: 16px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--text-primary);
        }
        
        .form-control:disabled {
            background: var(--bg-hover);
            color: var(--text-muted);
        }
        
        textarea.form-control {
            min-height: 120px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 13px;
            line-height: 1.6;
        }
        
        /* 按钮 */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 28px;
            background: var(--text-primary);
            color: var(--bg-primary);
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .btn-outline:hover {
            border-color: var(--text-primary);
        }
        
        .btn-danger {
            background: var(--danger);
            color: #fff;
        }
        
        .btn-sm {
            padding: 10px 18px;
            font-size: 13px;
        }
        
        /* 表格 */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        .data-table th {
            text-align: left;
            padding: 16px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 500;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table td {
            padding: 20px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .data-table tr:hover td {
            background: var(--bg-hover);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .status-badge.pending {
            background: rgba(251, 146, 60, 0.1);
            color: #fb923c;
        }
        
        .status-badge.paid {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }
        
        .status-badge.notified {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .status-badge.incoming {
            background: rgba(168, 85, 247, 0.1);
            color: #a855f7;
        }
        
        .status-badge.outgoing {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        /* 分页 */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 32px;
            flex-wrap: wrap;
        }
        
        .page-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            height: 44px;
            padding: 0 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-secondary);
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-btn:hover {
            border-color: var(--text-primary);
            color: var(--text-primary);
        }
        
        .page-btn.active {
            background: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }
        
        .page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* 消息提示 */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            font-size: 15px;
        }
        
        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #22c55e;
        }
        
        .alert-error {
            background: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.2);
            color: #f43f5e;
        }
        
        /* 日志卡片 */
        .log-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .log-card:hover {
            border-color: var(--text-muted);
            transform: translateX(4px);
        }
        
        .log-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .log-time {
            font-size: 13px;
            color: var(--text-muted);
        }
        
        .log-url {
            font-size: 14px;
            color: var(--text-secondary);
            word-break: break-all;
            margin-bottom: 8px;
        }
        
        .log-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }
        
        /* 模态框 */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
        }
        
        .modal-close {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.3s;
        }
        
        .modal-close:hover {
            border-color: var(--danger);
            color: var(--danger);
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        
        .code-block {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 13px;
            line-height: 1.6;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            color: var(--text-secondary);
            margin-top: 12px;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* 过滤器 */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .filter-select {
            padding: 12px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 14px;
            cursor: pointer;
        }
        
        /* JSON上传区域 */
        .json-upload-wrapper {
            position: relative;
            width: 100%;
        }
        
        .json-upload-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 32px;
            background: var(--bg-secondary);
            border: 2px dashed var(--border-color);
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .json-upload-area:hover {
            border-color: var(--text-muted);
            background: var(--bg-hover);
        }
        
        .json-upload-area input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 10;
        }
        
        .json-upload-area i {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }
        
        .json-upload-area h4 {
            font-size: 16px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .json-upload-area p {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        /* 收款码上传 */
        .upload-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
        }
        
        .upload-card {
            background: var(--bg-secondary);
            border: 2px dashed var(--border-color);
            border-radius: 20px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-card:hover {
            border-color: var(--text-muted);
            background: var(--bg-hover);
        }
        
        .upload-card input[type="file"] {
            display: none;
        }
        
        .upload-placeholder {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: var(--bg-hover);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: var(--text-muted);
        }
        
        .upload-preview {
            max-width: 100%;
            max-height: 200px;
            margin: 0 auto 20px;
            border-radius: 12px;
        }
        
        .upload-card h4 {
            font-size: 18px;
            margin-bottom: 8px;
        }
        
        .upload-card p {
            color: var(--text-muted);
            font-size: 14px;
        }
        
        /* 响应式 - 平板 */
        @media (max-width: 1024px) {
            .sidebar {
                width: 220px;
            }
            .main-content {
                margin-left: 220px;
                padding: 30px;
            }
        }
        
        /* 响应式 - 手机 */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: flex;
            }
            
            .sidebar {
                width: 280px;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 70px 15px 20px;
            }
            
            .page-header {
                margin-bottom: 24px;
            }
            
            .page-header h2 {
                font-size: 24px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
                margin-bottom: 24px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .stat-value {
                font-size: 28px;
            }
            
            .content-card {
                padding: 20px;
                margin-bottom: 15px;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .data-table {
                font-size: 13px;
            }
            
            .data-table th,
            .data-table td {
                padding: 12px 8px;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-select {
                width: 100%;
            }
            
            .btn {
                width: 100%;
                padding: 14px 20px;
            }
            
            .modal-content {
                max-height: 95vh;
                margin: 10px;
            }
            
            .modal-header,
            .modal-body {
                padding: 16px;
            }
            
            .upload-grid {
                grid-template-columns: 1fr;
            }
            
            .upload-card {
                padding: 30px 20px;
            }
            
            .log-card {
                padding: 16px;
            }
            
            .log-meta {
                gap: 10px;
            }
            
            .pagination {
                gap: 5px;
            }
            
            .page-btn {
                min-width: 38px;
                height: 38px;
                padding: 0 12px;
                font-size: 13px;
            }
        }
        
        /* 小屏幕手机 */
        @media (max-width: 375px) {
            .main-content {
                padding: 65px 10px 15px;
            }
            
            .page-header h2 {
                font-size: 20px;
            }
            
            .stat-value {
                font-size: 24px;
            }
            
            .content-card {
                padding: 15px;
            }
        }
        
        @supports (padding-bottom: env(safe-area-inset-bottom)) {
            .main-content {
                padding-bottom: calc(20px + env(safe-area-inset-bottom));
            }
        }
        
        /* 动画效果 */
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
        
        .fade-in {
            animation: fadeIn 0.4s ease-out forwards;
        }
        
        .slide-in {
            opacity: 0;
            animation: slideInUp 0.4s ease-out forwards;
        }
        
        .btn:active {
            transform: scale(0.95);
            transition: transform 0.1s;
        }
        
        .page-btn:not(.disabled):active {
            transform: scale(0.9);
            transition: transform 0.1s;
        }
        
        .status-badge {
            transition: all 0.3s ease;
        }
        
        .status-badge:hover {
            transform: scale(1.05);
        }
        
        .data-table tr {
            transition: background-color 0.2s ease;
        }
        
        .data-table tr:hover {
            background-color: var(--bg-hover);
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border-color);
            border-top-color: var(--text-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        .content-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .content-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .nav-item {
            transition: all 0.2s ease;
        }
        
        .nav-item:hover {
            transform: translateX(4px);
        }
        
        .form-control {
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
        }
        
        [data-theme="light"] .form-control:focus {
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body>
<button class="mobile-menu-btn" onclick="toggleSidebar()">
    <i class="ri-menu-line"></i>
</button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>