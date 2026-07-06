#!/usr/bin/env python3
"""BPay Linux 支付监控系统 - WebUI版"""

import json
import os
import threading
import time
import hashlib
import re
import subprocess
import base64
from datetime import datetime, timedelta
from io import BytesIO
from queue import Queue

import requests
import urllib3
from flask import Flask, jsonify, render_template, request, Response, send_file
from PIL import Image

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, "..", "config.json")
LOGS_DIR = os.path.join(BASE_DIR, "..", "logs")

app = Flask(__name__)

# Global state
monitor_state = {
    "wechat_enabled": False,       # 用户意图：是否启用微信监控
    "alipay_enabled": False,       # 用户意图：是否启用支付宝监控
    "wechat_status": "未启动",
    "alipay_status": "未启动",
    "wechat_scanning": False,      # 实际是否正在 OCR 扫描
    "alipay_scanning": False,      # 实际是否正在 API 轮询
    "order_polling": False,        # 订单轮询内部线程是否运行
}
selected_payment_wid = None
log_queues = []
payment_history = []
alipay_order_history = []

# 订单驱动监控
active_orders = {}  # {trade_no: {info, deadline, matched}}
active_orders_lock = threading.Lock()



def get_config():
    default = {
        "ocr_method": "百度OCR",
        "baidu_api_key": "",
        "baidu_secret_key": "",
        "custom_api_url": "",
        "notification_url": "",
        "timer_interval": "10",
        "wechat_type": "收款助手",
        "alipay_cookie": "",
        "alipay_ctoken": "",
        "alipay_userid": "",
        "alipay_interval": "5",
        "alipay_app_id": "",
        "alipay_private_key": "",
        "alipay_public_key": "",
        "alipay_app_interval": "5",
        "payment_gateway": "",
    }
    try:
        if os.path.exists(CONFIG_FILE):
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                cfg = json.load(f)
                default.update(cfg)
    except Exception:
        pass
    return default


def save_config(cfg):
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=4)


def add_log(message, level="info"):
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    entry = {"time": ts, "msg": message, "level": level}
    for q in log_queues[:]:
        try:
            q.put_nowait(entry)
        except Exception:
            log_queues.remove(q)
    try:
        daily = os.path.join(LOGS_DIR, datetime.now().strftime("%Y-%m-%d"))
        os.makedirs(daily, exist_ok=True)
        with open(os.path.join(daily, "monitor.log"), "a", encoding="utf-8") as f:
            f.write(f"[{ts}] [{level.upper()}] {message}\n")
    except Exception:
        pass


# ──────────────────── WeChat Process Management ────────────────────


def wechat_running():
    try:
        r = subprocess.run(["pgrep", "-x", "wechat"], capture_output=True, text=True, timeout=5)
        return r.returncode == 0
    except:
        return False


def _parse_geometry(geo_text):
    """Parse xdotool getwindowgeometry output"""
    geo = {"x": 0, "y": 0, "width": 0, "height": 0}
    for line in geo_text.split("\n"):
        if "Position:" in line:
            val = line.split(":", 1)[1].strip()
            coord = val.split(",")
            geo["x"] = int(coord[0].strip())
            geo["y"] = int(coord[1].strip().split()[0])
        elif "Geometry:" in line:
            val = line.split(":", 1)[1].strip().split("x")
            geo["width"] = int(val[0].strip())
            geo["height"] = int(val[1].strip())
    return geo


def get_wechat_windows():
    try:
        r = subprocess.run(
            ["xdotool", "search", "--all", "--name", "微信"],
            capture_output=True, text=True, timeout=5,
            env={**os.environ, "DISPLAY": ":1"},
        )
        if r.returncode != 0 or not r.stdout.strip():
            return []
        windows = []
        for wid in r.stdout.strip().split("\n"):
            geo_r = subprocess.run(
                ["xdotool", "getwindowgeometry", wid],
                capture_output=True, text=True, timeout=5,
                env={**os.environ, "DISPLAY": ":1"},
            )
            geo = _parse_geometry(geo_r.stdout)
            geo["id"] = wid
            geo["name"] = "微信"
            windows.append(geo)
        return windows
    except:
        return []


def get_all_windows():
    try:
        r = subprocess.run(
            ["xdotool", "search", "--all", "--name", ".*"],
            capture_output=True, text=True, timeout=5,
            env={**os.environ, "DISPLAY": ":1"},
        )
        if r.returncode != 0 or not r.stdout.strip():
            return []
        windows = []
        for wid in r.stdout.strip().split("\n"):
            name_r = subprocess.run(
                ["xdotool", "getwindowname", wid],
                capture_output=True, text=True, timeout=3,
                env={**os.environ, "DISPLAY": ":1"},
            )
            name = name_r.stdout.strip() if name_r.returncode == 0 else ""
            if not name:
                continue
            geo_r = subprocess.run(
                ["xdotool", "getwindowgeometry", wid],
                capture_output=True, text=True, timeout=3,
                env={**os.environ, "DISPLAY": ":1"},
            )
            geo = _parse_geometry(geo_r.stdout)
            windows.append({"id": wid, "name": name, **geo})
        return windows
    except:
        return []


