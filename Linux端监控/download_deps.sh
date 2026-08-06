#!/bin/bash
# 下载 Umi-OCR 与微信 Linux 安装包,替代 git LFS 管理的大文件
# 用法: ./download_deps.sh [--force]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

UMI_VERSION="${UMI_VERSION:-2.1.5}"   # 对应仓库内 Umi-OCR v2.1.5 (Paddle)
UMI_URL="https://github.com/hiroi-sora/Umi-OCR/releases/download/v${UMI_VERSION}/Umi-OCR_Linux_Paddle_${UMI_VERSION}.tar.xz"
WECHAT_URL="https://dldir1v6.qq.com/weixin/Universal/Linux/WeChatLinux_x86_64.deb"
FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

# 微信 deb (Dockerfile 依赖 ./wechat.deb)
if [ ! -f wechat.deb ] || [ $FORCE -eq 1 ]; then
    echo ">>> 下载微信 Linux 安装包 -> wechat.deb"
    wget -q --show-progress -O wechat.deb "$WECHAT_URL"
fi

# Umi-OCR:官方包解压出 Umi-OCR/ 目录,直接覆盖仓库内同名目录
if [ ! -d Umi-OCR/UmiOCR-data/.embeddable ] || [ $FORCE -eq 1 ]; then
    echo ">>> 下载 Umi-OCR v${UMI_VERSION} Linux Paddle (264MB) -> /tmp"
    wget -q --show-progress -O /tmp/umi-ocr.tar.xz "$UMI_URL"
    echo ">>> 解压覆盖 Umi-OCR/"
    tar -xJf /tmp/umi-ocr.tar.xz
    rm -f /tmp/umi-ocr.tar.xz
fi

echo ">>> 完成:"
ls -lh wechat.deb | awk '{print "  " $NF "  " $5}'
du -sh Umi-OCR | awk '{print "  Umi-OCR/  " $1}'
