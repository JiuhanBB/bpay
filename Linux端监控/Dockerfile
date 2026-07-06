# BPay Linux 支付监控 - 完整构建版
# 从 ubuntu:24.04 开始，适合给他人部署
# 包含：XFCE4 + VNC + noVNC + fcitx5 + WeChat + Umi-OCR + 支付监控 WebUI

FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Shanghai
ENV LANG=zh_CN.UTF-8
ENV LANGUAGE=zh_CN:zh
ENV LC_ALL=zh_CN.UTF-8
ENV VNC_PORT=5901
ENV NOVNC_PORT=6080
ENV VNC_RESOLUTION=1280x800
ENV VNC_DEPTH=24

# 1. 系统基础 + 桌面环境
RUN sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' /etc/apt/sources.list \
    && apt-get update && apt-get install -y --no-install-recommends \
    xfce4 \
    xfce4-terminal \
    xfce4-whiskermenu-plugin \
    tigervnc-standalone-server \
    tigervnc-common \
    novnc \
    python3-websockify \
    fcitx5 \
    fcitx5-chinese-addons \
    fonts-wqy-microhei \
    fonts-wqy-zenhei \
    fonts-noto-cjk \
    wget \
    curl \
    xz-utils \
    scrot \
    imagemagick \
    xdotool \
    xclip \
    python3 \
    python3-pip \
    python3-venv \
    locales \
    libatomic1 \
    libxtst6 \
    libnss3 \
    libnspr4 \
    libasound2t64 \
    libxkbcommon-x11-0 \
    xvfb \
    && apt-get install --fix-broken -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# dbus（XFCE4 必需，否则启动时弹 dbus-launch 错误）
RUN sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' /etc/apt/sources.list \
    && apt-get update && apt-get install -y \
    dbus-x11 \
    dbus-user-session \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# libxcb 全家桶（WeChat + Umi-OCR 都需要 QT xcb 支持）
RUN sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' /etc/apt/sources.list \
    && apt-get update && apt-get install -y \
    libxcb-icccm4 libxcb-image0 libxcb-keysyms1 libxcb-randr0 \
    libxcb-render-util0 libxcb-render0 libxcb-shape0 libxcb-shm0 \
    libxcb-sync1 libxcb-xfixes0 libxcb-xinerama0 libxcb-xinput0 \
    libxcb-xkb1 libxcb-util1 \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. 区域设置
RUN locale-gen zh_CN.UTF-8 && update-locale

# 3. 安装微信（先更新 apt cache，否则依赖无法解析）
COPY wechat.deb /tmp/wechat.deb
RUN sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' /etc/apt/sources.list \
    && apt-get update && dpkg -i /tmp/wechat.deb || true \
    && apt-get -f install -y \
    && rm /tmp/wechat.deb

# 4. 安装 Umi-OCR Linux 版（PaddleOCR 引擎，HTTP API 端口 1224）
COPY Umi-OCR /app/Umi-OCR/

# Umi-OCR 预配置：允许外部 HTTP 请求 + 中文字体
RUN printf "\
[Global]\n\
server.host=0.0.0.0\n\
ui.fontFamily=WenQuanYi Micro Hei\n\
ui.dataFontFamily=WenQuanYi Micro Hei\n\
" > /app/Umi-OCR/UmiOCR-data/.settings

# 修复嵌入式 Python 的 python3 软链（原文件 0 字节）
RUN rm -f /app/Umi-OCR/UmiOCR-data/.embeddable/bin/python3 && \
    ln -s python3.10 /app/Umi-OCR/UmiOCR-data/.embeddable/bin/python3

# 5. Python 依赖（监控 WebUI）
RUN pip3 install flask Pillow requests urllib3 cryptography psutil \
    -i https://mirrors.aliyun.com/pypi/simple/ \
    --trusted-host mirrors.aliyun.com \
    --break-system-packages --no-cache-dir

# 6. 复制监控程序
COPY monitor /monitor/

# 7. 入口脚本
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

EXPOSE 5901 6080 5000 1224

CMD ["/startup.sh"]
