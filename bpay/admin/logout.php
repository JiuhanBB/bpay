<?php
/**
 * BPay 管理后台退出登录 - JWT版本
 */

// 清除JWT Cookie
setcookie('bpay_token', '', time() - 3600, '/');

header('Location: login.php');
exit;