def get_wechat_status_info():
    if not wechat_running():
        return {"status": "not_running", "message": "未启动"}
    wechat_wins = get_wechat_windows()
    login_wins = [w for w in wechat_wins if 100 < w.get("width", 0) < 400 and w.get("height", 0) < 500]
    main_wins = [w for w in wechat_wins if w.get("width", 0) > 700 and w.get("height", 0) > 500]
    if main_wins:
        return {"status": "logged_in", "message": f"已登录 ({len(main_wins)}个窗口)", "windows": wechat_wins, "login_window": main_wins[0]}
    if login_wins:
        return {"status": "login_screen", "message": "登录界面（等待扫码）", "windows": wechat_wins, "login_window": login_wins[0]}
    if wechat_wins:
        return {"status": "other", "message": f"运行中 ({len(wechat_wins)}个窗口)", "windows": wechat_wins}
    return {"status": "running_no_window", "message": "运行中（无窗口）", "windows": []}


# ──────────────────── Screenshot ────────────────────


def take_screenshot(wid=None):
    path = "/tmp/bpay_screenshot.png"
    target = wid or selected_payment_wid
    if target:
        subprocess.run(
            ["import", "-window", target, path],
            capture_output=True, timeout=15,
            env={**os.environ, "DISPLAY": ":1"},
        )
        if os.path.exists(path):
            return path
    try:
        subprocess.run(
            ["scrot", path],
            display=":1",
            capture_output=True,
            timeout=15,
            env={**os.environ, "DISPLAY": ":1"},
        )
        if os.path.exists(path):
            return path
    except Exception:
        pass
    try:
        subprocess.run(
            ["import", "-window", "root", path],
            display=":1",
            capture_output=True,
            timeout=15,
            env={**os.environ, "DISPLAY": ":1"},
        )
        if os.path.exists(path):
            return path
    except Exception:
        pass
    try:
        result = subprocess.run(
            ["xdotool", "search", "--name", "微信"],
            capture_output=True, text=True, timeout=5,
            env={**os.environ, "DISPLAY": ":1"},
        )
        if result.returncode == 0 and result.stdout.strip():
            wid = result.stdout.strip().split("\n")[0]
            subprocess.run(
                ["import", "-window", wid, path],
                capture_output=True, timeout=15,
                env={**os.environ, "DISPLAY": ":1"},
            )
            if os.path.exists(path):
                return path
    except Exception:
        pass
    return None


# ──────────────────── OCR ────────────────────


def ocr_baidu(img_path, api_key, secret_key):
    try:
        auth_url = f"https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials&client_id={api_key}&client_secret={secret_key}"
        token = requests.get(auth_url, timeout=10).json().get("access_token")
        if not token:
            return None
        with open(img_path, "rb") as f:
            b64 = base64.b64encode(f.read()).decode()
        resp = requests.post(
            "https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic",
            data={"access_token": token, "image": b64, "language_type": "CHN_ENG"},
            timeout=15,
        )
        return resp.json()
    except Exception as e:
        add_log(f"百度OCR异常: {e}", "error")
        return None


def ocr_custom(img_path, api_url):
    try:
        with open(img_path, "rb") as f:
            resp = requests.post(api_url, files={"image": f}, timeout=15)
        return resp.json()
    except Exception as e:
        add_log(f"自定义OCR异常: {e}", "error")
        return None


def ocr_local(img_path):
    url = "http://127.0.0.1:1224/api/ocr"
    try:
        with open(img_path, "rb") as f:
            b64 = base64.b64encode(f.read()).decode()
        resp = requests.post(
            url,
            json={"base64": b64, "options": {"tbpu.parser": "single_line"}},
            timeout=15,
        )
        result = resp.json()
        if result and "data" in result:
            return {"words_result": [{"words": item["text"]} for item in result["data"]]}
    except Exception as e:
        add_log(f"本地OCR异常: {e}", "error")
    return None


def do_ocr(img_path):
    cfg = get_config()
    method = cfg.get("ocr_method", "百度OCR")
    if method == "百度OCR":
        return ocr_baidu(img_path, cfg.get("baidu_api_key", ""), cfg.get("baidu_secret_key", ""))
    elif method == "自定义API":
        return ocr_custom(img_path, cfg.get("custom_api_url", ""))
    else:
        return ocr_local(img_path)


# ──────────────────── Payment Extraction ────────────────────


