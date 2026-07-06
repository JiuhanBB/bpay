# BPay 支付监控系统

微信/支付宝收款实时监控，自动抓取收款信息并上报通知。

## 系统架构

```
┌─ 客户端（监控端） ──────────────────────┐
│                                         │
│  ┌──────────────────┐  ┌─────────────┐  │
│  │ 微信桌面客户端     │  │ Umi-OCR     │  │
│  │ (WeChat Linux)    │  │ (离线OCR引擎)│  │
│  └────────┬─────────┘  └──────┬──────┘  │
│           │ 截图               │ OCR      │
│           ▼                    ▼         │
│  ┌────────────────────────────────────┐  │
│  │ 监控 WebUI (Flask, :5000)          │  │
│  │ · 定时截图 / OCR                    │  │
│  │ · 提取收款信息                       │  │
│  │ · MD5 签名 + 上报通知               │  │
│  └────────────────────────────────────┘  │
│         │ HTTP 通知                      │
└─────────┼────────────────────────────────┘
          ▼
┌─ 服务端 ───────────────────────────────┐
│  PHP 支付网关 (bpay/)                   │
│  · balance_notify.php 接收通知          │
│  · 订单查询 / 管理员后台                │
└─────────────────────────────────────────┘
```

## 目录结构

```
bpay/
├── bpay/                  # PHP 支付网关（服务端）
│   ├── config.php         # 数据库/密钥配置
│   ├── db.php             # 数据库连接
│   ├── pay.php            # 支付接口
│   ├── submit.php         # 订单提交
│   ├── admin/             # 管理员后台
│   └── api/               # API 接口
│       ├── balance_notify.php  # 余额变动通知接收
│       └── notify.php          # 支付回调
│
├── Linux端监控/            # Linux Docker 监控（客户端）
│   ├── Dockerfile         # Docker 构建文件
│   ├── startup.sh         # 容器入口脚本
│   ├── monitor/           # 监控 WebUI
│   │   ├── app.py         # Flask 后端
│   │   └── templates/     # 前端页面
│   ├── Umi-OCR/           # 离线 OCR 引擎
│   ├── wechat.deb         # WeChat Linux 安装包（需自行下载）
│   ├── build.sh           # 构建 + 运行脚本
│   └── deploy.ps1         # Windows 部署辅助脚本
│
├── win端监控/              # Windows 原生监控（客户端）
│   └── 久寒支付监控.py     # tkinter GUI 监控
│
└── README.md
```

## 环境要求

### Linux 端
- Ubuntu 22.04 / Debian 12 / CentOS 7+
- Docker + Docker Compose
- 4GB+ 内存（推荐 8GB）
- 20GB+ 磁盘空间

### Windows 端
- Windows 10/11（64位）
- Python 3.10+
- WeChat Windows 版（已登录）

### PHP 服务端
- Nginx / Apache + PHP 8.0+
- SQLite


## 使用指南

### 1. 访问 WebUI (Linux)

浏览器打开 `http://服务器IP:5000`

### 2. 基本配置

在 WebUI 中：
1. **微信设置** → 启动微信 → 窗口截图获取二维码 → 手机扫码登录
2. **支付类型**：选择"收款码"或"赞赏码"
3. **自动打开服务号**：点击后自动搜索并打开对应服务号
4. **OCR 配置**：选择 OCR 方式（默认本地 Umi-OCR）
5. **通知配置**：填写 `balance_notify.php` 地址
6. **开始监控**：点击启动

### 3. VNC 远程桌面

访问 `http://服务器IP:6080/vnc_lite.html`
（无密码，可在容器内直接操作微信）

## 安全提示

本项目为便于开发含有诸多未修复的安全漏洞。如JWT密钥硬编码，Linux监控/VNC无密码等等，如需上线生产环境请自行修复

## 致谢

Linux 端 Docker 监控思路来源于 [WechatOnCloud](https://github.com/Gloridust/WechatOnCloud)，WeChat Linux 版在 Docker 容器中运行的实践参考了该项目的方案。

双端的 Wechat 监控 OCR 来源与 [Umi-OCR](https://github.com/hiroi-sora/Umi-OCR)，整体监控技术使用了此方案

## 常见问题

### 微信无法登录
- VNC 打开 `http://IP:6080` 查看桌面
- 点击微信窗口查看二维码
- 如微信未启动，在 WebUI 中点击"启动微信"

### Umi-OCR 启动失败
```bash
docker exec bpay-monitor cat /tmp/umi-ocr.log
```
检查是否缺少 X11 依赖，重新构建镜像。

### OCR 识别不准确
- 确保服务号窗口已打开并已进入
- 在 WebUI 中点击"自动打开服务号"
- 确认支付窗口选择正确

