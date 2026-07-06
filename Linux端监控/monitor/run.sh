#!/bin/bash
MONITOR_DIR="$(cd "$(dirname "$0")" && pwd)"

export DISPLAY=:1
export LANG=zh_CN.UTF-8
export LC_ALL=zh_CN.UTF-8

mkdir -p "$MONITOR_DIR/../logs"

# === 启动 Umi-OCR 无头模式 ===
UMI_DIR="/app/Umi-OCR"
if [ -d "$UMI_DIR" ]; then
    echo "正在启动 Umi-OCR（无头模式）..."
    cd "$UMI_DIR"
    HEADLESS=true nohup ./umi-ocr.sh > /tmp/umi-ocr.log 2>&1 &
    UMI_PID=$!
    echo "Umi-OCR PID: $UMI_PID"
    # 等待 HTTP API 就绪
    for i in $(seq 1 60); do
        if curl -s http://127.0.0.1:1224/api/ocr/get_options > /dev/null 2>&1; then
            echo "Umi-OCR API 已就绪 (http://127.0.0.1:1224)"
            break
        fi
        if [ "$i" -eq 60 ]; then
            echo "Umi-OCR 启动超时，请检查日志: /tmp/umi-ocr.log"
        fi
        sleep 1
    done
else
    echo "未检测到 Umi-OCR，仅使用云端/自定义 OCR"
fi

# 后台启动监控 Web 服务
cd "$MONITOR_DIR"
python3 app.py >>/tmp/monitor.log 2>&1 &
echo "BPay Monitor started on http://0.0.0.0:5000 (PID $!)"
