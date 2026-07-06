#!/bin/bash

export LANG=zh_CN.UTF-8
export LANGUAGE=zh_CN:zh
export LC_ALL=zh_CN.UTF-8

mkdir -p ~/.vnc ~/.config/autostart ~/.config/xfce4/xfconf/xfce-perchannel-xml ~/.config/xfce4/panel

# 修复 DNS（Docker 可能覆盖 /etc/resolv.conf）
echo "nameserver 223.5.5.5" > /etc/resolv.conf 2>/dev/null || true

# === 面板配置：底部Win10风格 ===
cat > ~/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-panel.xml << 'PANEL'
<?xml version="1.0" encoding="UTF-8"?>
<channel name="xfce4-panel" version="1.0">
  <property name="configver" type="int" value="2"/>
  <property name="panels" type="array">
    <value type="int" value="1"/>
    <property name="dark-mode" type="bool" value="true"/>
    <property name="panel-1" type="empty">
      <property name="position" type="string" value="p=8;x=0;y=0"/>
      <property name="position-arrangement" type="string" value="bottom"/>
      <property name="position-locked" type="bool" value="true"/>
      <property name="size" type="uint" value="28"/>
      <property name="length" type="int" value="100"/>
      <property name="span" type="bool" value="true"/>
      <property name="plugin-ids" type="array">
        <value type="int" value="1"/>
        <value type="int" value="2"/>
        <value type="int" value="3"/>
        <value type="int" value="4"/>
        <value type="int" value="5"/>
        <value type="int" value="6"/>
      </property>
    </property>
  </property>
  <property name="plugins" type="array">
    <value type="string" value="whiskermenu"/>
    <value type="string" value="tasklist"/>
    <value type="string" value="separator"/>
    <value type="string" value="systray"/>
    <value type="string" value="clock"/>
    <value type="string" value="actions"/>
    <property name="plugin-1" type="string" value="whiskermenu"/>
    <property name="plugin-2" type="string" value="tasklist">
      <property name="grouping" type="uint" value="1"/>
    </property>
    <property name="plugin-3" type="string" value="separator">
      <property name="expand" type="bool" value="true"/>
      <property name="style" type="uint" value="0"/>
    </property>
    <property name="plugin-4" type="string" value="systray">
      <property name="square-icons" type="bool" value="true"/>
    </property>
    <property name="plugin-5" type="string" value="clock"/>
    <property name="plugin-6" type="string" value="actions"/>
  </property>
</channel>
PANEL

# === 桌面配置：禁用右键菜单 ===
cat > ~/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-desktop.xml << 'DESKTOP'
<?xml version="1.0" encoding="UTF-8"?>
<channel name="xfce4-desktop" version="1.0">
  <property name="backdrop" type="empty">
    <property name="screen0" type="empty">
      <property name="monitorVNC-0" type="empty">
        <property name="workspace0" type="empty">
          <property name="color-style" type="int" value="0"/>
          <property name="image-style" type="int" value="5"/>
          <property name="last-image" type="string" value="/usr/share/backgrounds/xfce/xfce-shapes.svg"/>
        </property>
      </property>
    </property>
  </property>
  <property name="desktop-menu" type="empty">
    <property name="show" type="bool" value="false"/>
  </property>
  <property name="mountain-view" type="empty">
    <property name="show" type="bool" value="false"/>
  </property>
  <property name="windowlist-menu" type="empty">
    <property name="show" type="bool" value="false"/>
  </property>
</channel>
DESKTOP