def extract_payment_info(ocr_result, wechat_type):
    if not ocr_result or "words_result" not in ocr_result:
        return None
    words = [item["words"] for item in ocr_result["words_result"]]
    info = {}

    if wechat_type == "收款助手":
        try:
            idx = words.index("收款到账通知")
            if idx + 1 < len(words):
                m = re.match(r"(\d{2})月(\d{2})日\s*(\d{2}):(\d{2})", words[idx + 1])
                if m:
                    now = datetime.now()
                    info["payment_time"] = f"{now.year}-{m.group(1)}-{m.group(2)} {m.group(3)}:{m.group(4)}:00"
                else:
                    info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            else:
                info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        except ValueError:
            info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        try:
            idx = words.index("收款金额")
            info["amount"] = words[idx + 1].replace("￥", "") if idx + 1 < len(words) else None
        except ValueError:
            for w in words:
                if w.startswith("￥"):
                    info["amount"] = w.replace("￥", "")
                    break
        if "amount" not in info or not info["amount"]:
            return None
        try:
            idx = words.index("付款方备注")
            if idx + 1 < len(words):
                info["remark"] = words[idx + 1]
        except ValueError:
            pass
        info["payer"] = info.get("remark", "")
    else:
        try:
            idx = words.index("收款金额")
            info["amount"] = words[idx + 1].replace("￥", "")
        except ValueError:
            return None
        try:
            idx = words.index("来自")
            info["payer"] = words[idx + 1]
        except ValueError:
            pass
        try:
            idx = words.index("到账时间")
            info["payment_time"] = words[idx + 1]
        except ValueError:
            info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    if "amount" not in info or not info.get("amount"):
        return None
    info["payment_type"] = "wechat"
    return info


def is_duplicate(payment_info):
    if not payment_info:
        return True
    payer = payment_info.get("payer", "").strip()
    identifier = f"{payment_info.get('payment_time', '')}_{payer}_"
    if identifier in payment_history:
        return True
    payment_history.append(identifier)
    return False


def send_notification(payment_info):
    cfg = get_config()
    gateway = cfg.get("payment_gateway", "") or cfg.get("notification_url", "")
    if not gateway:
        add_log("未配置支付网关，跳过通知", "warn")
        return False
    url = gateway.rstrip("/") + "/api/balance_notify.php"
    data = {
        "change_amount": str(payment_info.get("amount", "")),
        "change_time": payment_info.get("payment_time", ""),
        "payment_type": payment_info.get("payment_type", ""),
    }
    params = {k: v for k, v in data.items() if v is not None and k != "sign"}
    sign_content = "&".join(f"{k}={v}" for k, v in sorted(params.items())) + "qwer"
    data["sign"] = hashlib.md5(sign_content.encode("utf-8")).hexdigest()
    try:
        r = requests.post(url, json=data, timeout=10, verify=False)
        return r.status_code == 200
    except Exception as e:
        add_log(f"发送通知失败: {e}", "error")
        return False


# ──────────────────── Order-Driven Monitor ────────────────────


def fetch_active_orders():
    """轮询 PHP 获取待支付订单"""
    cfg = get_config()
    gateway = cfg.get("payment_gateway", "") or cfg.get("notification_url", "")
    if not gateway:
        return []
    url = gateway.rstrip("/") + "/api/get_pending_orders.php"
    try:
        r = requests.get(url, timeout=10, verify=False)
        data = r.json()
        if data.get("code") == "success":
            return data.get("data", [])
    except Exception as e:
        add_log(f"获取待监控订单失败: {e}", "warn")
    return []


def get_active_order_by_amount(amount, pay_type):
    """在活跃订单中按金额匹配待支付订单 (FIFO)"""
    with active_orders_lock:
        candidates = []
        for trade_no, ao in active_orders.items():
            if ao["matched"]:
                continue
            info = ao["info"]
            if info["type"] != pay_type:
                continue
            order_money = float(info["money"])
            if abs(order_money - float(amount)) <= 0.02:
                candidates.append((info["create_time"], trade_no, ao))
        if not candidates:
            return None
        candidates.sort(key=lambda x: x[0])
        _, trade_no, ao = candidates[0]
        ao["matched"] = True
        return ao["info"]


def send_notification_with_order(payment_info, order_info):
    """发送支付通知到 balance_notify.php，带上 trade_no 直匹配"""
    cfg = get_config()
    gateway = cfg.get("payment_gateway", "") or cfg.get("notification_url", "")
    if not gateway:
        add_log("未配置支付网关，跳过通知", "warn")
        return False
    url = gateway.rstrip("/") + "/api/balance_notify.php"
    data = {
        "trade_no": order_info["trade_no"],
        "change_amount": str(payment_info.get("amount", "")),
        "change_time": payment_info.get("payment_time", ""),
        "payment_type": payment_info.get("payment_type", ""),
    }
    params = {k: v for k, v in data.items() if v is not None and k != "sign"}
    sign_content = "&".join(f"{k}={v}" for k, v in sorted(params.items())) + "qwer"
    data["sign"] = hashlib.md5(sign_content.encode("utf-8")).hexdigest()
    try:
        r = requests.post(url, json=data, timeout=10, verify=False)
        ok = r.status_code == 200
        if ok:
            add_log(f"订单 {order_info['trade_no']} 通知发送成功", "success")
        else:
            add_log(f"订单 {order_info['trade_no']} 通知发送失败: HTTP {r.status_code}", "error")
        return ok
    except Exception as e:
        add_log(f"发送通知异常: {e}", "error")
        return False


