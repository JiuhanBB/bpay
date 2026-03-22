<?php
/**
 * BPay 管理后台登录 - JWT认证 + 黑白主题
 */

require_once '../db.php';

// JWT密钥
$jwtSecret = 'XANOon14r3CuIvJNS8pQ5MkAZJFoGvUDrtF66aj1tjA8ocDlw02oNkkYTKduJnjv8K15Gebvf32aeq8bGmiEkC';

$error = '';

// 处理主题切换（必须在任何输出之前）
if (isset($_GET['toggle_theme'])) {
    $currentTheme = $_COOKIE['bpay_theme'] ?? 'dark';
    $newTheme = $currentTheme === 'dark' ? 'light' : 'dark';
    setcookie('bpay_theme', $newTheme, time() + 86400 * 365, '/');
    header('Location: login.php');
    exit;
}

// 检查是否已登录
if (isset($_COOKIE['bpay_token'])) {
    $token = $_COOKIE['bpay_token'];
    if (verifyJWT($token, $jwtSecret)) {
        header('Location: index.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    $db = new BPayDB();
    $storedPassword = $db->getConfig('admin_password');
    
    if (md5($password) === $storedPassword) {
        // 生成JWT
        $token = generateJWT(['admin' => true], $jwtSecret, 86400 * 7); // 7天有效期
        
        // 设置Cookie
        setcookie('bpay_token', $token, time() + 86400 * 7, '/', '', false, true);
        
        header('Location: index.php');
        exit;
    } else {
        $error = '密码错误';
    }
}

// 获取当前主题
$theme = $_COOKIE['bpay_theme'] ?? 'dark';

/**
 * 生成JWT
 */
function generateJWT($payload, $secret, $expire = 86400) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $time = time();
    $payload['iat'] = $time;
    $payload['exp'] = $time + $expire;
    $payload = json_encode($payload);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
}

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
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BPay 管理后台 - 登录</title>
    <link rel="stylesheet" href="https://unpkg.com/remixicon@3.5.0/fonts/remixicon.css">
    <style>
        /* 主题变量 */
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
            --danger: #ff4757;
        }
        
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
            --danger: #ef4444;
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
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            transition: background 0.3s, color 0.3s;
        }
        
        .login-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 48px;
            width: 100%;
            max-width: 420px;
            position: relative;
        }
        
        .theme-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .theme-toggle:hover {
            border-color: var(--text-primary);
            color: var(--text-primary);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .login-header .logo {
            width: 64px;
            height: 64px;
            background: var(--text-primary);
            color: var(--bg-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 24px;
        }
        
        .login-header h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        
        .login-header p {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-secondary);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-group input {
            width: 100%;
            padding: 16px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--text-primary);
        }
        
        .form-group input::placeholder {
            color: var(--text-muted);
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            background: var(--text-primary);
            color: var(--bg-primary);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-login:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .error {
            background: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.2);
            color: var(--danger);
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        @media (max-width: 480px) {
            .login-container {
                margin: 20px;
                padding: 32px 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <a href="?toggle_theme=1" class="theme-toggle" title="切换主题">
            <i class="ri-<?php echo $theme === 'dark' ? 'sun' : 'moon'; ?>-line"></i>
        </a>
        
        <div class="login-header">
            <div class="logo">
                <i class="ri-wallet-3-line"></i>
            </div>
            <h1>BPay 管理后台</h1>
            <p>易支付集成系统</p>
        </div>
        
        <?php if ($error): ?>
        <div class="error">
            <i class="ri-error-warning-line"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>管理密码</label>
                <input type="password" name="password" placeholder="请输入密码" required>
            </div>
            <button type="submit" class="btn-login">
                <i class="ri-login-box-line"></i>
                登录
            </button>
        </form>
    </div>
</body>
</html>
