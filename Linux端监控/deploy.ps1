Write-Host "=== 启动 Docker daemon ==="
wsl -d Ubuntu bash -c "sudo pkill -9 dockerd 2>/dev/null; sudo rm -f /var/run/docker.sock /var/run/docker.pid; nohup sudo dockerd --iptables=false > /tmp/dockerd.log 2>&1 & sleep 4"
Start-Sleep -Seconds 6

Write-Host "=== 清理旧容器 ==="
wsl -d Ubuntu bash -c "docker rm -f bpay-monitor 2>/dev/null; docker ps -a --filter name=bpay-monitor"

Write-Host "=== 启动新容器 ==="
wsl -d Ubuntu bash -c "docker run -d --name bpay-monitor --restart unless-stopped --shm-size=2g -p 6080:6080 -p 5901:5901 -p 5000:5000 -p 1224:1224 bpay-monitor"

Write-Host "=== 等待启动 ==="
Start-Sleep -Seconds 45

Write-Host "=== 进程检查 ==="
wsl -d Ubuntu bash -c "docker exec bpay-monitor ps aux" | Select-String "Xvfb|wechat |main_linux|app.py|websockify|xfdesktop|fcitx"

Write-Host "=== Umi-OCR API ==="
wsl -d Ubuntu bash -c "docker exec bpay-monitor curl -s -w '%{http_code}' -o /dev/null http://127.0.0.1:1224/api/ocr/get_options"

Write-Host "=== WebUI ==="
wsl -d Ubuntu bash -c "docker exec bpay-monitor curl -s -w '%{http_code}' -o /dev/null http://127.0.0.1:5000/"

Write-Host "=== VNC ==="
wsl -d Ubuntu bash -c "docker exec bpay-monitor curl -s -w '%{http_code}' -o /dev/null http://127.0.0.1:6080/vnc_lite.html"

Write-Host ""
Write-Host "==========================================="
Write-Host "  部署完成！请访问："
Write-Host "  VNC:  http://localhost:6080/vnc_lite.html"
Write-Host "  WebUI: http://localhost:5000"
Write-Host "==========================================="