def order_polling_loop():
    """核心循环：轮询 PHP 获取待支付订单，按需启停 OCR/API 线程"""
    add_log("订单轮询已启动")
    while monitor_state["order_polling"]:
        try:
            # 如果用户关闭了所有监控，停止轮询
            if not monitor_state["wechat_enabled"] and not monitor_state["alipay_enabled"]:
                add_log("所有监控已关闭，订单轮询停止", "info")
                break

            orders = fetch_active_orders()
            now = time.time()

            with active_orders_lock:
                # 清除已匹配或已过期的订单
                expired = []
                for trade_no, ao in list(active_orders.items()):
                    if ao["matched"]:
                        expired.append(trade_no)
                        add_log(f"订单 {trade_no} 已支付，移除监控", "success")
                    elif now > ao["deadline"]:
                        expired.append(trade_no)
                        add_log(f"订单 {trade_no} 已超时，停止监控", "info")
                for t in expired:
                    del active_orders[t]

                # 添加新订单
                for o in orders:
                    tn = o["trade_no"]
                    if tn not in active_orders:
                        active_orders[tn] = {
                            "info": o,
                            "deadline": o["expire_time"],
                            "matched": False,
                        }
                        add_log(f"新订单加入监控: {tn} 金额 ¥{o['money']} 类型 {o['type']}", "info")

            # 按需启停子线程
            with active_orders_lock:
                has_wxpay = any(
                    not ao["matched"] and ao["info"]["type"] == "wxpay"
                    for ao in active_orders.values()
                )
                has_alipay = any(
                    not ao["matched"] and ao["info"]["type"] == "alipay"
                    for ao in active_orders.values()
                )

            # 微信：用户启用 + 有订单 → 启动 OCR 线程
            if monitor_state["wechat_enabled"] and has_wxpay and not monitor_state["wechat_scanning"]:
                monitor_state["wechat_scanning"] = True
                monitor_state["wechat_status"] = "检测中"
                t = threading.Thread(target=wechat_monitor_loop, daemon=True)
                t.start()
                add_log("有待支付微信订单，OCR 启动", "info")
            elif (not monitor_state["wechat_enabled"] or not has_wxpay) and monitor_state["wechat_scanning"]:
                monitor_state["wechat_scanning"] = False
                monitor_state["wechat_status"] = "待命中" if monitor_state["wechat_enabled"] else "已停用"
                add_log("微信 OCR 暂停", "info")

            # 按微信订单数动态调整检测间隔
            wx_count = sum(
                1 for ao in active_orders.values()
                if not ao["matched"] and ao["info"]["type"] == "wxpay"
            )
            if monitor_state["wechat_enabled"] and has_wxpay:
                interval = max(3, 10 - wx_count)
                cfg = get_config()
                cfg["timer_interval"] = str(interval)
                save_config(cfg)

            time.sleep(3)
        except Exception as e:
            add_log(f"订单轮询异常: {e}", "error")
            time.sleep(5)

    # 清理
    monitor_state["order_polling"] = False
    monitor_state["wechat_scanning"] = False
    monitor_state["alipay_scanning"] = False
    with active_orders_lock:
        active_orders.clear()
    add_log("订单轮询已停止")


# ──────────────────── WeChat Monitor ────────────────────


