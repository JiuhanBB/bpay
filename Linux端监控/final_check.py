import subprocess

def run(cmd):
    r = subprocess.run(cmd, capture_output=True, text=False, timeout=15)
    return r.stdout.decode('utf-8', errors='replace') if r.stdout else ''

print('=== Processes ===')
out = run(['wsl', '-d', 'Ubuntu', 'bash', '-c',
    'docker exec bpay-monitor ps aux'])
for line in out.split('\n'):
    for kw in ['Xvfb', 'wechat', 'main_linux', 'app.py', 'websockify']:
        if kw in line and 'grep' not in line:
            print('  ' + line.strip()[:80])
            break

print()
print('=== Umi-OCR API ===')
out = run(['wsl', '-d', 'Ubuntu', 'bash', '-c',
    'docker exec bpay-monitor bash -c "curl -s -w %{http_code} -o /dev/null http://127.0.0.1:1224/api/ocr/get_options 2>/dev/null"'])
print(f'  HTTP {out.strip() or "FAIL"}')

print()
print('=== WebUI ===')
out = run(['wsl', '-d', 'Ubuntu', 'bash', '-c',
    'docker exec bpay-monitor bash -c "curl -s -w %{http_code} -o /dev/null http://127.0.0.1:5000/ 2>/dev/null"'])
print(f'  HTTP {out.strip() or "FAIL"}')

print()
print('=== WebUI CSS 暗色检查 ===')
out = run(['wsl', '-d', 'Ubuntu', 'bash', '-c',
    'docker exec bpay-monitor bash -c "grep -c \\\"!important\\\" /monitor/templates/index.html 2>/dev/null"'])
print(f'  !important 规则数: {out.strip() or 0}')
out = run(['wsl', '-d', 'Ubuntu', 'bash', '-c',
    'docker exec bpay-monitor bash -c "grep -c \\\"#12151c\\\" /monitor/templates/index.html 2>/dev/null"'])
print(f'  暗色背景: {"OK" if int(out.strip() or 0) > 0 else "MISSING"}')