# === noVNC前端：禁用右键菜单 + 禁用文字选中 + 去掉 CtrlAltDel 按钮 ===
sed -i 's|document.addEventListener("contextmenu".*||' /usr/share/novnc/vnc_lite.html
cat > /usr/share/novnc/vnc_lite.html << 'NOVNC'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>BPay VNC</title>
<style>
  html, body { height: 100%; margin: 0; background: #1a1d23; overflow: hidden; }
  body { display: flex; flex-direction: column; }
  * { user-select: none; -webkit-user-select: none; }
  #top_bar { background: #2a2f37; color: #adb5bd; padding: 4px 8px; font: 12px sans-serif; display: flex; justify-content: center; }
  #screen { flex: 1; overflow: hidden; }
</style>
<script nonce="BPay">
  document.addEventListener("contextmenu", e => e.preventDefault());
  document.addEventListener("selectstart", e => e.preventDefault());
</script>
<script type="module" crossorigin="anonymous">
  import RFB from './core/rfb.js';
  let rfb;
  function connected(e) { document.getElementById('status').textContent = "已连接"; }
  function disconnected(e) { document.getElementById('status').textContent = e.detail.clean ? "已断开" : "连接断开"; }
  function credsReq(e) { rfb.sendCredentials({ password: prompt("Password:") }); }
  function updName(e) {}
  function readQV(name, def) {
    const re = new RegExp('.*[?&]' + name + '=([^&#]*)'),
          m = (document.location.href + window.location.hash).match(re);
    return m ? decodeURIComponent(m[1]) : def;
  }
  const host = readQV('host', window.location.hostname);
  let port = readQV('port', window.location.port);
  const pass = readQV('password');
  const path = readQV('path', 'websockify');
  document.getElementById('status').textContent = "连接中...";
  let url = (window.location.protocol === "https:" ? 'wss' : 'ws') + '://' + host;
  if(port) url += ':' + port;
  url += '/' + path;
  rfb = new RFB(document.getElementById('screen'), url, { credentials: { password: pass } });
  rfb.addEventListener("connect", connected);
  rfb.addEventListener("disconnect", disconnected);
  rfb.addEventListener("credentialsrequired", credsReq);
  rfb.addEventListener("desktopname", updName);
  rfb.viewOnly = readQV('view_only', false);
  rfb.scaleViewport = readQV('scale', false);
</script>
</head>
<body>
  <div id="top_bar"><span id="status">加载中</span></div>
  <div id="screen"></div>
</body>
</html>
NOVNC

# === 窗口管理器：经典Win风格 ===
cat > ~/.config/xfce4/xfconf/xfce-perchannel-xml/xfwm4.xml << 'XFWM4'
<?xml version="1.0" encoding="UTF-8"?>
<channel name="xfwm4" version="1.0">
  <property name="general" type="empty">
    <property name="theme" type="string" value="Default"/>
    <property name="title_font" type="string" value="Sans 9"/>
    <property name="button_layout" type="string" value="O|SHMC"/>
    <property name="borderless_maximize" type="bool" value="true"/>
    <property name="wrap_workspaces" type="bool" value="false"/>
    <property name="wrap_windows" type="bool" value="false"/>
    <property name="wrap_resistance" type="int" value="10"/>
    <property name="focus_delay" type="int" value="250"/>
    <property name="click_to_focus" type="bool" value="true"/>
    <property name="workspace_count" type="int" value="1"/>
  </property>
</channel>
XFWM4

# === fcitx5自启 ===
cat > ~/.config/autostart/fcitx5.desktop << 'EOF'
[Desktop Entry]
Type=Application
Name=Fcitx5
Exec=fcitx5
Hidden=false
NoDisplay=false
X-XFCE-Autostart-enabled=true
EOF

# === WeChat自启 ===
cat > ~/.config/autostart/wechat.desktop << 'EOF'
[Desktop Entry]
Type=Application
Name=WeChat
Exec=env WECHAT_IPC_DISABLE=1 /usr/bin/wechat
StartupNotify=false
Terminal=false
EOF

# === xstartup：只启动，不修改 ===
cat > ~/.vnc/xstartup << 'XSTARTUP'
#!/bin/bash
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
export XDG_SESSION_DESKTOP=XFCE
export XDG_CURRENT_DESKTOP=XFCE
export DESKTOP_SESSION=xfce
export LANG=zh_CN.UTF-8
export LANGUAGE=zh_CN:zh
export LC_ALL=zh_CN.UTF-8
export GTK_IM_MODULE=fcitx
export QT_IM_MODULE=fcitx
export XMODIFIERS=@im=fcitx
export DISPLAY=:1
if [ -z "$DBUS_SESSION_BUS_ADDRESS" ]; then
    eval $(dbus-launch --sh-syntax)
    export DBUS_SESSION_BUS_ADDRESS
fi
fcitx5 &>/dev/null &
while true; do
    startxfce4
    sleep 2
done
XSTARTUP

chmod +x ~/.vnc/xstartup

rm -f /tmp/.X*-lock /tmp/.X11-unix/X*

vncserver -kill :1 2>/dev/null || true
vncserver :1 -geometry "$VNC_RESOLUTION" -depth "$VNC_DEPTH" -localhost no -SecurityTypes None --I-KNOW-THIS-IS-INSECURE

sleep 3
websockify --web=/usr/share/novnc/ "$NOVNC_PORT" localhost:"$VNC_PORT" &

echo "=========================================="
echo "  VNC: port $VNC_PORT"
echo "  Web: http://localhost:$NOVNC_PORT/vnc_lite.html"
echo "  No password required"
echo "=========================================="

# 启动支付监控 WebUI
/monitor/run.sh

tail -f /root/.vnc/*.log