def wechat_monitor_loop():
    add_log("微信 OCR 已启动")
    consecutive_failures = 0
    while monitor_state["wechat_scanning"]:
        try:
            # 检查是否还有待支付的微信订单（用户也可能中途关闭了微信监控）
            if not monitor_state["wechat_enabled"]:
                add_log("微信监控已停用，OCR 停止", "info")
                break
            with active_orders_lock:
                has_wxpay = any(
                    not ao["matched"] and ao["info"]["type"] == "wxpay"
                    for ao in active_orders.values()
                )
            if not has_wxpay:
                add_log("无待支付微信订单，OCR 暂停", "info")
                break

            img_path = take_screenshot()
            if not img_path:
                add_log("截图失败，重试中...", "warn")
                consecutive_failures += 1
                time.sleep(min(consecutive_failures, 5))
                continue

            consecutive_failures = 0
            add_log("截图成功，正在OCR识别...")
            cfg = get_config()
            ocr_result = do_ocr(img_path)
            if not ocr_result:
                add_log("OCR识别失败", "warn")
                time.sleep(int(cfg.get("timer_interval", "10")))
                continue

            payment_info = extract_payment_info(ocr_result, cfg.get("wechat_type", "收款助手"))
            if payment_info:
                if is_duplicate(payment_info):
                    add_log("检测到重复支付，已跳过", "info")
                else:
                    amount = payment_info.get("amount", "")
                    msg = f"检测到收款: ¥{amount} 来自: {payment_info.get('payer', '未知')}"
                    add_log(msg, "success")

                    order_info = get_active_order_by_amount(amount, "wxpay")
                    if order_info:
                        add_log(f"匹配到订单 {order_info['trade_no']}", "success")
                        send_notification_with_order(payment_info, order_info)
                    else:
                        add_log("未匹配到待支付订单，发普通通知", "info")
                        send_notification(payment_info)
            else:
                add_log("未能提取支付信息", "info")

            time.sleep(int(cfg.get("timer_interval", "10")))
        except Exception as e:
            add_log(f"微信监控异常: {e}", "error")
            time.sleep(10)

    monitor_state["wechat_scanning"] = False
    if monitor_state["wechat_enabled"]:
        monitor_state["wechat_status"] = "待命中"
    else:
        monitor_state["wechat_status"] = "已停用"


# ──────────────────── Alipay Monitor ────────────────────


def extract_from_cookie(cookie, key):
    try:
        for item in cookie.split(";"):
            item = item.strip()
            if "=" in item:
                k, v = item.split("=", 1)
                if k.strip() == key:
                    return v.strip()
    except Exception:
        pass
    return None


def query_alipay_orders(cookie, ctoken, user_id, page_num=1, page_size=10):
    url = f"https://mbillexprod.alipay.com/enterprise/tradeListQuery.json?ctoken={ctoken}&_output_charset=utf-8"
    headers = {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        "Referer": "https://b.alipay.com/",
        "Origin": "https://b.alipay.com",
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        "Cookie": cookie,
    }
    data = {
        "billUserId": user_id,
        "entityFilterType": "1",
        "tradeFrom": "ALL",
        "targetTradeOwner": "USERID",
        "pageNum": page_num,
        "pageSize": page_size,
        "startTime": datetime.now().strftime("%Y-%m-%d") + " 00:00:00",
        "endTime": (datetime.now() + timedelta(days=1)).strftime("%Y-%m-%d") + " 00:00:00",
        "status": "ALL",
        "sortType": "0",
        "timeType": "gmtTradeCreate",
        "_input_charset": "gbk",
    }
    try:
        resp = requests.post(url, data=data, headers=headers, timeout=30, verify=False)
        result = resp.json()
        if result.get("status") == "succeed" or result.get("success") == "true":
            return {"status": "success", "data": result.get("result", {}).get("detail", [])}
        return {"status": "error", "message": result.get("message", "请求失败")}
    except Exception as e:
        return {"status": "error", "message": str(e)}


def alipay_monitor_loop(cookie, ctoken, user_id, interval):
    add_log("支付宝 API 已启动")
    while monitor_state["alipay_scanning"]:
        try:
            if not monitor_state["alipay_enabled"]:
                add_log("支付宝监控已停用，API 停止", "info")
                break
            with active_orders_lock:
                has_alipay = any(
                    not ao["matched"] and ao["info"]["type"] == "alipay"
                    for ao in active_orders.values()
                )
            if not has_alipay:
                add_log("无待支付支付宝订单，API 暂停", "info")
                break

            result = query_alipay_orders(cookie, ctoken, user_id, page_size=10)
            if result.get("status") == "success":
                for order in result.get("data", []):
                    trade_no = order.get("tradeNo", "")
                    if order.get("tradeStatus") == "成功" and trade_no not in alipay_order_history:
                        alipay_order_history.append(trade_no)
                        pay_info = {
                            "payment_type": "alipay",
                            "amount": order.get("totalAmount", ""),
                            "payment_time": order.get("gmtTradeCreate", ""),
                            "payer": order.get("buyerName", ""),
                        }
                        add_log(f"检测到新支付宝订单: ¥{pay_info['amount']}", "success")

                        order_info = get_active_order_by_amount(pay_info["amount"], "alipay")
                        if order_info:
                            add_log(f"匹配到订单 {order_info['trade_no']}", "success")
                            send_notification_with_order(pay_info, order_info)
                        else:
                            add_log("未匹配到待支付订单，发普通通知", "info")
                            send_notification(pay_info)
            else:
                add_log(f"支付宝查询失败: {result.get('message', '')}", "warn")
            for _ in range(int(interval * 10)):
                if not monitor_state["alipay_scanning"]:
                    break
                time.sleep(0.1)
        except Exception as e:
            add_log(f"支付宝监控异常: {e}", "error")
            time.sleep(interval)

    monitor_state["alipay_scanning"] = False
    if monitor_state["alipay_enabled"]:
        monitor_state["alipay_status"] = "待命中"
    else:
        monitor_state["alipay_status"] = "已停用"


