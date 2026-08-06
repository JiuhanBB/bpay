#!/bin/bash
# BPay Linux 监控 - 一键构建与运行
# 使用前请确保 wechat.deb 在同一目录下

set -e

IMAGE_NAME="bpay-monitor"
CONTAINER_NAME="bpay-monitor"

echo "=========================================="
echo "  BPay Linux 支付监控 - Docker 构建脚本"
echo "=========================================="

# 自动下载依赖 (微信安装包 + Umi-OCR)
./download_deps.sh

# 构建镜像
echo ""
echo ">>> 构建 Docker 镜像: $IMAGE_NAME ..."
docker build -t "$IMAGE_NAME" .

# 删除旧容器（如果存在）
docker rm -f "$CONTAINER_NAME" 2>/dev/null || true

# 运行容器
echo ""
echo ">>> 启动容器: $CONTAINER_NAME ..."
docker run -d \
    --name "$CONTAINER_NAME" \
    --restart unless-stopped \
    --network=host \
    --shm-size=2g \
    "$IMAGE_NAME"

echo ""
echo "=========================================="
echo "  容器已启动！"
echo "  VNC Web:  http://localhost:6080/vnc_lite.html"
echo "  监控面板: http://localhost:5000"
echo "  Umi-OCR:  http://localhost:1224"
echo ""
echo "  查看日志: docker logs -f $CONTAINER_NAME"
echo "=========================================="