def alipay_app_monitor_loop(app_id, private_key, interval):
    add_log("支付宝应用支付监控已启动")
    last_balance = None
    while monitor_state["alipay_running"]:
        try:
            biz_content = {}
            common = {
                "app_id": app_id,
                "method": "alipay.data.bill.balance.query",
                "format": "JSON",
                "charset": "utf-8",
                "sign_type": "RSA2",
                "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "version": "1.0",
                "biz_content": json.dumps(biz_content),
            }
            pk = private_key.replace("-----BEGIN RSA PRIVATE KEY-----", "").replace("-----END RSA PRIVATE KEY-----", "").replace("\n", "")
            try:
                from cryptography.hazmat.primitives import hashes, serialization
                from cryptography.hazmat.primitives.asymmetric import padding
                pk_bytes = base64.b64decode(pk)
                key_obj = serialization.load_der_private_key(pk_bytes, password=None)
                sign_content = "&".join(f"{k}={v}" for k, v in sorted(common.items()))
                sig = key_obj.sign(sign_content.encode(), padding.PKCS1v15(), hashes.SHA256())
                common["sign"] = base64.b64encode(sig).decode()
            except Exception:
                add_log("RSA签名失败，无法查询支付宝余额", "error")
                break
            resp = requests.post("https://openapi.alipay.com/gateway.do", data=common, timeout=30)
            result = resp.json().get("alipay_data_bill_balance_query_response", {})
            if result.get("code") == "10000":
                balance = float(result.get("available_amount", 0))
                add_log(f"支付宝当前余额: ¥{balance}")
                if last_balance is not None and balance > last_balance:
                    diff = balance - last_balance
                    add_log(f"余额增加: ¥{diff}", "success")
                    send_notification({"amount": str(diff), "payment_time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"), "payment_type": "alipay_app"})
                last_balance = balance
            else:
                add_log(f"余额查询失败: {result.get('msg', '')}", "warn")
            for _ in range(int(interval * 10)):
                if not monitor_state["alipay_running"]:
                    break
                time.sleep(0.1)
        except Exception as e:
            add_log(f"支付宝应用监控异常: {e}", "error")
            time.sleep(interval)


# ──────────────────── Flask Routes ────────────────────


@app.route("/")
def index():
    return render_template("index.html")


@app.route("/api/config", methods=["GET", "POST"])
def api_config():
    if request.method == "POST":
        cfg = request.json
        save_config(cfg)
        return jsonify({"status": "ok"})
    return jsonify(get_config())


@app.route("/api/status")
def api_status():
    info = get_wechat_status_info()
    pw_name = ""
    if selected_payment_wid:
        try:
            r = subprocess.run(["xdotool", "getwindowname", selected_payment_wid],
                               capture_output=True, text=True, timeout=3,
                               env={**os.environ, "DISPLAY": ":1"})
            if r.returncode == 0 and r.stdout.strip():
                pw_name = r.stdout.strip()
        except Exception:
            pass
    with active_orders_lock:
        active_count = len(active_orders)
    return jsonify({
        "wechat_enabled": monitor_state["wechat_enabled"],
        "alipay_enabled": monitor_state["alipay_enabled"],
        "wechat_status": monitor_state["wechat_status"],
        "alipay_status": monitor_state["alipay_status"],
        "wechat_scanning": monitor_state["wechat_scanning"],
        "alipay_scanning": monitor_state["alipay_scanning"],
        "order_polling": monitor_state["order_polling"],
        "wechat_process": info,
        "payment_window": {"wid": selected_payment_wid, "name": pw_name} if selected_payment_wid else None,
        "active_orders": active_count,
    })


@app.route("/api/logs")
def api_logs():
    def stream():
        q = Queue()
        log_queues.append(q)
        try:
            while True:
                entry = q.get()
                yield f"data: {json.dumps(entry, ensure_ascii=False)}\n\n"
        except GeneratorExit:
            log_queues.remove(q)
    return Response(stream(), mimetype="text/event-stream")


@app.route("/api/screenshot")
def api_screenshot():
    path = take_screenshot()
    if path:
        return send_file(path, mimetype="image/png")
    return jsonify({"error": "截图失败"}), 500


@app.route("/api/wechat/start", methods=["POST"])
def api_wechat_start():
    """启用微信监控（不代表立即开始扫描，有待支付订单时自动启动 OCR）"""
    if monitor_state["wechat_enabled"]:
        return jsonify({"status": "already_enabled"})
    monitor_state["wechat_enabled"] = True
    monitor_state["wechat_status"] = "已启用"
    # 确保订单轮询运行
    if not monitor_state["order_polling"]:
        monitor_state["order_polling"] = True
        t = threading.Thread(target=order_polling_loop, daemon=True)
        t.start()
    add_log("微信监控已启用", "info")
    return jsonify({"status": "enabled"})


@app.route("/api/wechat/stop", methods=["POST"])
def api_wechat_stop():
    """停用微信监控"""
    monitor_state["wechat_enabled"] = False
    monitor_state["wechat_status"] = "已停用"
    monitor_state["wechat_scanning"] = False
    add_log("微信监控已停用", "info")
    return jsonify({"status": "disabled"})


@app.route("/api/wechat/test", methods=["POST"])
def api_wechat_test():
    img_path = take_screenshot()
    if not img_path:
        return jsonify({"error": "截图失败"}), 500
    ocr_result = do_ocr(img_path)
    if not ocr_result:
        return jsonify({"error": "OCR识别失败"}), 500
    cfg = get_config()
    info = extract_payment_info(ocr_result, cfg.get("wechat_type", "收款助手"))
    return jsonify({"ocr_text": [w["words"] for w in ocr_result.get("words_result", [])], "payment_info": info})


@app.route("/api/wechat/process/start", methods=["POST"])
def api_wechat_process_start():
    if wechat_running():
        return jsonify({"status": "already_running"})
    try:
        subprocess.Popen(
            ["wechat", "--no-sandbox", "--disable-gpu"],
            env={**os.environ, "DISPLAY": ":1"},
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        add_log("微信进程已启动")
        return jsonify({"status": "started"})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/api/wechat/process/stop", methods=["POST"])
def api_wechat_process_stop():
    try:
        subprocess.run(["pkill", "-x", "wechat"], timeout=5)
        add_log("微信进程已关闭")
        return jsonify({"status": "stopped"})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/api/wechat/process/status")
def api_wechat_process_status():
    return jsonify(get_wechat_status_info())


@app.route("/api/wechat/windows")
def api_wechat_windows():
    return jsonify(get_all_windows())


@app.route("/api/wechat/login/detect", methods=["POST"])
def api_wechat_login_detect():
    action = (request.json or {}).get("action", "start")
    if action == "start":
        if not wechat_running():
            return jsonify({"error": "微信未运行"}), 400
        monitor_state["auto_detect_running"] = True
        def detect_loop():
            while monitor_state.get("auto_detect_running"):
                info = get_wechat_status_info()
                if info["status"] == "logged_in":
                    add_log("检测到微信登录成功！", "success")
                    break
                time.sleep(2)
        threading.Thread(target=detect_loop, daemon=True).start()
        return jsonify({"status": "detection_started"})
    else:
        monitor_state["auto_detect_running"] = False
        return jsonify({"status": "detection_stopped"})


@app.route("/api/wechat/window/screenshot", methods=["POST"])
def api_wechat_window_screenshot():
    data = request.get_json(silent=True) or {}
    wid = data.get("wid") or request.form.get("wid")
    if not wid:
        return jsonify({"error": "缺少窗口ID"}), 400
    path = os.path.join("/tmp", f"bpay_win_{wid}.png")
    try:
        subprocess.run(
            ["import", "-window", wid, path],
            capture_output=True, timeout=15,
            env={**os.environ, "DISPLAY": ":1"},
        )
        if os.path.exists(path):
            return send_file(path, mimetype="image/png")
        return jsonify({"error": "截图失败，窗口可能已关闭"}), 500
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/api/wechat/service/open", methods=["POST"])
def api_wechat_service_open():
    data = request.get_json(silent=True) or {}
    wid = data.get("wid")
    service_type = data.get("service_type") or get_config().get("wechat_type", "收款助手")
    if not wid:
        return jsonify({"error": "未选择窗口"}), 400
    service_name = "微信收款助手" if service_type == "收款助手" else "微信支付"
    env = {**os.environ, "DISPLAY": ":1"}
    global selected_payment_wid

    def find_service_window():
        for w in get_all_windows():
            if service_name in w.get("name", ""):
                return w
        return None

    try:
        subprocess.run(["xdotool", "windowactivate", wid], check=True, timeout=5, env=env)
        time.sleep(0.5)
        subprocess.run(["xdotool", "mousemove", "--window", wid, "130", "40"],
                       check=True, timeout=3, env=env)
        subprocess.run(["xdotool", "click", "1"], check=True, timeout=3, env=env)
        time.sleep(0.3)
        clip = subprocess.Popen(["xclip", "-selection", "clipboard", "-i"],
                                stdin=subprocess.PIPE, env=env)
        clip.communicate(service_name.encode("utf-8"))
        time.sleep(0.2)
        subprocess.run(["xdotool", "key", "ctrl+v"], check=True, timeout=5, env=env)
        subprocess.run(["xdotool", "key", "Return"], check=True, timeout=3, env=env)
        time.sleep(1.5)
        found = find_service_window()
        if found:
            selected_payment_wid = found["id"]
            add_log(f"服务号已打开 → 自动选中: {found['name']} ({found['id']})")
            return jsonify({"status": "ok", "service": service_name, "window": found})
        add_log("Enter未打开服务号，点击第一个搜索结果")
        subprocess.run(["xdotool", "mousemove", "--window", wid, "130", "120"],
                       check=True, timeout=3, env=env)
        subprocess.run(["xdotool", "click", "1"], check=True, timeout=3, env=env)
        time.sleep(1)
        found = find_service_window()
        if found:
            selected_payment_wid = found["id"]
            add_log(f"已点击打开服务号 → 自动选中: {found['name']} ({found['id']})")
            return jsonify({"status": "ok", "service": service_name, "window": found})
        add_log(f"未能打开服务号: {service_name}", "error")
        return jsonify({"status": "failed", "message": f"未能打开{service_name}"})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/api/wechat/payment-window", methods=["GET", "POST"])
def api_payment_window():
    global selected_payment_wid
    if request.method == "POST":
        data = request.get_json(silent=True) or {}
        selected_payment_wid = data.get("wid") or None
        return jsonify({"status": "ok", "wid": selected_payment_wid})
    info = {"wid": selected_payment_wid}
    if selected_payment_wid:
        try:
            r = subprocess.run(
                ["xdotool", "getwindowname", selected_payment_wid],
                capture_output=True, text=True, timeout=3,
                env={**os.environ, "DISPLAY": ":1"},
            )
            if r.returncode == 0 and r.stdout.strip():
                info["name"] = r.stdout.strip()
        except Exception:
            pass
    return jsonify(info)


@app.route("/api/alipay/start", methods=["POST"])
def api_alipay_start():
    """启用支付宝监控（不代表立即开始扫描，有待支付订单时自动启动 API）"""
    if monitor_state["alipay_enabled"]:
        return jsonify({"status": "already_enabled"})
    monitor_state["alipay_enabled"] = True
    monitor_state["alipay_status"] = "已启用"
    if not monitor_state["order_polling"]:
        monitor_state["order_polling"] = True
        t = threading.Thread(target=order_polling_loop, daemon=True)
        t.start()
    add_log("支付宝监控已启用", "info")
    return jsonify({"status": "enabled"})


@app.route("/api/alipay/stop", methods=["POST"])
def api_alipay_stop():
    """停用支付宝监控"""
    monitor_state["alipay_enabled"] = False
    monitor_state["alipay_status"] = "已停用"
    monitor_state["alipay_scanning"] = False
    add_log("支付宝监控已停用", "info")
    return jsonify({"status": "disabled"})


@app.route("/api/alipay/test", methods=["POST"])
def api_alipay_test():
    cfg = get_config()
    data = request.json or {}
    cookie = data.get("cookie", cfg.get("alipay_cookie", ""))
    ctoken = data.get("ctoken", cfg.get("alipay_ctoken", ""))
    userid = data.get("userid", cfg.get("alipay_userid", ""))
    result = query_alipay_orders(cookie, ctoken, userid, page_size=1)
    return jsonify(result)


@app.route("/api/history")
def api_history():
    return jsonify({"wechat": payment_history[-100:], "alipay": alipay_order_history[-100:]})


@app.route("/api/clear_history", methods=["POST"])
def api_clear_history():
    payment_history.clear()
    alipay_order_history.clear()
    return jsonify({"status": "ok"})


@app.route("/api/orders/start", methods=["POST"])
def api_orders_start():
    """（已弃用）订单驱动已集成到微信/支付宝的启停按钮中，此接口保留兼容"""
    return jsonify({"status": "already_running", "note": "订单驱动已自动集成"})


@app.route("/api/orders/status")
def api_orders_status():
    """查看当前活跃订单"""
    with active_orders_lock:
        orders_list = []
        for trade_no, ao in active_orders.items():
            orders_list.append({
                "trade_no": trade_no,
                "type": ao["info"]["type"],
                "money": ao["info"]["money"],
                "name": ao["info"]["name"],
                "create_time": ao["info"]["create_time"],
                "expire_time": ao["info"]["expire_time"],
                "deadline": ao["deadline"],
                "matched": ao["matched"],
                "remaining": max(0, int(ao["deadline"] - time.time())),
            })
        orders_list.sort(key=lambda x: x["create_time"])
    return jsonify({
        "order_polling": monitor_state["order_polling"],
        "wechat_enabled": monitor_state["wechat_enabled"],
        "alipay_enabled": monitor_state["alipay_enabled"],
        "wechat_scanning": monitor_state["wechat_scanning"],
        "alipay_scanning": monitor_state["alipay_scanning"],
        "active_count": len(orders_list),
        "orders": orders_list,
    })


# ──────────────────── Main ────────────────────

if __name__ == "__main__":
    os.makedirs(LOGS_DIR, exist_ok=True)
    add_log("支付监控系统启动")
    app.run(host="0.0.0.0", port=5000, debug=False, threaded=True)
