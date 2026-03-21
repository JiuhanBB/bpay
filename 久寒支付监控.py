import requests
import urllib3
import base64
import json
import os
import pyautogui
import pygetwindow as gw
import tkinter as tk
from tkinter import ttk, messagebox
from PIL import Image, ImageTk
import threading
import time
from datetime import datetime, timedelta
import subprocess
import ctypes
from ctypes import wintypes
import win32gui
import win32ui
import win32con
import win32api
import psutil

# 禁用SSL警告，允许抓包工具拦截HTTPS请求
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# RSA加密相关
try:
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import padding, rsa
    from cryptography.hazmat.backends import default_backend
    CRYPTOGRAPHY_AVAILABLE = True
except ImportError:
    CRYPTOGRAPHY_AVAILABLE = False
    print("警告: cryptography库未安装，RSA加密功能不可用")
    print("请运行: pip install cryptography")

# 日志目录管理
def get_log_directory():
    """获取日志目录"""
    log_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "logs")
    if not os.path.exists(log_dir):
        os.makedirs(log_dir)
    return log_dir

def get_daily_log_directory():
    """获取当天的日志目录"""
    log_dir = get_log_directory()
    today = datetime.now().strftime("%Y-%m-%d")
    daily_dir = os.path.join(log_dir, today)
    if not os.path.exists(daily_dir):
        os.makedirs(daily_dir)
    return daily_dir

# 二维码处理库
try:
    from pyzbar.pyzbar import decode as decode_qr
    from pyzbar.pyzbar import ZBarSymbol
except ImportError:
    decode_qr = None

try:
    import qrcode
except ImportError:
    qrcode = None



BAIDU_API_KEY = ""
BAIDU_SECRET_KEY = ""
BAIDU_OCR_URL = "https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic"
TEMP_IMAGE_PATH = "temp_screenshot.png"
CONFIG_FILE = "config.json"
UMI_OCR_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "Umi-OCR", "Umi-OCR.exe")

PW_RENDERFULLCONTENT = 0x00000002

GWL_EXSTYLE = -20
WS_EX_LAYERED = 0x80000
LWA_ALPHA = 0x2
WM_PAINT = 0x000F
SW_RESTORE = 9
SW_MINIMIZE = 6

user32 = ctypes.windll.user32
user32.IsIconic.argtypes = [wintypes.HWND]
user32.IsIconic.restype = wintypes.BOOL
user32.GetWindowLongA.argtypes = [wintypes.HWND, wintypes.INT]
user32.GetWindowLongA.restype = wintypes.LONG
user32.SetWindowLongA.argtypes = [wintypes.HWND, wintypes.INT, wintypes.LONG]
user32.SetWindowLongA.restype = wintypes.LONG
user32.SetLayeredWindowAttributes.argtypes = [wintypes.HWND, wintypes.BYTE, wintypes.BYTE, wintypes.INT]
user32.SetLayeredWindowAttributes.restype = wintypes.BOOL
user32.ShowWindow.argtypes = [wintypes.HWND, wintypes.INT]
user32.ShowWindow.restype = wintypes.BOOL
user32.SendMessageA.argtypes = [wintypes.HWND, wintypes.UINT, wintypes.WPARAM, wintypes.LPARAM]
user32.SendMessageA.restype = wintypes.LPARAM

SPI_GETANIMATION = 0x0048
SPI_SETANIMATION = 0x0049
SPIF_SENDCHANGE = 0x02

class ANIMATIONINFO(ctypes.Structure):
    _fields_ = [
        ("cbSize", wintypes.UINT),
        ("iMinAnimate", wintypes.INT)
    ]
    
    def __init__(self, min_animate=False):
        super().__init__()
        self.cbSize = ctypes.sizeof(ANIMATIONINFO)
        self.iMinAnimate = 1 if min_animate else 0

user32.SystemParametersInfoA.argtypes = [wintypes.UINT, wintypes.UINT, ctypes.POINTER(ANIMATIONINFO), wintypes.UINT]
user32.SystemParametersInfoA.restype = wintypes.BOOL

SWP_NOMOVE = 0x0002
SWP_NOZORDER = 0x0004
SWP_SHOWWINDOW = 0x0040

user32.SetWindowPos.argtypes = [wintypes.HWND, wintypes.HWND, wintypes.INT, wintypes.INT, wintypes.INT, wintypes.INT, wintypes.UINT]
user32.SetWindowPos.restype = wintypes.BOOL

class OCRApp:
    def __init__(self, root):
        self.root = root
        self.root.title("久寒包包-支付监控系统")
        self.root.geometry("800x600")
        
        self.payment_history = []
        self.alipay_order_history = []
        
        self.timer_thread = None
        self.timer_running = False
        self.timer_interval = 10.0
        
        # 支付宝监听状态
        self.alipay_monitor_running = False
        self.alipay_app_monitor_running = False
        
        self.windows = self.get_active_windows()
        
        # 配置自动初始化：检测并生成默认配置文件
        if not os.path.exists(CONFIG_FILE):
            default_config = {
                "payment_gateway": "",
                "merchant_id": "",
                "merchant_key": "",
                "alipay_app_id": "",
                "alipay_interval": "5",
                "wechat_monitor_enabled": False
            }
            with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
                json.dump(default_config, f, ensure_ascii=False, indent=4)
            print("已生成默认配置文件")
        
        self.config = self.load_config()
        
        # 启动Umi-OCR作为独立进程
        self.start_umi_ocr()
        
        # 动画设置保存
        self.original_animation = None
        
        # 配置引导标志，防止重复显示
        self.config_guide_shown = False
        
        # 加载历史订单
        self.load_history_orders()
        
        self.create_widgets()
        
        self.root.protocol("WM_DELETE_WINDOW", self.on_closing)
        
        # 启动自动检测
        self.start_auto_detection()
    
    def disable_window_animation(self):
        """禁用窗口动画实现瞬间操作"""
        try:
            self.original_animation = ANIMATIONINFO()
            user32.SystemParametersInfoA(SPI_GETANIMATION, ctypes.sizeof(ANIMATIONINFO), ctypes.byref(self.original_animation), 0)
            
            if self.original_animation.iMinAnimate != 0:
                new_animation = ANIMATIONINFO(min_animate=False)
                user32.SystemParametersInfoA(SPI_SETANIMATION, ctypes.sizeof(ANIMATIONINFO), ctypes.byref(new_animation), SPIF_SENDCHANGE)
        except:
            pass
    
    def enable_window_animation(self):
        """恢复窗口动画设置"""
        try:
            if self.original_animation and self.original_animation.iMinAnimate != 0:
                user32.SystemParametersInfoA(SPI_SETANIMATION, ctypes.sizeof(ANIMATIONINFO), ctypes.byref(self.original_animation), SPIF_SENDCHANGE)
        except:
            pass
    
    def start_umi_ocr(self):
        """启动Umi-OCR作为独立进程（启动后最小化窗口）"""
        try:
            if not os.path.exists(UMI_OCR_PATH):
                print(f"Umi-OCR路径不存在: {UMI_OCR_PATH}")
                return
            
            for proc in psutil.process_iter(['name']):
                if proc.info['name'] == 'Umi-OCR.exe':
                    print("Umi-OCR已在运行")
                    return
            
            # 使用shell=True以独立进程方式启动
            subprocess.Popen(
                f'"{UMI_OCR_PATH}"',
                shell=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL
            )
            
            # 等待窗口出现并最小化
            time.sleep(3)
            self.minimize_umi_ocr_window()
            
            print("Umi-OCR已作为独立进程启动并最小化")
            
        except Exception as e:
            print(f"启动Umi-OCR失败: {str(e)}")
    
    def minimize_umi_ocr_window(self):
        """最小化Umi-OCR窗口"""
        try:
            def enum_windows_callback(hwnd, extra):
                if win32gui.IsWindowVisible(hwnd):
                    title = win32gui.GetWindowText(hwnd)
                    if "Umi-OCR" in title:
                        # 最小化窗口
                        win32gui.ShowWindow(hwnd, win32con.SW_MINIMIZE)
                        print(f"已最小化Umi-OCR窗口: {title}")
            
            win32gui.EnumWindows(enum_windows_callback, None)
        except Exception as e:
            print(f"最小化Umi-OCR窗口失败: {str(e)}")
    
    def stop_umi_ocr(self):
        """停止Umi-OCR（独立进程，通过进程名查找停止）"""
        try:
            for proc in psutil.process_iter(['name']):
                if proc.info['name'] == 'Umi-OCR.exe':
                    proc.terminate()
                    print("Umi-OCR已停止")
        except Exception as e:
            print(f"停止Umi-OCR失败: {str(e)}")
    
    def on_closing(self):
        self.stop_auto_detection()
        self.stop_umi_ocr()
        self.root.destroy()
    
    def load_config(self):
        default_config = {
            "ocr_method": "百度OCR",
            "baidu_api_key": BAIDU_API_KEY,
            "baidu_secret_key": BAIDU_SECRET_KEY,
            "custom_api_url": "",
            "umi_api_url": "http://127.0.0.1:1224/api/ocr",
            "notification_url": "",
            "selected_window": "",
            "timer_interval": "10.0",
            "wechat_type": "收款助手",
            # 支付宝Cookie方式配置
            "alipay_cookie": "",
            "alipay_ctoken": "",
            "alipay_userid": "",
            "alipay_interval": "5",
            # 支付宝应用支付配置
            "alipay_app_id": "",
            "alipay_private_key": "",
            "alipay_public_key": "",
            "alipay_app_interval": "5",
            "alipay_monitor_mode": "cookie",  # cookie 或 app
            # 支付网关配置
            "payment_gateway": "",
            "qr_code_url": ""
        }
        
        try:
            if os.path.exists(CONFIG_FILE):
                with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
                    return json.load(f)
        except Exception as e:
            print(f"加载配置失败: {str(e)}")
            
        return default_config
    
    def save_config(self):
        try:
            config = {
                "ocr_method": self.ocr_method_var.get(),
                "baidu_api_key": self.baidu_key_entry.get(),
                "baidu_secret_key": self.baidu_secret_entry.get(),
                "custom_api_url": self.custom_api_entry.get(),
                "umi_api_url": self.umi_api_entry.get(),
                "notification_url": self.notification_url_entry.get(),
                "selected_window": self.window_var.get(),
                "timer_interval": self.timer_interval_var.get(),
                "wechat_type": self.wechat_type_var.get()
            }
            
            with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
                json.dump(config, f, ensure_ascii=False, indent=4)
                
            return True
        except Exception as e:
            print(f"保存配置失败: {str(e)}")
            return False
    
    def get_active_windows(self):
        return [win.title for win in gw.getAllWindows() if win.title]
    
    def refresh_windows_list(self):
        self.windows = self.get_active_windows()
        self.window_combo['values'] = self.windows
        self.status_var.set(f"已刷新窗口列表，找到 {len(self.windows)} 个窗口")
    
    def get_baidu_access_token(self, api_key, secret_key):
        auth_url = f"https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials&client_id={api_key}&client_secret={secret_key}"
        try:
            response = requests.get(auth_url, timeout=10)
            return response.json().get("access_token")
        except Exception as e:
            print(f"获取百度Access Token失败: {str(e)}")
            return None
    
    def ocr_baidu(self, image_path, api_key, secret_key):
        if not os.path.exists(image_path):
            return None

        try:
            with open(image_path, "rb") as f:
                img_base64 = base64.b64encode(f.read()).decode("utf-8")

            access_token = self.get_baidu_access_token(api_key, secret_key)
            if not access_token:
                return None

            headers = {"Content-Type": "application/x-www-form-urlencoded"}
            params = {
                "access_token": access_token,
                "image": img_base64,
                "language_type": "CHN_ENG"
            }
            response = requests.post(BAIDU_OCR_URL, data=params, headers=headers, timeout=10)
            return response.json()

        except Exception as e:
            print(f"百度OCR识别异常: {str(e)}")
            return None
    
    def ocr_custom_api(self, image_path, api_url):
        if not os.path.exists(image_path):
            return None

        try:
            with open(image_path, "rb") as f:
                files = {"image": f}
                response = requests.post(api_url, files=files, timeout=10)
            
            return response.json()
        except Exception as e:
            print(f"自定义API OCR识别异常: {str(e)}")
            return None
    
    def ocr_umi(self, image_path):
        if not os.path.exists(image_path):
            return None

        try:
            api_url = self.umi_api_entry.get() or "http://127.0.0.1:1224/api/ocr"
            
            with open(image_path, "rb") as image_file:
                img_base64 = base64.b64encode(image_file.read()).decode('utf-8')

            payload = {
                "base64": img_base64,
                "options": {"tbpu.parser": "single_line"}
            }
            headers = {'Content-Type': 'application/json'}

            response = requests.post(api_url, data=json.dumps(payload), headers=headers, timeout=10)
            result = response.json()
            
            if result and 'data' in result:
                words_result = [{"words": item['text']} for item in result['data']]
                return {"words_result": words_result}
            return None
            
        except Exception as e:
            print(f"Umi-OCR识别异常: {str(e)}")
            return None
    
    def ocr_local_image(self, image_path):
        ocr_method = self.ocr_method_var.get()
        
        if ocr_method == "百度OCR":
            api_key = self.baidu_key_entry.get()
            secret_key = self.baidu_secret_entry.get()
            return self.ocr_baidu(image_path, api_key, secret_key)
        
        elif ocr_method == "自定义API":
            api_url = self.custom_api_entry.get()
            return self.ocr_custom_api(image_path, api_url)
        
        elif ocr_method == "Umi-OCR":
            return self.ocr_umi(image_path)
        
        return None
    
    def extract_payment_info(self, ocr_result):
        if not ocr_result or "words_result" not in ocr_result:
            return None
        
        words_list = [item["words"] for item in ocr_result["words_result"]]
        info = {}
        
        # 根据用户选择的支付类型识别
        wechat_type = self.wechat_type_var.get()
        
        if wechat_type == "收款助手":
            # 微信收款助手格式
            try:
                # 提取时间
                notify_index = words_list.index("收款到账通知")
                if notify_index + 1 < len(words_list):
                    time_str = words_list[notify_index + 1]
                    # 格式：02月28日 14:56 或 02月28日14:56
                    import re
                    match = re.match(r'(\d{2})月(\d{2})日\s*(\d{2}):(\d{2})', time_str)
                    if match:
                        month, day, hour, minute = match.groups()
                        current_year = datetime.now().year
                        info["payment_time"] = f"{current_year}-{month}-{day} {hour}:{minute}:00"
                    else:
                        info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                else:
                    info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            
            try:
                # 提取收款金额
                amount_index = words_list.index("收款金额")
                if amount_index + 1 < len(words_list):
                    amount = words_list[amount_index + 1].replace("￥", "")
                    info["amount"] = amount
                else:
                    # 直接查找带￥的金额
                    for word in words_list:
                        if word.startswith("￥"):
                            info["amount"] = word.replace("￥", "")
                            break
                    else:
                        return None
            except ValueError:
                # 直接查找带￥的金额
                for word in words_list:
                    if word.startswith("￥"):
                        info["amount"] = word.replace("￥", "")
                        break
                else:
                    return None
            
            try:
                # 提取付款方备注
                remark_index = words_list.index("付款方备注")
                if remark_index + 1 < len(words_list):
                    info["remark"] = words_list[remark_index + 1]
            except ValueError:
                pass
            
            info["payer"] = info.get("remark", "")
        else:
            # 赞赏码格式
            try:
                amount_index = words_list.index("收款金额")
                amount = words_list[amount_index + 1].replace("￥", "")
                info["amount"] = amount
            except ValueError:
                return None
            
            try:
                from_index = words_list.index("来自")
                info["payer"] = words_list[from_index + 1]
            except ValueError:
                pass
            
            try:
                comment_index = words_list.index("付款方留言")
                info["remark"] = words_list[comment_index + 1]
            except ValueError:
                pass
            
            try:
                time_index = words_list.index("到账时间")
                info["payment_time"] = words_list[time_index + 1]
            except ValueError:
                info["payment_time"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        
        if "amount" not in info:
            return None
        
        # 添加支付类型标识
        info["payment_type"] = "wechat"
        
        return info
    
    def is_duplicate_payment(self, payment_info):
        if not payment_info:
            return False
            
        # 构建与load_history_orders一致的标识符格式
        # 只使用payment_time和payer，不包含remark
        # 这样可以确保与从日志加载的历史订单标识符一致
        # 对payer进行strip()操作，确保与load_history_orders中的处理一致
        payer = payment_info.get('payer', '').strip()
        identifier = f"{payment_info.get('payment_time', '')}_{payer}_"
        
        # 打印标识符，用于调试
        print(f"检查微信订单重复: 标识符='{identifier}', 历史记录中存在={identifier in self.payment_history}")
        
        if identifier in self.payment_history:
            return True
            
        self.payment_history.append(identifier)
        return False
    
    def send_notification(self, payment_info):
        """发送支付通知到bpay"""
        try:
            # 获取支付网关并拼接完整API地址（兼容旧配置notification_url）
            payment_gateway = self.config.get("payment_gateway", "") or self.config.get("notification_url", "")
            
            if not payment_gateway:
                print("未配置支付网关，跳过通知")
                return False
            
            notification_url = payment_gateway + "api/balance_notify.php"
            
            # 构建通知数据
            data = {
                "change_amount": str(payment_info.get("amount", "")),
                "change_time": payment_info.get("payment_time", ""),
                "payment_type": payment_info.get("payment_type", "")
            }
            
            # 生成签名
            sign = self._generate_md5_sign(data)
            data["sign"] = sign
            
            # 发送通知（verify=False允许抓包调试）
            response = requests.post(notification_url, json=data, timeout=10, verify=False)
            return response.status_code == 200
        except Exception as e:
            print(f"发送通知失败: {str(e)}")
            return False
    
    def create_widgets(self):
        main_frame = ttk.Frame(self.root, padding="10")
        main_frame.grid(row=0, column=0, sticky=(tk.W, tk.E, tk.N, tk.S))
        
        self.root.columnconfigure(0, weight=1)
        self.root.rowconfigure(0, weight=1)
        main_frame.columnconfigure(0, weight=1)
        main_frame.rowconfigure(1, weight=1)
        
        # 创建主控制页面（顶部）
        self.create_main_control_panel(main_frame)
        
        # 创建主Tab栏（底部）
        self.notebook = ttk.Notebook(main_frame)
        self.notebook.grid(row=1, column=0, sticky="nsew", pady=(10, 0))
        
        self.create_wechat_main_tab()
        self.create_alipay_main_tab()
        self.create_log_tab()
        
        self.status_var = tk.StringVar(value="就绪")
        self.status_bar = ttk.Label(main_frame, textvariable=self.status_var, relief="sunken", anchor="w")
        self.status_bar.grid(row=2, column=0, padx=5, pady=5, sticky="ew")
        
        self.on_ocr_method_change()
    
    def create_main_control_panel(self, parent):
        """创建主控制面板，包含全局配置和统一控制按钮"""
        control_frame = ttk.LabelFrame(parent, text="全局配置与控制", padding="10")
        control_frame.grid(row=0, column=0, sticky="ew")
        control_frame.columnconfigure(1, weight=1)
        
        # 分为两个区域：通知设置和统一控制
        left_frame = ttk.Frame(control_frame)
        left_frame.grid(row=0, column=0, sticky="n")
        
        right_frame = ttk.Frame(control_frame)
        right_frame.grid(row=0, column=1, sticky="n", padx=(20, 0))
        
        # 左侧：通知设置
        notify_frame = ttk.LabelFrame(left_frame, text="通知设置", padding="5")
        notify_frame.pack(fill="x", pady=(0, 10))
        
        ttk.Label(notify_frame, text="通知URL:").grid(row=0, column=0, padx=5, pady=2, sticky="w")
        self.notification_url_entry = ttk.Entry(notify_frame, width=40)
        self.notification_url_entry.insert(0, self.config.get("notification_url", ""))
        self.notification_url_entry.grid(row=0, column=1, padx=5, pady=2, sticky="ew")
        
        # 右侧：统一控制按钮
        btn_frame = ttk.LabelFrame(right_frame, text="统一控制", padding="10")
        btn_frame.pack(fill="x")
        
        # 状态显示
        self.wechat_monitor_status = tk.StringVar(value="微信: 未启动")
        self.alipay_monitor_status = tk.StringVar(value="支付宝: 未启动")
        
        ttk.Label(btn_frame, textvariable=self.wechat_monitor_status, font=("Arial", 10)).grid(row=0, column=0, columnspan=2, sticky="w", pady=2)
        ttk.Label(btn_frame, textvariable=self.alipay_monitor_status, font=("Arial", 10)).grid(row=1, column=0, columnspan=2, sticky="w", pady=2)
        
        ttk.Separator(btn_frame, orient="horizontal").grid(row=2, column=0, columnspan=2, sticky="ew", pady=10)
        
        self.start_all_btn = ttk.Button(btn_frame, text="全部开始监听", command=self.start_all_monitors, width=20)
        self.start_all_btn.grid(row=3, column=0, padx=5, pady=5)
        
        self.stop_all_btn = ttk.Button(btn_frame, text="全部停止监听", command=self.stop_all_monitors, width=20, state="disabled")
        self.stop_all_btn.grid(row=3, column=1, padx=5, pady=5)
        
        ttk.Separator(btn_frame, orient="horizontal").grid(row=4, column=0, columnspan=2, sticky="ew", pady=10)
        
        self.save_config_btn = ttk.Button(btn_frame, text="保存配置", command=self.on_save_config, width=15)
        self.save_config_btn.grid(row=5, column=0, padx=5, pady=5)
        
        self.clear_history_btn = ttk.Button(btn_frame, text="清空记录", command=self.clear_history, width=15)
        self.clear_history_btn.grid(row=5, column=1, padx=5, pady=5)
        
        ttk.Separator(btn_frame, orient="horizontal").grid(row=6, column=0, columnspan=2, sticky="ew", pady=10)
        
        self.export_config_btn = ttk.Button(btn_frame, text="导出服务端配置", command=self.export_server_config, width=20)
        self.export_config_btn.grid(row=7, column=0, columnspan=2, padx=5, pady=5)
    
    def start_all_monitors(self):
        """同时启动微信和支付宝监听"""
        # 启动微信定时检测
        if not self.timer_running:
            self.start_timer()
            self.wechat_monitor_status.set("微信: 监听中...")
        
        # 启动支付宝监听 - 支持两种模式：Cookie方式和应用支付方式
        alipay_started = False
        
        # 首先尝试应用支付方式（推荐方式）
        if not hasattr(self, 'alipay_app_monitor_running') or not self.alipay_app_monitor_running:
            app_id = self.config.get("alipay_app_id", "")
            private_key = self.config.get("alipay_private_key", "")
            public_key = self.config.get("alipay_public_key", "")
            
            if app_id and private_key and public_key:
                try:
                    interval = float(self.config.get("alipay_app_interval", "5"))
                    self.alipay_app_monitor_running = True
                    
                    # 更新UI状态
                    if hasattr(self, 'alipay_app_start_btn'):
                        self.alipay_app_start_btn.config(state="disabled")
                    if hasattr(self, 'alipay_app_stop_btn'):
                        self.alipay_app_stop_btn.config(state="normal")
                    if hasattr(self, 'alipay_app_status_var'):
                        self.alipay_app_status_var.set("监听中...")
                    
                    # 启动监听线程
                    self.alipay_app_monitor_thread = threading.Thread(
                        target=self.alipay_app_monitor_loop,
                        args=(app_id, private_key, public_key, interval),
                        daemon=True
                    )
                    self.alipay_app_monitor_thread.start()
                    
                    if hasattr(self, 'log_alipay_app'):
                        self.log_alipay_app("支付宝应用支付监听已启动（统一控制）")
                    
                    self.alipay_monitor_status.set("支付宝: 监听中...")
                    alipay_started = True
                    print("支付宝应用支付监听已通过统一控制启动")
                except Exception as e:
                    print(f"启动支付宝应用支付监听失败: {e}")
        
        # 如果应用支付方式未启动，尝试Cookie方式
        if not alipay_started and (not hasattr(self, 'alipay_monitor_running') or not self.alipay_monitor_running):
            cookie = self.config.get("alipay_cookie", "")
            if cookie:
                try:
                    interval = float(self.config.get("alipay_interval", "5"))
                    params = self.extract_alipay_params_from_cookie(cookie)
                    ctoken = params.get('ctoken')
                    user_id = params.get('user_id')
                    
                    if ctoken and user_id:
                        self.alipay_monitor_running = True
                        
                        # 更新UI状态
                        if hasattr(self, 'alipay_start_btn'):
                            self.alipay_start_btn.config(state="disabled")
                        if hasattr(self, 'alipay_stop_btn'):
                            self.alipay_stop_btn.config(state="normal")
                        if hasattr(self, 'alipay_status_var'):
                            self.alipay_status_var.set("监听中...")
                        
                        # 启动监听线程
                        self.alipay_monitor_thread = threading.Thread(
                            target=self.alipay_monitor_loop,
                            args=(cookie, ctoken, user_id, interval),
                            daemon=True
                        )
                        self.alipay_monitor_thread.start()
                        
                        if hasattr(self, 'log_alipay'):
                            self.log_alipay("支付宝订单监听已启动（统一控制）")
                        
                        self.alipay_monitor_status.set("支付宝: 监听中...")
                        alipay_started = True
                        print("支付宝Cookie监听已通过统一控制启动")
                except Exception as e:
                    print(f"启动支付宝Cookie监听失败: {e}")
        
        if not alipay_started:
            print("警告: 支付宝监听未能启动，请检查配置")
        
        self.start_all_btn.config(state="disabled")
        self.stop_all_btn.config(state="normal")
    
    def stop_all_monitors(self):
        """同时停止微信和支付宝监听"""
        # 停止微信定时检测
        if self.timer_running:
            self.stop_timer()
            self.wechat_monitor_status.set("微信: 已停止")
        
        # 停止支付宝Cookie监听
        if hasattr(self, 'alipay_monitor_running') and self.alipay_monitor_running:
            self.stop_alipay_monitor()
        
        # 停止支付宝应用支付监听
        if hasattr(self, 'alipay_app_monitor_running') and self.alipay_app_monitor_running:
            self.stop_alipay_app_monitor()
        
        # 如果两种支付宝监听都停止了，更新状态
        if (not hasattr(self, 'alipay_monitor_running') or not self.alipay_monitor_running) and \
           (not hasattr(self, 'alipay_app_monitor_running') or not self.alipay_app_monitor_running):
            self.alipay_monitor_status.set("支付宝: 已停止")
        
        self.start_all_btn.config(state="normal")
        self.stop_all_btn.config(state="disabled")
    
    def create_wechat_main_tab(self):
        """创建微信主Tab，包含OCR设置、微信监听和微信配置"""
        wechat_main_frame = ttk.Frame(self.notebook, padding="10")
        self.notebook.add(wechat_main_frame, text="微信")
        
        # 在微信Tab内部创建子Tab
        wechat_notebook = ttk.Notebook(wechat_main_frame)
        wechat_notebook.pack(fill="both", expand=True)
        
        # 1. OCR设置Tab
        self.create_wechat_ocr_tab(wechat_notebook)
        
        # 2. 微信监听Tab
        self.create_wechat_monitor_tab(wechat_notebook)
        
        # 3. 微信配置Tab
        self.create_wechat_settings_tab(wechat_notebook)
    
    def create_wechat_ocr_tab(self, parent):
        """创建微信OCR设置Tab"""
        ocr_frame = ttk.Frame(parent, padding="10")
        parent.add(ocr_frame, text="OCR设置")
        
        ocr_frame.columnconfigure(0, weight=1)
        ocr_frame.rowconfigure(0, weight=1)
        
        canvas = tk.Canvas(ocr_frame)
        scrollbar = ttk.Scrollbar(ocr_frame, orient="vertical", command=canvas.yview)
        scrollable_frame = ttk.Frame(canvas)
        
        scrollable_frame.bind(
            "<Configure>",
            lambda e: canvas.configure(scrollregion=canvas.bbox("all"))
        )
        
        canvas.create_window((0, 0), window=scrollable_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)
        
        canvas.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")
        
        scrollable_frame.columnconfigure(1, weight=1)
        
        ocr_setup_frame = ttk.LabelFrame(scrollable_frame, text="OCR设置", padding="5")
        ocr_setup_frame.grid(row=0, column=0, padx=5, pady=5, sticky="ew")
        ocr_setup_frame.columnconfigure(1, weight=1)
        
        ttk.Label(ocr_setup_frame, text="OCR方法:").grid(row=0, column=0, padx=5, pady=2, sticky="w")
        self.ocr_method_var = tk.StringVar(value=self.config.get("ocr_method", "百度OCR"))
        ocr_method_combo = ttk.Combobox(ocr_setup_frame, textvariable=self.ocr_method_var, 
                                      values=["百度OCR", "自定义API", "Umi-OCR"], width=15)
        ocr_method_combo.grid(row=0, column=1, padx=5, pady=2, sticky="w")
        ocr_method_combo.bind("<<ComboboxSelected>>", self.on_ocr_method_change)
        
        ttk.Label(ocr_setup_frame, text="API Key:").grid(row=1, column=0, padx=5, pady=2, sticky="w")
        self.baidu_key_entry = ttk.Entry(ocr_setup_frame, width=40)
        self.baidu_key_entry.insert(0, self.config.get("baidu_api_key", BAIDU_API_KEY))
        self.baidu_key_entry.grid(row=1, column=1, padx=5, pady=2, sticky="ew")
        
        ttk.Label(ocr_setup_frame, text="Secret Key:").grid(row=2, column=0, padx=5, pady=2, sticky="w")
        self.baidu_secret_entry = ttk.Entry(ocr_setup_frame, width=40)
        self.baidu_secret_entry.insert(0, self.config.get("baidu_secret_key", BAIDU_SECRET_KEY))
        self.baidu_secret_entry.grid(row=2, column=1, padx=5, pady=2, sticky="ew")
        
        self.custom_api_label = ttk.Label(ocr_setup_frame, text="API URL:")
        self.custom_api_label.grid(row=1, column=0, padx=5, pady=2, sticky="w")
        self.custom_api_entry = ttk.Entry(ocr_setup_frame, width=40)
        self.custom_api_entry.insert(0, self.config.get("custom_api_url", ""))
        self.custom_api_entry.grid(row=1, column=1, padx=5, pady=2, sticky="ew")
        
        self.umi_api_label = ttk.Label(ocr_setup_frame, text="API URL:")
        self.umi_api_label.grid(row=1, column=0, padx=5, pady=2, sticky="w")
        self.umi_api_entry = ttk.Entry(ocr_setup_frame, width=40)
        self.umi_api_entry.insert(0, self.config.get("umi_api_url", "http://127.0.0.1:1224/api/ocr"))
        self.umi_api_entry.grid(row=1, column=1, padx=5, pady=2, sticky="ew")
        
        ttk.Label(ocr_setup_frame, text="检测间隔(秒):").grid(row=3, column=0, padx=5, pady=2, sticky="w")
        self.timer_interval_var = tk.StringVar(value=self.config.get("timer_interval", "10.0"))
        self.timer_interval_entry = ttk.Entry(ocr_setup_frame, textvariable=self.timer_interval_var, width=10)
        self.timer_interval_entry.grid(row=3, column=1, padx=5, pady=2, sticky="w")
    
    def create_wechat_monitor_tab(self, parent):
        """创建微信监听Tab"""
        wechat_frame = ttk.Frame(parent, padding="10")
        parent.add(wechat_frame, text="微信监听")
        
        wechat_frame.columnconfigure(0, weight=1)
        wechat_frame.rowconfigure(0, weight=1)
        
        canvas = tk.Canvas(wechat_frame)
        scrollbar = ttk.Scrollbar(wechat_frame, orient="vertical", command=canvas.yview)
        scrollable_frame = ttk.Frame(canvas)
        
        scrollable_frame.bind(
            "<Configure>",
            lambda e: canvas.configure(scrollregion=canvas.bbox("all"))
        )
        
        canvas.create_window((0, 0), window=scrollable_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)
        
        canvas.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")
        
        scrollable_frame.columnconfigure(0, weight=1)
        
        type_frame = ttk.LabelFrame(scrollable_frame, text="支付类型", padding="5")
        type_frame.grid(row=0, column=0, padx=5, pady=5, sticky="ew")
        type_frame.columnconfigure(1, weight=1)
        
        self.wechat_type_var = tk.StringVar(value=self.config.get("wechat_type", "收款助手"))
        ttk.Radiobutton(type_frame, text="收款码 (微信收款助手)", variable=self.wechat_type_var, 
                       value="收款助手").grid(row=0, column=0, padx=10, pady=5, sticky="w")
        ttk.Radiobutton(type_frame, text="赞赏码 (微信支付)", variable=self.wechat_type_var, 
                       value="赞赏码").grid(row=0, column=1, padx=10, pady=5, sticky="w")
        
        self.wechat_type_var.trace_add('write', lambda *args: [setattr(self, 'config_guide_shown', False), self.auto_select_payment_window()])
        
        window_frame = ttk.LabelFrame(scrollable_frame, text="窗口选择", padding="5")
        window_frame.grid(row=1, column=0, padx=5, pady=5, sticky="ew")
        window_frame.columnconfigure(1, weight=1)
        
        ttk.Label(window_frame, text="支付窗口:").grid(row=0, column=0, padx=5, pady=2, sticky="w")
        self.window_var = tk.StringVar(value=self.config.get("selected_window", ""))
        self.window_combo = ttk.Combobox(window_frame, textvariable=self.window_var, width=40)
        self.window_combo['values'] = self.windows
        self.window_combo.grid(row=0, column=1, padx=5, pady=2, sticky="ew")
        
        self.refresh_btn = ttk.Button(window_frame, text="刷新窗口", command=self.refresh_windows_list, width=10)
        self.refresh_btn.grid(row=0, column=2, padx=5, pady=2, sticky="w")
        
        preview_frame = ttk.LabelFrame(scrollable_frame, text="截图预览", padding="5")
        preview_frame.grid(row=2, column=0, padx=5, pady=5, sticky="nsew")
        preview_frame.columnconfigure(0, weight=1)
        preview_frame.rowconfigure(0, weight=1)
        
        self.image_label = ttk.Label(preview_frame, text="截图将显示在这里", background="#e0e0e0", 
                                    anchor="center", relief="sunken")
        self.image_label.grid(row=0, column=0, padx=5, pady=5, sticky="nsew")
        
        btn_frame = ttk.Frame(scrollable_frame)
        btn_frame.grid(row=3, column=0, padx=5, pady=10, sticky="ew")
        
        self.start_timer_btn = ttk.Button(btn_frame, text="开始定时检测", command=self.start_timer)
        self.start_timer_btn.pack(side=tk.LEFT, padx=5)
        
        self.stop_timer_btn = ttk.Button(btn_frame, text="停止定时检测", command=self.stop_timer, state="disabled")
        self.stop_timer_btn.pack(side=tk.LEFT, padx=5)
        
        self.capture_btn = ttk.Button(btn_frame, text="单次识别", command=self.start_capture_process)
        self.capture_btn.pack(side=tk.LEFT, padx=5)
    
    def create_wechat_settings_tab(self, parent):
        """创建微信配置Tab"""
        wechat_ctrl_frame = ttk.Frame(parent, padding="10")
        parent.add(wechat_ctrl_frame, text="微信配置")
        
        wechat_ctrl_frame.columnconfigure(0, weight=1)
        wechat_ctrl_frame.rowconfigure(0, weight=1)
        
        canvas = tk.Canvas(wechat_ctrl_frame)
        scrollbar = ttk.Scrollbar(wechat_ctrl_frame, orient="vertical", command=canvas.yview)
        scrollable_frame = ttk.Frame(canvas)
        
        scrollable_frame.bind(
            "<Configure>",
            lambda e: canvas.configure(scrollregion=canvas.bbox("all"))
        )
        
        canvas.create_window((0, 0), window=scrollable_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)
        
        canvas.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")
        
        scrollable_frame.columnconfigure(0, weight=1)
        scrollable_frame.rowconfigure(2, weight=1)
        
        status_frame = ttk.LabelFrame(scrollable_frame, text="微信状态", padding="5")
        status_frame.grid(row=0, column=0, padx=5, pady=5, sticky="ew")
        status_frame.columnconfigure(1, weight=1)
        
        self.wechat_status_var = tk.StringVar(value="未启动")
        ttk.Label(status_frame, text="状态:").grid(row=0, column=0, padx=5, pady=5, sticky="w")
        ttk.Label(status_frame, textvariable=self.wechat_status_var, font=("Arial", 10, "bold")).grid(row=0, column=1, padx=5, pady=5, sticky="w")
        
        self.auto_detect_var = tk.StringVar(value="自动检测: 运行中")
        ttk.Label(status_frame, textvariable=self.auto_detect_var, foreground="green").grid(row=0, column=2, padx=5, pady=5, sticky="e")
        
        path_frame = ttk.Frame(status_frame)
        path_frame.grid(row=1, column=0, columnspan=2, padx=5, pady=5, sticky="ew")
        path_frame.columnconfigure(1, weight=1)
        
        ttk.Label(path_frame, text="微信路径:").grid(row=0, column=0, padx=5, pady=5, sticky="w")
        self.wechat_path_var = tk.StringVar()
        self.wechat_path_entry = ttk.Entry(path_frame, textvariable=self.wechat_path_var, width=50)
        self.wechat_path_entry.grid(row=0, column=1, padx=5, pady=5, sticky="ew")
        
        self.browse_btn = ttk.Button(path_frame, text="浏览...", command=self.browse_wechat_path)
        self.browse_btn.grid(row=0, column=2, padx=5, pady=5)
        
        btn_frame = ttk.LabelFrame(scrollable_frame, text="操作按钮", padding="5")
        btn_frame.grid(row=1, column=0, padx=5, pady=5, sticky="ew")
        
        self.start_wechat_btn = ttk.Button(btn_frame, text="【启动并登录】", command=self.start_and_login_wechat, width=15)
        self.start_wechat_btn.pack(side=tk.LEFT, padx=10, pady=5)
        
        self.check_wechat_btn = ttk.Button(btn_frame, text="【检测状态】", command=self.check_wechat_status, width=15)
        self.check_wechat_btn.pack(side=tk.LEFT, padx=10, pady=5)
        
        self.close_wechat_btn = ttk.Button(btn_frame, text="【关闭微信】", command=self.close_wechat, width=15)
        self.close_wechat_btn.pack(side=tk.LEFT, padx=10, pady=5)
        
        login_frame = ttk.LabelFrame(scrollable_frame, text="登录选项", padding="5")
        login_frame.grid(row=2, column=0, padx=5, pady=5, sticky="ew")
        
        self.login_method_var = tk.StringVar(value="qrcode")
        ttk.Radiobutton(login_frame, text="二维码登录", variable=self.login_method_var, value="qrcode").pack(side=tk.LEFT, padx=10)
        
        qrcode_frame = ttk.LabelFrame(scrollable_frame, text="登录二维码", padding="5")
        qrcode_frame.grid(row=3, column=0, padx=5, pady=5, sticky="nsew")
        qrcode_frame.columnconfigure(0, weight=1)
        qrcode_frame.rowconfigure(0, weight=1)
        
        self.qrcode_label = ttk.Label(qrcode_frame, text="二维码将显示在这里", background="#e0e0e0", 
                                     anchor="center", relief="sunken")
        self.qrcode_label.grid(row=0, column=0, padx=5, pady=5, sticky="nsew")
        
        service_frame = ttk.LabelFrame(scrollable_frame, text="服务号操作", padding="5")
        service_frame.grid(row=4, column=0, padx=5, pady=5, sticky="ew")
        service_frame.columnconfigure(1, weight=1)
        
        ttk.Label(service_frame, text="服务号:").grid(row=0, column=0, padx=5, pady=5, sticky="w")
        self.service_account_var = tk.StringVar(value="微信收款助手")
        service_combo = ttk.Combobox(service_frame, textvariable=self.service_account_var, 
                                    values=["微信收款助手", "微信支付"], width=20)
        service_combo.grid(row=0, column=1, padx=5, pady=5, sticky="w")
        
        self.open_service_btn = ttk.Button(service_frame, text="自动打开服务号", command=self.open_service_account)
        self.open_service_btn.grid(row=0, column=2, padx=5, pady=5)
    
    def create_alipay_main_tab(self):
        """创建支付宝主Tab"""
        alipay_main_frame = ttk.Frame(self.notebook, padding="10")
        self.notebook.add(alipay_main_frame, text="支付宝")
        
        # 在支付宝Tab内部创建子Tab
        alipay_notebook = ttk.Notebook(alipay_main_frame)
        alipay_notebook.pack(fill="both", expand=True)
        
        # 支付宝Cookie监听Tab（网页版）
        self.create_alipay_monitor_tab(alipay_notebook)
        
        # 支付宝应用支付Tab（商家账单API）
        self.create_alipay_app_tab(alipay_notebook)
    
    def create_alipay_monitor_tab(self, parent):
        """创建支付宝Cookie监听Tab（网页版）"""
        alipay_frame = ttk.Frame(parent, padding="10")
        parent.add(alipay_frame, text="Cookie监听(网页版)")
        
        alipay_frame.columnconfigure(0, weight=1)
        alipay_frame.rowconfigure(0, weight=1)
        
        canvas = tk.Canvas(alipay_frame)
        scrollbar = ttk.Scrollbar(alipay_frame, orient="vertical", command=canvas.yview)
        scrollable_frame = ttk.Frame(canvas)
        
        scrollable_frame.bind(
            "<Configure>",
            lambda e: canvas.configure(scrollregion=canvas.bbox("all"))
        )
        
        canvas.create_window((0, 0), window=scrollable_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)
        
        canvas.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")
        
        scrollable_frame.columnconfigure(0, weight=1)
        
        # Cookie配置区域
        cookie_frame = ttk.LabelFrame(scrollable_frame, text="Cookie配置", padding="5")
        cookie_frame.grid(row=0, column=0, columnspan=2, padx=5, pady=5, sticky="ew")
        cookie_frame.columnconfigure(1, weight=1)
        
        ttk.Label(cookie_frame, text="支付宝Cookie:").grid(row=0, column=0, padx=5, pady=2, sticky="nw")
        self.alipay_cookie_text = tk.Text(cookie_frame, height=5, width=50)
        self.alipay_cookie_text.grid(row=0, column=1, padx=5, pady=2, sticky="ew")
        self.alipay_cookie_text.insert("1.0", self.config.get("alipay_cookie", ""))
        self.alipay_cookie_text.bind("&lt;KeyRelease&gt;", self.on_alipay_cookie_change)
        self.alipay_cookie_text.bind("&lt;FocusOut&gt;", self.on_alipay_cookie_change)
        
        ttk.Label(cookie_frame, text="ctoken:").grid(row=1, column=0, padx=5, pady=2, sticky="w")
        self.alipay_ctoken_entry = ttk.Entry(cookie_frame, width=40)
        self.alipay_ctoken_entry.insert(0, self.config.get("alipay_ctoken", ""))
        self.alipay_ctoken_entry.grid(row=1, column=1, padx=5, pady=2, sticky="ew")
        
        ttk.Label(cookie_frame, text="billUserId:").grid(row=2, column=0, padx=5, pady=2, sticky="w")
        self.alipay_userid_entry = ttk.Entry(cookie_frame, width=40)
        self.alipay_userid_entry.insert(0, self.config.get("alipay_userid", ""))
        self.alipay_userid_entry.grid(row=2, column=1, padx=5, pady=2, sticky="ew")
        
        # 操作按钮
        btn_frame = ttk.Frame(scrollable_frame)
        btn_frame.grid(row=1, column=0, columnspan=2, padx=5, pady=5, sticky="ew")
        
        self.alipay_save_btn = ttk.Button(btn_frame, text="保存配置", command=self.save_alipay_config)
        self.alipay_save_btn.pack(side=tk.LEFT, padx=2, pady=2)
        
        self.alipay_test_btn = ttk.Button(btn_frame, text="测试连接", command=self.test_alipay_connection)
        self.alipay_test_btn.pack(side=tk.LEFT, padx=2, pady=2)
        
        self.alipay_webview_btn = ttk.Button(btn_frame, text="打开支付宝网页", command=self.open_alipay_webview)
        self.alipay_webview_btn.pack(side=tk.LEFT, padx=2, pady=2)
        
        # 监听控制区域
        control_frame = ttk.LabelFrame(scrollable_frame, text="监听控制", padding="5")
        control_frame.grid(row=2, column=0, columnspan=2, padx=5, pady=5, sticky="nsew")
        control_frame.columnconfigure(0, weight=1)
        control_frame.rowconfigure(1, weight=1)
        
        # 状态和控制按钮
        status_control_frame = ttk.Frame(control_frame)
        status_control_frame.grid(row=0, column=0, padx=5, pady=5, sticky="ew")
        
        ttk.Label(status_control_frame, text="监听状态:").pack(side=tk.LEFT, padx=5)
        self.alipay_status_var = tk.StringVar(value="未启动")
        ttk.Label(status_control_frame, textvariable=self.alipay_status_var, 
                 font=("Arial", 10, "bold")).pack(side=tk.LEFT, padx=5)
        
        self.alipay_start_btn = ttk.Button(status_control_frame, text="开始监听", 
                                          command=self.start_alipay_monitor)
        self.alipay_start_btn.pack(side=tk.LEFT, padx=10)
        
        self.alipay_stop_btn = ttk.Button(status_control_frame, text="停止监听", 
                                         command=self.stop_alipay_monitor, state="disabled")
        self.alipay_stop_btn.pack(side=tk.LEFT, padx=10)
        
        # 监听间隔设置
        ttk.Label(status_control_frame, text="监听间隔(秒):").pack(side=tk.LEFT, padx=(20, 5))
        self.alipay_interval_var = tk.StringVar(value=self.config.get("alipay_interval", "5"))
        ttk.Entry(status_control_frame, textvariable=self.alipay_interval_var, width=8).pack(side=tk.LEFT)
        
        # 通知设置提示
        notify_info_frame = ttk.Frame(control_frame)
        notify_info_frame.grid(row=2, column=0, padx=5, pady=5, sticky="ew")
        ttk.Label(notify_info_frame, text="通知URL: 使用主页面中的通知URL", foreground="gray").pack(side=tk.LEFT)
        
        # 余额变动记录显示区域
        record_frame = ttk.LabelFrame(control_frame, text="余额变动记录", padding="5")
        record_frame.grid(row=1, column=0, padx=5, pady=5, sticky="nsew")
        record_frame.columnconfigure(0, weight=1)
        record_frame.rowconfigure(0, weight=1)
        
        self.alipay_log_text = tk.Text(record_frame, height=15, state="disabled")
        self.alipay_log_text.grid(row=0, column=0, padx=5, pady=5, sticky="nsew")
        
        alipay_scrollbar = ttk.Scrollbar(record_frame, command=self.alipay_log_text.yview)
        alipay_scrollbar.grid(row=0, column=1, sticky="ns")
        self.alipay_log_text.config(yscrollcommand=alipay_scrollbar.set)
    
    def create_alipay_app_tab(self, parent):
        """创建支付宝应用支付Tab（商家余额API）"""
        app_frame = ttk.Frame(parent, padding="10")
        parent.add(app_frame, text="商家余额(API)")
        
        app_frame.columnconfigure(0, weight=1)
        app_frame.rowconfigure(0, weight=1)
        
        canvas = tk.Canvas(app_frame)
        scrollbar = ttk.Scrollbar(app_frame, orient="vertical", command=canvas.yview)
        scrollable_frame = ttk.Frame(canvas)
        
        scrollable_frame.bind(
            "<Configure>",
            lambda e: canvas.configure(scrollregion=canvas.bbox("all"))
        )
        
        canvas.create_window((0, 0), window=scrollable_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)
        
        canvas.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")
        
        scrollable_frame.columnconfigure(0, weight=1)
        
        # 说明区域
        info_frame = ttk.LabelFrame(scrollable_frame, text="余额监控说明", padding="5")
        info_frame.grid(row=0, column=0, padx=5, pady=5, sticky="ew")

        info_text = """使用支付宝开放平台接口查询商家余额，通过余额变动检测收款并对接易支付系统

1. 登录支付宝开放平台(open.alipay.com)
2. 创建应用并开通"余额查询"接口权限
3. 获取应用ID、应用私钥和支付宝公钥
4. 填写以下配置信息

原理：定时查询账户余额，对比当前余额与上次余额，余额增加即判定为收款"""
        
        ttk.Label(info_frame, text=info_text, justify=tk.LEFT, foreground="blue").pack(padx=5, pady=5)
        
        # 配置区域容器
        config_container = ttk.Frame(scrollable_frame)
        config_container.grid(row=1, column=0, padx=5, pady=5, sticky="ew")
        config_container.columnconfigure(0, weight=1)
        config_container.columnconfigure(1, weight=1)
        
        # 支付网关配置 Frame
        gateway_frame = ttk.LabelFrame(config_container, text="支付网关配置", padding="10")
        gateway_frame.grid(row=0, column=0, padx=(0, 5), pady=5, sticky="nsew")
        gateway_frame.columnconfigure(1, weight=1)

        ttk.Label(gateway_frame, text="支付网关:").grid(row=0, column=0, padx=5, pady=5, sticky="w")
        self.payment_gateway_entry = ttk.Entry(gateway_frame, width=30)
        self.payment_gateway_entry.insert(0, self.config.get("payment_gateway", ""))
        self.payment_gateway_entry.grid(row=0, column=1, padx=5, pady=5, sticky="ew")
        ttk.Label(gateway_frame, text="只需填写域名，如: https://pay.example.com/", foreground="gray", font=("Arial", 8)).grid(row=1, column=1, padx=5, pady=0, sticky="w")

        ttk.Label(gateway_frame, text="收款码URL:").grid(row=2, column=0, padx=5, pady=5, sticky="w")
        self.qr_code_url_entry = ttk.Entry(gateway_frame, width=30)
        self.qr_code_url_entry.insert(0, self.config.get("qr_code_url", ""))
        self.qr_code_url_entry.grid(row=2, column=1, padx=5, pady=5, sticky="ew")
        ttk.Label(gateway_frame, text="支付宝收款码图片链接", foreground="gray", font=("Arial", 8)).grid(row=3, column=1, padx=5, pady=0, sticky="w")
        
        # 系统配置 Frame（支付宝开放平台配置）
        system_frame = ttk.LabelFrame(config_container, text="余额监控配置（支付宝开放平台）", padding="10")
        system_frame.grid(row=0, column=1, padx=(5, 0), pady=5, sticky="nsew")
        system_frame.columnconfigure(1, weight=1)

        ttk.Label(system_frame, text="应用ID(AppId):").grid(row=0, column=0, padx=5, pady=5, sticky="w")
        self.alipay_app_id_entry = ttk.Entry(system_frame, width=30)
        self.alipay_app_id_entry.insert(0, self.config.get("alipay_app_id", ""))
        self.alipay_app_id_entry.grid(row=0, column=1, padx=5, pady=5, sticky="ew")
        ttk.Label(system_frame, text="支付宝开放平台的应用ID（用于查询账户余额）", foreground="gray", font=("Arial", 8)).grid(row=1, column=1, padx=5, pady=0, sticky="w")
        
        # 操作按钮
        btn_frame = ttk.Frame(scrollable_frame)
        btn_frame.grid(row=2, column=0, padx=5, pady=5, sticky="ew")
        
        self.alipay_app_save_btn = ttk.Button(btn_frame, text="保存配置", command=self.save_alipay_app_config)
        self.alipay_app_save_btn.pack(side=tk.LEFT, padx=5)
        
        self.alipay_app_test_btn = ttk.Button(btn_frame, text="测试连接", command=self.test_alipay_app_connection)
        self.alipay_app_test_btn.pack(side=tk.LEFT, padx=5)
        
        # 监听控制区域
        control_frame = ttk.LabelFrame(scrollable_frame, text="监听控制", padding="5")
        control_frame.grid(row=3, column=0, padx=5, pady=5, sticky="nsew")
        control_frame.columnconfigure(0, weight=1)
        control_frame.rowconfigure(1, weight=1)
        
        # 状态和控制按钮
        status_control_frame = ttk.Frame(control_frame)
        status_control_frame.grid(row=0, column=0, padx=5, pady=5, sticky="ew")
        
        ttk.Label(status_control_frame, text="监听状态:").pack(side=tk.LEFT, padx=5)
        self.alipay_app_status_var = tk.StringVar(value="未启动")
        ttk.Label(status_control_frame, textvariable=self.alipay_app_status_var, 
                 font=("Arial", 10, "bold")).pack(side=tk.LEFT, padx=5)
        
        self.alipay_app_start_btn = ttk.Button(status_control_frame, text="开始监听", 
                                              command=self.start_alipay_app_monitor)
        self.alipay_app_start_btn.pack(side=tk.LEFT, padx=10)
        
        self.alipay_app_stop_btn = ttk.Button(status_control_frame, text="停止监听", 
                                             command=self.stop_alipay_app_monitor, state="disabled")
        self.alipay_app_stop_btn.pack(side=tk.LEFT, padx=10)
        
        # 监听间隔设置
        ttk.Label(status_control_frame, text="监听间隔(秒):").pack(side=tk.LEFT, padx=(20, 5))
        self.alipay_app_interval_var = tk.StringVar(value=self.config.get("alipay_app_interval", "5"))
        ttk.Entry(status_control_frame, textvariable=self.alipay_app_interval_var, width=8).pack(side=tk.LEFT)
        
        # 余额变动记录显示区域
        record_frame = ttk.LabelFrame(control_frame, text="余额变动记录", padding="5")
        record_frame.grid(row=1, column=0, padx=5, pady=5, sticky="nsew")
        record_frame.columnconfigure(0, weight=1)
        record_frame.rowconfigure(0, weight=1)

        self.alipay_app_log_text = tk.Text(record_frame, height=15, state="disabled")
        self.alipay_app_log_text.grid(row=0, column=0, padx=5, pady=5, sticky="nsew")
        
        alipay_app_scrollbar = ttk.Scrollbar(record_frame, command=self.alipay_app_log_text.yview)
        alipay_app_scrollbar.grid(row=0, column=1, sticky="ns")
        self.alipay_app_log_text.config(yscrollcommand=alipay_app_scrollbar.set)
    
    def save_alipay_app_config(self):
        """保存支付宝应用支付配置"""
        try:
            # 系统配置（支付宝开放平台）
            self.config["alipay_app_id"] = self.alipay_app_id_entry.get().strip()
            self.config["alipay_app_interval"] = self.alipay_app_interval_var.get()

            # 支付网关配置
            self.config["qr_code_url"] = self.qr_code_url_entry.get().strip()

            # 处理支付网关URL格式，确保以/结尾
            payment_gateway = self.payment_gateway_entry.get().strip()
            if payment_gateway and not payment_gateway.endswith("/"):
                payment_gateway += "/"
            self.config["payment_gateway"] = payment_gateway

            with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
                json.dump(self.config, f, ensure_ascii=False, indent=4)

            messagebox.showinfo("成功", "支付宝应用支付配置已保存")
        except Exception as e:
            messagebox.showerror("错误", f"保存配置失败: {str(e)}")
    
    def test_alipay_app_connection(self):
        """测试支付宝应用支付连接"""
        messagebox.showinfo("提示", "此功能需要配置RSA密钥，请通过其他方式配置后使用")
    
    def query_alipay_app_orders(self, app_id, private_key, public_key, page_num=1, page_size=20):
        """使用支付宝应用支付接口查询商家账单"""
        try:
            from datetime import datetime, timedelta
            import json
            import base64
            import hashlib
            
            # 构建请求参数
            biz_content = {
                "start_time": (datetime.now() - timedelta(days=1)).strftime("%Y-%m-%d %H:%M:%S"),
                "end_time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "page_no": str(page_num),
                "page_size": str(page_size)
            }
            
            # 构建公共参数
            common_params = {
                "app_id": app_id,
                "method": "alipay.data.bill.balance.query",
                "format": "JSON",
                "charset": "utf-8",
                "sign_type": "RSA2",
                "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "version": "1.0",
                "biz_content": json.dumps(biz_content)
            }
            
            # 生成签名
            sign_content = self._generate_sign_content(common_params)
            sign = self._rsa_sign(sign_content, private_key)
            common_params["sign"] = sign
            
            # 发送请求
            url = "https://openapi.alipay.com/gateway.do"
            response = requests.post(url, data=common_params, timeout=30)
            result = response.json()
            
            # 验证签名
            alipay_response = result.get("alipay_data_bill_balance_query_response", {})
            if alipay_response.get("code") == "10000":
                return {
                    "status": "success",
                    "data": alipay_response.get("detail_list", []),
                    "total": alipay_response.get("total_size", 0)
                }
            else:
                return {
                    "status": "error",
                    "message": alipay_response.get("msg", "请求失败")
                }
                
        except Exception as e:
            return {
                "status": "error",
                "message": str(e)
            }
    
    def _generate_sign_content(self, params):
        """生成待签名字符串"""
        # 过滤空值和sign字段，按key排序
        filtered_params = {k: v for k, v in params.items() if v and k != "sign"}
        sorted_params = sorted(filtered_params.items())
        # 构建待签名字符串
        sign_content = "&".join([f"{k}={v}" for k, v in sorted_params])
        return sign_content
    
    def _rsa_sign(self, content, private_key):
        """RSA签名"""
        try:
            from cryptography.hazmat.primitives import hashes, serialization
            from cryptography.hazmat.primitives.asymmetric import padding
            
            # 加载私钥
            private_key = private_key.replace("-----BEGIN RSA PRIVATE KEY-----", "").replace("-----END RSA PRIVATE KEY-----", "").replace("\n", "")
            private_key_bytes = base64.b64decode(private_key)
            
            # 使用cryptography库进行签名
            private_key_obj = serialization.load_der_private_key(private_key_bytes, password=None)
            signature = private_key_obj.sign(
                content.encode('utf-8'),
                padding.PKCS1v15(),
                hashes.SHA256()
            )
            return base64.b64encode(signature).decode('utf-8')
        except Exception as e:
            # 如果cryptography库不可用，尝试使用其他方式
            print(f"RSA签名失败: {e}")
            return ""
    
    def start_alipay_app_monitor(self):
        """开始支付宝应用支付监听"""
        messagebox.showinfo("提示", "此功能需要配置RSA密钥，请通过其他方式配置后使用")
    
    def stop_alipay_app_monitor(self):
        """停止支付宝应用支付监听"""
        self.alipay_app_monitor_running = False
        self.alipay_app_start_btn.config(state="normal")
        self.alipay_app_stop_btn.config(state="disabled")
        self.alipay_app_status_var.set("已停止")
        self.log_alipay_app("支付宝应用支付监听已停止")
        
        # 更新主控制页面状态
        if not hasattr(self, 'alipay_monitor_running') or not self.alipay_monitor_running:
            self.alipay_monitor_status.set("支付宝: 已停止")
    
    def alipay_app_monitor_loop(self, app_id, private_key, public_key, interval):
        """支付宝应用支付监听循环 - 余额变动监控模式"""
        last_balance = None
        last_check_time = None
        
        self.log_alipay_app("启动余额变动监控模式")
        
        while self.alipay_app_monitor_running:
            try:
                # 查询当前余额
                result = self.query_alipay_balance(app_id, private_key)
                
                if result and result.get("status") == "success":
                    current_balance = result.get("balance", 0)
                    current_time = datetime.now()
                    
                    self.log_alipay_app(f"当前余额: ¥{current_balance}")
                    
                    # 如果是第一次查询，记录余额
                    if last_balance is None:
                        last_balance = current_balance
                        last_check_time = current_time
                        self.log_alipay_app(f"初始化余额: ¥{current_balance}")
                    else:
                        # 计算余额变化
                        balance_diff = current_balance - last_balance
                        
                        # 如果余额增加（有收入）
                        if balance_diff > 0:
                            self.log_alipay_app(f"检测到余额增加: ¥{balance_diff}")
                            
                            # 上报到服务器
                            self.report_balance_change(balance_diff, current_balance, current_time)
                            
                            # 更新余额
                            last_balance = current_balance
                            last_check_time = current_time
                        # 如果余额减少（忽略，不处理支出）
                        elif balance_diff < 0:
                            self.log_alipay_app(f"检测到余额减少: ¥{balance_diff} (忽略)")
                            last_balance = current_balance
                            last_check_time = current_time
                        else:
                            # 余额无变化
                            pass
                else:
                    error_msg = result.get("message", "未知错误") if result else "请求失败"
                    self.log_alipay_app(f"查询余额失败: {error_msg}")
                
                # 等待下一次查询
                for _ in range(int(interval * 10)):
                    if not self.alipay_app_monitor_running:
                        break
                    time.sleep(0.1)
                    
            except Exception as e:
                self.log_alipay_app(f"监听异常: {str(e)}")
                time.sleep(interval)
    
    def query_alipay_balance(self, app_id, private_key):
        """查询支付宝账户余额"""
        try:
            import json
            import base64
            
            # 构建请求参数 - 使用余额查询接口
            biz_content = {}
            
            # 构建公共参数
            common_params = {
                "app_id": app_id,
                "method": "alipay.data.bill.balance.query",
                "format": "JSON",
                "charset": "utf-8",
                "sign_type": "RSA2",
                "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "version": "1.0",
                "biz_content": json.dumps(biz_content)
            }
            
            # 生成签名
            sign_content = self._generate_sign_content(common_params)
            sign = self._rsa_sign(sign_content, private_key)
            
            if not sign:
                return {
                    "status": "error",
                    "message": "签名生成失败"
                }
            
            common_params["sign"] = sign
            
            # 发送请求
            url = "https://openapi.alipay.com/gateway.do"
            response = requests.post(url, data=common_params, timeout=30)
            result = response.json()
            
            # 解析响应
            alipay_response = result.get("alipay_data_bill_balance_query_response", {})
            if alipay_response.get("code") == "10000":
                # 获取可用余额
                available_amount = alipay_response.get("available_amount", "0")
                total_amount = alipay_response.get("total_amount", "0")
                freeze_amount = alipay_response.get("freeze_amount", "0")
                
                # 转换为浮点数
                try:
                    balance = float(available_amount)
                except:
                    balance = 0
                
                return {
                    "status": "success",
                    "balance": balance,
                    "total_amount": total_amount,
                    "freeze_amount": freeze_amount,
                    "available_amount": available_amount
                }
            else:
                return {
                    "status": "error",
                    "message": alipay_response.get("msg", "请求失败"),
                    "sub_msg": alipay_response.get("sub_msg", "")
                }
                
        except Exception as e:
            return {
                "status": "error",
                "message": str(e)
            }
    
    def report_balance_change(self, change_amount, current_balance, change_time):
        """上报余额变动到服务器"""
        try:
            # 获取支付网关并拼接完整API地址（兼容旧配置notification_url）
            payment_gateway = self.config.get("payment_gateway", "") or self.config.get("notification_url", "")

            if not payment_gateway:
                self.log_alipay_app("未配置支付网关，跳过上报")
                return False

            notification_url = payment_gateway + "api/balance_notify.php"

            # 构建上报数据（简化格式）
            data = {
                "change_amount": str(change_amount),
                "current_balance": str(current_balance),
                "change_time": change_time.strftime("%Y-%m-%d %H:%M:%S")
            }

            # 生成签名：参数按ASCII排序拼接 + "qwer" 盐值，然后MD5
            sign = self._generate_md5_sign(data)
            data["sign"] = sign

            # 发送通知（verify=False允许抓包调试）
            response = requests.post(notification_url, json=data, timeout=10, verify=False)

            if response.status_code == 200:
                self.log_alipay_app(f"余额变动上报成功: +¥{change_amount}")
                return True
            else:
                self.log_alipay_app(f"余额变动上报失败: HTTP {response.status_code}")
                return False

        except Exception as e:
            self.log_alipay_app(f"余额变动上报异常: {str(e)}")
            return False

    def _generate_md5_sign(self, params):
        """生成MD5签名：参数按ASCII排序拼接 + "qwer" 盐值"""
        import hashlib

        # 过滤空值和sign字段，按key排序
        filtered_params = {k: v for k, v in params.items() if v is not None and k != "sign"}
        sorted_params = sorted(filtered_params.items())

        # 构建待签名字符串：key1=value1&key2=value2...
        sign_content = "&".join([f"{k}={v}" for k, v in sorted_params])

        # 添加盐值 "qwer"
        sign_content += "qwer"

        # MD5加密
        md5_hash = hashlib.md5(sign_content.encode('utf-8')).hexdigest()

        return md5_hash
    
    def process_alipay_app_orders(self, orders):
        """处理支付宝应用支付订单"""
        for order in orders:
            try:
                # 提取订单信息（应用支付接口返回的字段可能不同）
                trade_no = order.get("trade_no", "")
                amount = order.get("total_amount", "")
                status = order.get("trade_status", "")
                create_time = order.get("create_time", "")
                buyer = order.get("buyer_logon_id", "")
                subject = order.get("subject", "")
                
                # 只处理已完成的收款订单
                if status not in ["TRADE_SUCCESS", "TRADE_FINISHED"]:
                    continue
                
                # 检查是否重复
                if trade_no in self.alipay_order_history:
                    continue
                
                # 添加到历史记录
                self.alipay_order_history.append(trade_no)
                
                # 构建订单信息
                order_info = {
                    "payment_type": "alipay_app",
                    "amount": amount,
                    "payment_time": create_time,
                    "payer": buyer
                }
                
                # 记录订单
                self.log_alipay_app(f"检测到新订单: tradeNo={trade_no}, {order_info}")
                
                # 发送通知
                if self.send_notification(order_info):
                    self.log_alipay_app(f"订单通知发送成功: {trade_no}")
                else:
                    self.log_alipay_app(f"订单通知发送失败: {trade_no}")
                
            except Exception as e:
                self.log_alipay_app(f"处理订单异常: {str(e)}")
    
    def log_alipay_app(self, message):
        """记录支付宝应用支付日志"""
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_entry = f"[{timestamp}] {message}\n"
        
        self.alipay_app_log_text.config(state="normal")
        self.alipay_app_log_text.insert(tk.END, log_entry)
        self.alipay_app_log_text.see(tk.END)
        self.alipay_app_log_text.config(state="disabled")
        
        # 同时保存到按天创建的目录中
        try:
            daily_dir = get_daily_log_directory()
            log_file = os.path.join(daily_dir, "alipay_app_log.txt")
            with open(log_file, "a", encoding="utf-8") as f:
                f.write(log_entry)
        except Exception as e:
            print(f"保存支付宝应用支付日志失败: {e}")
    
    def open_alipay_webview(self):
        """打开支付宝网页版"""
        try:
            # 使用系统默认浏览器打开支付宝网页
            import webbrowser
            webbrowser.open('https://b.alipay.com/')
            
            self.log_alipay("已在浏览器中打开支付宝网页，请登录")
            messagebox.showinfo("提示", "请在浏览器中登录支付宝，然后复制Cookie到程序中\n\n登录后按F12 -> Console -> 输入 document.cookie 获取Cookie")
            
        except Exception as e:
            messagebox.showerror("错误", f"打开浏览器失败: {str(e)}")
    
    def save_alipay_config(self):
        """保存支付宝配置"""
        try:
            self.config["alipay_cookie"] = self.alipay_cookie_text.get("1.0", tk.END).strip()
            self.config["alipay_ctoken"] = self.alipay_ctoken_entry.get()
            self.config["alipay_userid"] = self.alipay_userid_entry.get()
            self.config["alipay_interval"] = self.alipay_interval_var.get()
            
            with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
                json.dump(self.config, f, ensure_ascii=False, indent=4)
            
            messagebox.showinfo("成功", "支付宝配置已保存")
        except Exception as e:
            messagebox.showerror("错误", f"保存配置失败: {str(e)}")
    
    def extract_alipay_params_from_cookie(self, cookie):
        """从cookie中提取所有支付宝请求参数"""
        params = {}
        
        # 提取ctoken
        params['ctoken'] = self.extract_from_cookie(cookie, 'ctoken')
        
        # 提取user_id (billUserId) - 尝试多个可能的字段
        user_id = None
        
        # 1. 尝试从CLUB_ALIPAY_COM提取
        user_id = self.extract_from_cookie(cookie, 'CLUB_ALIPAY_COM')
        
        # 2. 尝试从__TRACERT_COOKIE_bucUserId提取
        if not user_id:
            user_id = self.extract_from_cookie(cookie, '__TRACERT_COOKIE_bucUserId')
        
        # 3. 尝试从iw.userid提取并解码
        if not user_id:
            iw_userid = self.extract_from_cookie(cookie, 'iw.userid')
            if iw_userid:
                try:
                    import base64
                    decoded = base64.b64decode(iw_userid).decode('utf-8')
                    user_id = decoded
                except:
                    pass
        
        params['user_id'] = user_id
        
        return params
    
    def test_alipay_connection(self):
        """测试支付宝连接"""
        try:
            cookie = self.alipay_cookie_text.get("1.0", tk.END).strip()
            
            if not cookie:
                messagebox.showerror("错误", "请填写Cookie")
                return
            
            # 自动从Cookie提取所有参数
            params = self.extract_alipay_params_from_cookie(cookie)
            ctoken = params.get('ctoken')
            user_id = params.get('user_id')
            
            # 更新到输入框
            if ctoken:
                self.alipay_ctoken_entry.delete(0, tk.END)
                self.alipay_ctoken_entry.insert(0, ctoken)
            if user_id:
                self.alipay_userid_entry.delete(0, tk.END)
                self.alipay_userid_entry.insert(0, user_id)
            
            # 检查是否成功提取了参数
            if not ctoken or not user_id:
                missing = []
                if not ctoken:
                    missing.append("ctoken")
                if not user_id:
                    missing.append("billUserId")
                messagebox.showerror("错误", f"无法从Cookie中提取参数: {', '.join(missing)}")
                return
            
            result = self.query_alipay_orders(cookie, ctoken=ctoken, user_id=user_id, page_size=1)
            
            if result and result.get("status") == "success":
                messagebox.showinfo("成功", "连接测试成功！可以正常获取订单数据")
            else:
                error_msg = result.get("message", "未知错误") if result else "请求失败"
                messagebox.showerror("失败", f"连接测试失败: {error_msg}")
        except Exception as e:
            messagebox.showerror("错误", f"测试连接失败: {str(e)}")
    
    def on_alipay_cookie_change(self, event=None):
        """当Cookie变化时自动提取参数"""
        try:
            cookie = self.alipay_cookie_text.get("1.0", tk.END).strip()
            if cookie:
                params = self.extract_alipay_params_from_cookie(cookie)
                ctoken = params.get('ctoken')
                user_id = params.get('user_id')
                
                if ctoken:
                    self.alipay_ctoken_entry.delete(0, tk.END)
                    self.alipay_ctoken_entry.insert(0, ctoken)
                if user_id:
                    self.alipay_userid_entry.delete(0, tk.END)
                    self.alipay_userid_entry.insert(0, user_id)
        except Exception as e:
            pass
    
    def extract_from_cookie(self, cookie, key):
        """从cookie字符串中提取指定key的值"""
        try:
            for item in cookie.split(';'):
                item = item.strip()
                if '=' in item:
                    k, v = item.split('=', 1)
                    if k == key:
                        return v
        except:
            pass
        return None
    
    def query_alipay_orders(self, cookie, ctoken=None, user_id=None, page_num=1, page_size=20):
        """查询支付宝交易订单"""
        try:
            # 如果没有提供ctoken或user_id，从cookie中提取
            if not ctoken:
                ctoken = self.extract_from_cookie(cookie, 'ctoken')
            if not user_id:
                user_id = self.extract_from_cookie(cookie, 'CLUB_ALIPAY_COM')
            
            if not ctoken or not user_id:
                return {
                    "status": "error",
                    "message": "无法从Cookie中提取ctoken或billUserId"
                }
            
            url = f"https://mbillexprod.alipay.com/enterprise/tradeListQuery.json?ctoken={ctoken}&_output_charset=utf-8"
            
            headers = {
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                "Referer": "https://b.alipay.com/",
                "Origin": "https://b.alipay.com",
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0",
                "Accept": "application/json",
                "Accept-Encoding": "gzip, deflate, br",
                "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8,en-GB;q=0.7,en-US;q=0.6",
                "Sec-Ch-Ua": '"Not A(Brand";v="99", "Microsoft Edge";v="121", "Chromium";v="121"',
                "Sec-Ch-Ua-Mobile": "?0",
                "Sec-Ch-Ua-Platform": '"Windows"',
                "Sec-Fetch-Dest": "empty",
                "Sec-Fetch-Mode": "cors",
                "Sec-Fetch-Site": "same-site",
                "Cookie": cookie
            }
            
            data = {
                "billUserId": user_id,
                "entityFilterType": "1",
                "tradeFrom": "ALL",
                "targetTradeOwner": "USERID",
                "zftSmid": "",
                "pageNum": page_num,
                "pageSize": page_size,
                "startTime": datetime.now().strftime("%Y-%m-%d") + " 00:00:00",
                "endTime": (datetime.now() + timedelta(days=1)).strftime("%Y-%m-%d") + " 00:00:00",
                "status": "ALL",
                "sortType": "0",
                "timeType": "gmtTradeCreate",
                "_input_charset": "gbk"
            }
            
            # 禁用SSL验证以允许抓包工具拦截请求
            response = requests.post(url, data=data, headers=headers, timeout=30, verify=False)
            result = response.json()
            
            # 根据示例响应，status字段是"succeed"而不是"success"
            if result.get("status") == "succeed" or result.get("success") == "true":
                # 订单数据在 result.detail 中
                return {
                    "status": "success",
                    "data": result.get("result", {}).get("detail", []),
                    "total": result.get("result", {}).get("paging", {}).get("totalItems", 0)
                }
            else:
                return {
                    "status": "error",
                    "message": result.get("message", "请求失败")
                }
                
        except Exception as e:
            return {
                "status": "error",
                "message": str(e)
            }
    
    def start_alipay_monitor(self):
        """开始支付宝订单监听"""
        try:
            cookie = self.alipay_cookie_text.get("1.0", tk.END).strip()
            interval = float(self.alipay_interval_var.get())
            
            if not cookie:
                messagebox.showerror("错误", "请填写Cookie")
                return
            
            # 自动从Cookie提取所有参数
            params = self.extract_alipay_params_from_cookie(cookie)
            ctoken = params.get('ctoken')
            user_id = params.get('user_id')
            
            if not ctoken or not user_id:
                messagebox.showerror("错误", "无法从Cookie中提取ctoken或billUserId，请检查Cookie是否正确")
                return
            
            # 更新到输入框
            self.alipay_ctoken_entry.delete(0, tk.END)
            self.alipay_ctoken_entry.insert(0, ctoken)
            self.alipay_userid_entry.delete(0, tk.END)
            self.alipay_userid_entry.insert(0, user_id)
            
            if interval <= 0:
                messagebox.showerror("错误", "监听间隔必须大于0")
                return
            
            self.alipay_monitor_running = True
            self.alipay_start_btn.config(state="disabled")
            self.alipay_stop_btn.config(state="normal")
            self.alipay_status_var.set("监听中...")
            
            # 启动监听线程，传递所有必要参数
            self.alipay_monitor_thread = threading.Thread(
                target=self.alipay_monitor_loop,
                args=(cookie, ctoken, user_id, interval),
                daemon=True
            )
            self.alipay_monitor_thread.start()
            
            self.log_alipay("支付宝订单监听已启动")
            
            # 更新主控制页面状态
            self.alipay_monitor_status.set("支付宝: 监听中...")
            
        except ValueError:
            messagebox.showerror("错误", "请输入有效的监听间隔")
        except Exception as e:
            messagebox.showerror("错误", f"启动监听失败: {str(e)}")
    
    def stop_alipay_monitor(self):
        """停止支付宝订单监听"""
        self.alipay_monitor_running = False
        self.alipay_start_btn.config(state="normal")
        self.alipay_stop_btn.config(state="disabled")
        self.alipay_status_var.set("已停止")
        self.log_alipay("支付宝订单监听已停止")
        
        # 更新主控制页面状态
        self.alipay_monitor_status.set("支付宝: 已停止")
    
    def alipay_monitor_loop(self, cookie, ctoken, user_id, interval):
        """支付宝监听循环"""
        while self.alipay_monitor_running:
            try:
                result = self.query_alipay_orders(cookie, ctoken=ctoken, user_id=user_id, page_size=10)
                
                if result and result.get("status") == "success":
                    orders = result.get("data", [])
                    self.process_alipay_orders(orders)
                else:
                    error_msg = result.get("message", "未知错误") if result else "请求失败"
                    self.log_alipay(f"查询失败: {error_msg}")
                
                # 等待下一次查询
                for _ in range(int(interval * 10)):
                    if not self.alipay_monitor_running:
                        break
                    time.sleep(0.1)
                    
            except Exception as e:
                self.log_alipay(f"监听异常: {str(e)}")
                time.sleep(interval)
    
    def process_alipay_orders(self, orders):
        """处理支付宝订单"""
        for order in orders:
            try:
                # 提取订单信息
                trade_no = order.get("tradeNo", "")
                amount = order.get("totalAmount", "")
                status = order.get("tradeStatus", "")
                create_time = order.get("gmtTradeCreate", order.get("gmtCreate", ""))
                buyer = order.get("buyerName", "")
                subject = order.get("subject", "")
                
                # 只处理已完成的收款订单 - 根据示例响应，状态是"成功"
                if status != "成功":
                    continue
                
                # 检查是否重复
                if trade_no in self.alipay_order_history:
                    continue
                
                # 添加到历史记录
                self.alipay_order_history.append(trade_no)
                
                # 构建订单信息 - 使用与微信支付一致的字段名，只包含必要字段
                order_info = {
                    "payment_type": "alipay",
                    "amount": amount,
                    "payment_time": create_time,
                    "payer": buyer
                }
                
                # 记录订单
                self.log_alipay(f"检测到新订单: tradeNo={trade_no}, {order_info}")
                
                # 发送通知
                if self.send_notification(order_info):
                    self.log_alipay(f"订单通知发送成功: {trade_no}")
                else:
                    self.log_alipay(f"订单通知发送失败: {trade_no}")
                
            except Exception as e:
                self.log_alipay(f"处理订单异常: {str(e)}")
    
    def log_alipay(self, message):
        """记录支付宝日志"""
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_entry = f"[{timestamp}] {message}\n"
        
        self.alipay_log_text.config(state="normal")
        self.alipay_log_text.insert(tk.END, log_entry)
        self.alipay_log_text.see(tk.END)
        self.alipay_log_text.config(state="disabled")
        
        # 同时保存到按天创建的目录中
        try:
            daily_dir = get_daily_log_directory()
            log_file = os.path.join(daily_dir, "alipay_log.txt")
            with open(log_file, "a", encoding="utf-8") as f:
                f.write(log_entry)
        except Exception as e:
            print(f"保存支付宝日志失败: {e}")
    
    def create_log_tab(self):
        log_frame = ttk.Frame(self.notebook, padding="10")
        self.notebook.add(log_frame, text="日志")
        
        log_frame.columnconfigure(0, weight=1)
        log_frame.rowconfigure(0, weight=1)
        
        canvas = tk.Canvas(log_frame)
        scrollbar = ttk.Scrollbar(log_frame, orient="vertical", command=canvas.yview)
        scrollable_frame = ttk.Frame(canvas)
        
        scrollable_frame.bind(
            "<Configure>",
            lambda e: canvas.configure(scrollregion=canvas.bbox("all"))
        )
        
        canvas.create_window((0, 0), window=scrollable_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)
        
        canvas.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")
        
        scrollable_frame.columnconfigure(0, weight=1)
        
        success_frame = ttk.LabelFrame(scrollable_frame, text="成功记录", padding="5")
        success_frame.grid(row=0, column=0, padx=5, pady=5, sticky="nsew")
        success_frame.grid_rowconfigure(0, weight=1)
        success_frame.grid_columnconfigure(0, weight=1)
        
        self.success_log_text = tk.Text(success_frame, height=10, state="disabled", fg="green")
        self.success_log_text.grid(row=0, column=0, padx=5, pady=5, sticky="nsew")
        
        success_scrollbar = ttk.Scrollbar(success_frame, command=self.success_log_text.yview)
        success_scrollbar.grid(row=0, column=1, sticky="ns")
        self.success_log_text.config(yscrollcommand=success_scrollbar.set)
        
        fail_frame = ttk.LabelFrame(scrollable_frame, text="失败记录", padding="5")
        fail_frame.grid(row=1, column=0, padx=5, pady=5, sticky="nsew")
        fail_frame.grid_rowconfigure(0, weight=1)
        fail_frame.grid_columnconfigure(0, weight=1)
        
        self.fail_log_text = tk.Text(fail_frame, height=10, state="disabled", fg="red")
        self.fail_log_text.grid(row=0, column=0, padx=5, pady=5, sticky="nsew")
        
        fail_scrollbar = ttk.Scrollbar(fail_frame, command=self.fail_log_text.yview)
        fail_scrollbar.grid(row=0, column=1, sticky="ns")
        self.fail_log_text.config(yscrollcommand=fail_scrollbar.set)
    
    def on_ocr_method_change(self, event=None):
        method = self.ocr_method_var.get()
        
        self.baidu_key_entry.grid_remove()
        self.baidu_secret_entry.grid_remove()
        self.custom_api_label.grid_remove()
        self.custom_api_entry.grid_remove()
        self.umi_api_label.grid_remove()
        self.umi_api_entry.grid_remove()
        
        if method == "百度OCR":
            self.baidu_key_entry.grid()
            self.baidu_secret_entry.grid()
        elif method == "自定义API":
            self.custom_api_label.grid()
            self.custom_api_entry.grid()
        elif method == "Umi-OCR":
            self.umi_api_label.grid()
            self.umi_api_entry.grid()
    
    def auto_select_payment_window(self):
        """根据支付类型自动选择支付窗口"""
        try:
            # 刷新窗口列表
            self.windows = self.get_active_windows()
            
            # 获取当前选择的支付类型
            wechat_type = self.wechat_type_var.get()
            print(f"当前支付类型: {wechat_type}")
            print(f"可用窗口: {self.windows}")
            
            # 根据支付类型查找对应的窗口
            target_window = None
            for window_title in self.windows:
                if wechat_type == "收款助手":
                    # 查找微信收款助手窗口
                    if "微信收款助手" in window_title:
                        target_window = window_title
                        print(f"找到收款助手窗口: {target_window}")
                        break
                elif wechat_type == "赞赏码":
                    # 查找微信支付/赞赏码窗口
                    if "微信支付" in window_title or "赞赏码" in window_title:
                        target_window = window_title
                        print(f"找到赞赏码窗口: {target_window}")
                        break
            
            if target_window:
                # 找到窗口，自动选择并更新下拉框
                self.window_var.set(target_window)
                self.window_combo['values'] = self.windows
                self.window_combo.set(target_window)
                print(f"自动选择支付窗口: {target_window}")
                # 找到窗口后重置引导标志
                self.config_guide_shown = False
                return True
            else:
                # 未找到窗口，显示配置引导（只显示一次）
                print(f"未找到支付窗口，类型: {wechat_type}")
                if not self.config_guide_shown:
                    service_name = "微信收款助手" if wechat_type == "收款助手" else "微信支付"
                    fail_msg = f"未找到支付窗口: {service_name}\n\n"
                    fail_msg += "请按以下步骤操作:\n"
                    fail_msg += "1. 切换到【微信配置】标签页\n"
                    fail_msg += f"2. 点击【打开服务号】按钮，选择{service_name}\n"
                    fail_msg += "3. 等待服务号窗口打开后，返回【微信监听】重新识别\n"
                    self.log_fail(fail_msg)
                    # 显示配置引导窗口
                    self.show_wechat_config_guide(service_name)
                    self.config_guide_shown = True
                return False
                
        except Exception as e:
            print(f"自动选择支付窗口失败: {e}")
            import traceback
            traceback.print_exc()
            return False
    
    def start_capture_process(self):
        self.capture_btn.config(state="disabled")
        self.status_var.set("处理中...")
        threading.Thread(target=self.capture_and_recognize, daemon=True).start()
    
    def show_wechat_config_guide(self, service_name):
        """显示微信配置引导窗口"""
        guide_window = tk.Toplevel(self.root)
        guide_window.title("微信配置引导")
        guide_window.geometry("600x400")
        guide_window.resizable(False, False)
        guide_window.attributes('-topmost', True)
        
        # 居中显示
        guide_window.update_idletasks()
        x = (guide_window.winfo_screenwidth() // 2) - (600 // 2)
        y = (guide_window.winfo_screenheight() // 2) - (400 // 2)
        guide_window.geometry(f"+{x}+{y}")
        
        main_frame = ttk.Frame(guide_window, padding="20")
        main_frame.pack(fill=tk.BOTH, expand=True)
        
        # 标题
        ttk.Label(main_frame, text="🔧 微信配置引导", font=("Arial", 16, "bold")).pack(pady=10)
        
        # 问题说明
        ttk.Label(main_frame, text=f"未检测到 {service_name} 窗口，请按照以下步骤配置微信：", 
                 font=("Arial", 12)).pack(pady=15)
        
        # 步骤列表
        steps_frame = ttk.Frame(main_frame)
        steps_frame.pack(fill=tk.X, pady=10)
        
        steps = [
            "1. 确保微信已登录",
            "2. 打开微信主界面",
            f"3. 搜索并关注 '{service_name}' 服务号",
            "4. 进入服务号并保持窗口打开",
            "5. 返回本程序重新开始检测"
        ]
        
        for step in steps:
            ttk.Label(steps_frame, text=step, font=("Arial", 10)).pack(anchor=tk.W, pady=5)
        
        # 操作按钮
        btn_frame = ttk.Frame(main_frame)
        btn_frame.pack(pady=20)
        
        ttk.Button(btn_frame, text="切换到微信配置", 
                  command=lambda: [guide_window.destroy(), self.notebook.select(1)]).pack(side=tk.LEFT, padx=10)
        
        ttk.Button(btn_frame, text="打开服务号", 
                  command=lambda: [guide_window.destroy(), self.open_service_account()]).pack(side=tk.LEFT, padx=10)
        
        ttk.Button(btn_frame, text="取消", 
                  command=guide_window.destroy).pack(side=tk.LEFT, padx=10)
    
    def capture_and_recognize(self):
        try:
            # 自动选择支付窗口（根据支付类型）
            if not self.auto_select_payment_window():
                self.status_var.set("未找到支付窗口")
                # 引导用户去微信配置开启，只显示一次
                if not self.config_guide_shown:
                    wechat_type = self.wechat_type_var.get()
                    service_name = "微信收款助手" if wechat_type == "收款助手" else "微信支付"
                    fail_msg = f"未找到支付窗口: {service_name}\n\n"
                    fail_msg += "请按以下步骤操作:\n"
                    fail_msg += "1. 切换到【微信配置】标签页\n"
                    fail_msg += f"2. 点击【打开服务号】按钮，选择{service_name}\n"
                    fail_msg += "3. 等待服务号窗口打开后，返回【微信监听】重新识别\n"
                    self.log_fail(fail_msg)
                    # 显示配置引导窗口
                    self.show_wechat_config_guide(service_name)
                    self.config_guide_shown = True
                return
            
            self.capture_window()
            
            self.show_preview()
            
            self.status_var.set("正在调用OCR识别...")
            ocr_result = self.ocr_local_image(TEMP_IMAGE_PATH)
            
            if not ocr_result:
                self.status_var.set("OCR识别失败")
                self.log_fail("OCR识别失败，请重试")
                return
            
            payment_info = self.extract_payment_info(ocr_result)
            
            if payment_info:
                if self.is_duplicate_payment(payment_info):
                    self.status_var.set("检测到重复支付信息，已跳过")
                    # 重复订单不写入日志，只更新状态栏
                    return
                
                result_str = "=== 支付信息 ===\n"
                for key, value in payment_info.items():
                    result_str += f"{key}: {value}\n"
                
                if self.send_notification(payment_info):
                    result_str += "\n✓ 通知发送成功\n"
                else:
                    result_str += "\n✗ 通知发送失败\n"
                
                self.status_var.set("识别完成并已发送通知")
                self.log_success(result_str)
            else:
                self.status_var.set("未能提取支付信息")
                fail_text = "未能提取到有效的支付信息\n\n"
                
                words_list = [item["words"] for item in ocr_result["words_result"]]
                fail_text += "原始OCR结果：\n" + "\n".join(words_list)
                self.log_fail(fail_text)
                
        except Exception as e:
            self.status_var.set(f"错误: {str(e)}")
            self.log_fail(f"处理过程中发生错误: {str(e)}")
        finally:
            self.capture_btn.config(state="normal")
    
    def start_timer(self):
        try:
            interval = float(self.timer_interval_var.get())
            if interval <= 0:
                messagebox.showerror("错误", "检测间隔必须大于0")
                return
                
            self.timer_interval = interval
            self.timer_running = True
            self.start_timer_btn.config(state="disabled")
            self.stop_timer_btn.config(state="normal")
            self.capture_btn.config(state="disabled")
            
            # 更新主控制页面状态
            self.wechat_monitor_status.set("微信: 监听中...")
            
            self.timer_thread = threading.Thread(target=self.timer_loop, daemon=True)
            self.timer_thread.start()
            
            self.status_var.set(f"已启动定时检测，间隔 {interval} 秒")
        except ValueError:
            messagebox.showerror("错误", "请输入有效的数字")
    
    def stop_timer(self):
        self.timer_running = False
        self.start_timer_btn.config(state="normal")
        self.stop_timer_btn.config(state="disabled")
        self.capture_btn.config(state="normal")
        self.status_var.set("已停止定时检测")
        
        # 更新主控制页面状态
        self.wechat_monitor_status.set("微信: 已停止")
    
    def timer_loop(self):
        while self.timer_running:
            self.capture_and_recognize()
            time.sleep(self.timer_interval)
    
    def clear_history(self):
        self.payment_history.clear()
        self.status_var.set("已清空历史记录")
        messagebox.showinfo("成功", "已清空支付历史记录")
    
    def on_save_config(self):
        if self.save_config():
            self.status_var.set("配置已保存")
            messagebox.showinfo("成功", "配置已保存")
        else:
            self.status_var.set("配置保存失败")
            messagebox.showerror("错误", "配置保存失败")
    
    def export_server_config(self):
        """导出服务端配置（支付宝商家账单配置）"""
        try:
            # 构建服务端配置（纯数据，无中文说明）
            server_config = {
                "alipay_app_id": self.config.get("alipay_app_id", ""),
                "sign_type": "RSA2",
                "charset": "utf-8",
                "gateway_url": "https://openapi.alipay.com/gateway.do"
            }

            # 检查是否有可导出的配置
            if not server_config["alipay_app_id"]:
                messagebox.showwarning("警告", "支付宝应用ID未配置，请先配置支付宝应用")
                return

            # 选择保存路径
            from tkinter import filedialog
            file_path = filedialog.asksaveasfilename(
                defaultextension=".json",
                filetypes=[("JSON文件", "*.json"), ("所有文件", "*.*")],
                initialfile="server_config.json",
                title="导出服务端配置"
            )

            if not file_path:
                return

            # 保存配置
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(server_config, f, ensure_ascii=False, indent=2)

            self.status_var.set(f"服务端配置已导出: {file_path}")
            messagebox.showinfo(
                "导出成功",
                f"服务端配置已导出到:\n{file_path}\n\n"
                f"包含内容:\n"
                f"1. 支付宝SDK配置 - 用于服务端调用支付宝API\n\n"
                f"请将此文件安全地部署到您的服务器！"
            )

        except Exception as e:
            self.status_var.set(f"导出配置失败: {str(e)}")
            messagebox.showerror("错误", f"导出配置失败: {str(e)}")
    
    def capture_window(self):
        selected_title = self.window_var.get()
        if not selected_title:
            self.status_var.set("错误：请先选择一个窗口！")
            return
        
        try:
            hwnd = win32gui.FindWindow(None, selected_title)
            if not hwnd:
                target_windows = gw.getWindowsWithTitle(selected_title)
                if target_windows:
                    hwnd = target_windows[0]._hWnd
            
            if not hwnd:
                self.status_var.set(f"错误：找不到标题为'{selected_title}'的窗口")
                return
            
            self.status_var.set("正在后台截图...")
            
            # 禁用动画实现瞬间操作
            self.disable_window_animation()
            
            # 根据用户选择的支付类型调整窗口尺寸
            wechat_type = self.wechat_type_var.get()
            if wechat_type == "收款助手":
                target_width, target_height = 397, 794
            else:
                target_width, target_height = 391, 595
            user32.SetWindowPos(hwnd, 0, 0, 0, target_width, target_height, SWP_NOMOVE | SWP_NOZORDER | SWP_SHOWWINDOW)
            
            is_minimized = user32.IsIconic(hwnd)
            original_style = None
            
            if is_minimized:
                original_style = user32.GetWindowLongA(hwnd, GWL_EXSTYLE)
                user32.SetWindowLongA(hwnd, GWL_EXSTYLE, original_style | WS_EX_LAYERED)
                user32.SetLayeredWindowAttributes(hwnd, 0, 1, LWA_ALPHA)
                user32.ShowWindow(hwnd, SW_RESTORE)
                # 等待窗口内容渲染，避免黑屏
                time.sleep(0.2)
                user32.SendMessageA(hwnd, WM_PAINT, 0, 0)
                time.sleep(0.1)
            
            rect = win32gui.GetWindowRect(hwnd)
            width = rect[2] - rect[0]
            height = rect[3] - rect[1]
            
            if width <= 0 or height <= 0:
                self.status_var.set("错误：窗口尺寸无效")
                self.enable_window_animation()
                return
            
            hwndDC = win32gui.GetWindowDC(hwnd)
            mfcDC = win32ui.CreateDCFromHandle(hwndDC)
            saveDC = mfcDC.CreateCompatibleDC()
            
            saveBitMap = win32ui.CreateBitmap()
            saveBitMap.CreateCompatibleBitmap(mfcDC, width, height)
            saveDC.SelectObject(saveBitMap)
            
            ctypes.windll.user32.PrintWindow(hwnd, saveDC.GetSafeHdc(), PW_RENDERFULLCONTENT)
            
            bmpinfo = saveBitMap.GetInfo()
            bmpstr = saveBitMap.GetBitmapBits(True)
            
            im = Image.frombuffer(
                'RGB',
                (bmpinfo['bmWidth'], bmpinfo['bmHeight']),
                bmpstr, 'raw', 'BGRX', 0, 1)
            
            win32gui.DeleteObject(saveBitMap.GetHandle())
            saveDC.DeleteDC()
            mfcDC.DeleteDC()
            win32gui.ReleaseDC(hwnd, hwndDC)
            
            if is_minimized:
                user32.ShowWindow(hwnd, SW_MINIMIZE)
                if original_style is not None:
                    user32.SetWindowLongA(hwnd, GWL_EXSTYLE, original_style)
            
            # 恢复动画设置
            self.enable_window_animation()
            
            im.save(TEMP_IMAGE_PATH)
            
        except Exception as e:
            self.status_var.set(f"截图错误: {str(e)}")
            raise
    
    def show_preview(self):
        try:
            if not os.path.exists(TEMP_IMAGE_PATH):
                self.image_label.config(text="截图文件不存在")
                return
                
            img = Image.open(TEMP_IMAGE_PATH)
            
            max_width, max_height = 600, 400
            width, height = img.size
            ratio = min(max_width/width, max_height/height)
            new_size = (int(width * ratio), int(height * ratio))
            
            img = img.resize(new_size, Image.LANCZOS)
            
            photo = ImageTk.PhotoImage(img)
            
            self.image_label.config(image=photo)
            self.image_label.image = photo
            
            self.image_label.config(width=new_size[0], height=new_size[1])
            
        except Exception as e:
            self.image_label.config(text=f"预览错误: {str(e)}")
    
    def log_success(self, text):
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_entry = f"[{timestamp}]\n{text}\n{'='*50}\n"
        self.success_log_text.config(state="normal")
        self.success_log_text.insert(tk.END, log_entry)
        self.success_log_text.see(tk.END)
        self.success_log_text.config(state="disabled")
        
        # 保存到按天创建的目录中
        try:
            daily_dir = get_daily_log_directory()
            log_file = os.path.join(daily_dir, "success_log.txt")
            with open(log_file, "a", encoding="utf-8") as f:
                f.write(log_entry)
        except Exception as e:
            print(f"保存成功日志失败: {e}")
    
    def log_fail(self, text):
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_entry = f"[{timestamp}]\n{text}\n{'='*50}\n"
        self.fail_log_text.config(state="normal")
        self.fail_log_text.insert(tk.END, log_entry)
        self.fail_log_text.see(tk.END)
        self.fail_log_text.config(state="disabled")
        
        # 保存到按天创建的目录中
        try:
            daily_dir = get_daily_log_directory()
            log_file = os.path.join(daily_dir, "fail_log.txt")
            with open(log_file, "a", encoding="utf-8") as f:
                f.write(log_entry)
        except Exception as e:
            print(f"保存失败日志失败: {e}")
    
    def start_and_login_wechat(self):
        """启动微信并自动进行二维码登录（先检测状态避免闪烁）"""
        try:
            # 先检测当前状态
            self.refresh_windows_list()
            time.sleep(0.3)
            
            # 查找微信窗口（硬性匹配：标题完全等于"微信"或"WeChat"或"Weixin"）
            wechat_window = None
            for window_title in self.windows:
                if window_title in ["微信", "WeChat", "Weixin"]:
                    wechat_window = window_title
                    break
            
            if wechat_window:
                # 找到窗口，先检测状态
                self.window_var.set(wechat_window)
                hwnd = self.get_selected_window_hwnd()
                
                if hwnd:
                    # 检测窗口是否最小化
                    is_minimized = user32.IsIconic(hwnd)
                    if is_minimized:
                        user32.ShowWindow(hwnd, SW_RESTORE)
                    
                    rect = win32gui.GetWindowRect(hwnd)
                    width = rect[2] - rect[0]
                    height = rect[3] - rect[1]
                    
                    # 如果已经登录，不需要再启动
                    if width > 700 and height > 500:
                        self.wechat_status_var.set("已登录")
                        # 先最小化窗口，再弹出提示
                        if is_minimized:
                            user32.ShowWindow(hwnd, SW_MINIMIZE)
                        messagebox.showinfo("提示", "微信已登录，无需重复操作")
                        return
                    
                    # 如果是登录界面，直接获取二维码
                    if width < 400 and height < 500:
                        self.wechat_status_var.set("检测到登录界面，正在获取二维码...")
                        self.try_capture_qrcode_or_click(hwnd)
                        return
                    
                    # 恢复最小化状态
                    if is_minimized:
                        user32.ShowWindow(hwnd, SW_MINIMIZE)
            
            # 没有找到窗口或窗口未启动，启动微信
            wechat_path = self.find_wechat_path()
            
            if not wechat_path or not os.path.exists(wechat_path):
                messagebox.showerror("错误", "找不到微信程序，请手动选择微信路径")
                return
            
            # 禁用动画实现瞬间操作
            self.disable_window_animation()
            
            subprocess.Popen([wechat_path])
            self.wechat_status_var.set("微信启动中...")
            
            # 快速等待窗口出现并处理
            def wait_and_login():
                # 快速轮询等待窗口出现
                for _ in range(50):  # 最多等待5秒
                    time.sleep(0.1)
                    # 刷新窗口列表
                    self.windows = self.get_active_windows()
                    # 查找微信窗口（硬性匹配）
                    wechat_window = None
                    for window_title in self.windows:
                        if window_title in ["微信", "WeChat", "Weixin"]:
                            wechat_window = window_title
                            break
                    
                    if wechat_window:
                        # 找到窗口，立即处理
                        self.root.after(0, lambda wt=wechat_window: self.window_var.set(wt))
                        self.root.after(0, lambda: self.wechat_status_var.set("正在获取登录二维码..."))
                        
                        # 获取窗口句柄
                        hwnd = self.get_selected_window_hwnd()
                        if hwnd:
                            self.root.after(0, lambda h=hwnd: self.try_capture_qrcode_or_click(h))
                        break
                else:
                    # 超时
                    self.root.after(0, lambda: self.wechat_status_var.set("未找到微信窗口，请手动选择后点击检测状态"))
                    self.root.after(0, self.enable_window_animation)
            
            threading.Thread(target=wait_and_login, daemon=True).start()
            
        except Exception as e:
            self.enable_window_animation()
            messagebox.showerror("错误", f"启动微信失败: {str(e)}")
    
    def select_wechat_window(self):
        """自动选择微信窗口（硬性匹配）"""
        for window_title in self.windows:
            if window_title in ["微信", "WeChat", "Weixin"]:
                self.window_var.set(window_title)
                self.wechat_status_var.set(f"已选择窗口: {window_title}")
                return
        
        # 如果没找到，显示提示
        self.wechat_status_var.set("未找到微信窗口，请手动选择")
    
    def try_capture_qrcode_or_click(self, hwnd):
        """尝试截取二维码，如果没有则点击切换账户（闪烁模式）"""
        try:
            # 检测窗口是否最小化
            was_minimized = user32.IsIconic(hwnd)
            
            # 如果最小化，先恢复窗口
            if was_minimized:
                user32.ShowWindow(hwnd, SW_RESTORE)
            
            rect = win32gui.GetWindowRect(hwnd)
            width = rect[2] - rect[0]
            height = rect[3] - rect[1]
            
            # 截图
            hwndDC = win32gui.GetWindowDC(hwnd)
            mfcDC = win32ui.CreateDCFromHandle(hwndDC)
            saveDC = mfcDC.CreateCompatibleDC()
            
            saveBitMap = win32ui.CreateBitmap()
            saveBitMap.CreateCompatibleBitmap(mfcDC, width, height)
            saveDC.SelectObject(saveBitMap)
            
            ctypes.windll.user32.PrintWindow(hwnd, saveDC.GetSafeHdc(), 0)
            
            bmpinfo = saveBitMap.GetInfo()
            bmpstr = saveBitMap.GetBitmapBits(True)
            
            im = Image.frombuffer(
                'RGB',
                (bmpinfo['bmWidth'], bmpinfo['bmHeight']),
                bmpstr, 'raw', 'BGRX', 0, 1)
            
            win32gui.DeleteObject(saveBitMap.GetHandle())
            saveDC.DeleteDC()
            mfcDC.DeleteDC()
            win32gui.ReleaseDC(hwnd, hwndDC)
            
            # 裁剪二维码区域
            qr_size = min(width, height) - 40
            left = (width - qr_size) // 2
            top = (height - qr_size) // 2 - 30
            qr_im = im.crop((left, top, left + qr_size, top + qr_size))
            
            # 尝试解码二维码
            if decode_qr:
                decoded_objects = decode_qr(qr_im, symbols=[ZBarSymbol.QRCODE])
                if decoded_objects:
                    # 识别到二维码，显示二维码窗口并立即最小化微信窗口
                    print("识别到二维码，显示二维码窗口")
                    self.capture_and_show_qrcode(hwnd)
                    user32.ShowWindow(hwnd, SW_MINIMIZE)  # 立即最小化，闪烁模式
                    self.enable_window_animation()
                    # 确保自动检测正在运行
                    if not hasattr(self, 'auto_detection_running') or not self.auto_detection_running:
                        print("重新启动自动检测")
                        self.start_auto_detection()
                    return
            
            # 没有识别到二维码，点击切换账户
            self.wechat_status_var.set("未识别到二维码，点击切换账户...")
            self.click_switch_account(hwnd)
            
        except Exception as e:
            print(f"尝试截取二维码失败: {e}")
            self.click_switch_account(hwnd)
    
    def close_wechat(self):
        """关闭微信进程"""
        try:
            closed = False
            # 查找并关闭微信进程
            for proc in psutil.process_iter(['pid', 'name']):
                try:
                    if proc.info['name'] and ('wechat' in proc.info['name'].lower() or 'weixin' in proc.info['name'].lower()):
                        proc.terminate()
                        closed = True
                        print(f"已终止进程: {proc.info['name']} (PID: {proc.info['pid']})")
                except (psutil.NoSuchProcess, psutil.AccessDenied):
                    pass
            
            if closed:
                self.wechat_status_var.set("微信已关闭")
                messagebox.showinfo("成功", "微信进程已关闭")
            else:
                self.wechat_status_var.set("未找到微信进程")
                messagebox.showinfo("提示", "未找到运行中的微信进程")
                
        except Exception as e:
            messagebox.showerror("错误", f"关闭微信失败: {str(e)}")
    
    def find_wechat_path(self):
        """查找微信安装路径"""
        # 1. 从注册表查找
        path = self.find_wechat_from_registry()
        if path:
            return path
        
        # 2. 从开始菜单快捷方式查找
        path = self.find_wechat_from_shortcut()
        if path:
            return path
        
        # 3. 检查常见安装路径（新旧版本都检查）
        common_paths = [
            # 新版微信路径
            r"C:\Program Files (x86)\Tencent\Weixin\Weixin.exe",
            r"C:\Program Files\Tencent\Weixin\Weixin.exe",
            r"D:\Program Files (x86)\Tencent\Weixin\Weixin.exe",
            r"D:\Program Files\Tencent\Weixin\Weixin.exe",
            r"E:\Program Files (x86)\Tencent\Weixin\Weixin.exe",
            r"E:\Program Files\Tencent\Weixin\Weixin.exe",
            # 旧版微信路径
            r"C:\Program Files (x86)\Tencent\WeChat\WeChat.exe",
            r"C:\Program Files\Tencent\WeChat\WeChat.exe",
            r"D:\Program Files (x86)\Tencent\WeChat\WeChat.exe",
            r"D:\Program Files\Tencent\WeChat\WeChat.exe",
            r"E:\Program Files (x86)\Tencent\WeChat\WeChat.exe",
            r"E:\Program Files\Tencent\WeChat\WeChat.exe",
        ]
        for path in common_paths:
            if os.path.exists(path):
                return path
        
        return None
    
    def find_wechat_from_registry(self):
        """从注册表查找微信路径"""
        try:
            import winreg
            
            # 尝试多个注册表路径
            registry_paths = [
                (winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"),
                (winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"),
                (winreg.HKEY_CURRENT_USER, r"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"),
            ]
            
            for hkey, key_path in registry_paths:
                try:
                    with winreg.OpenKey(hkey, key_path) as key:
                        for i in range(winreg.QueryInfoKey(key)[0]):
                            try:
                                subkey_name = winreg.EnumKey(key, i)
                                with winreg.OpenKey(key, subkey_name) as subkey:
                                    try:
                                        display_name, _ = winreg.QueryValueEx(subkey, "DisplayName")
                                        if "微信" in display_name or "WeChat" in display_name or "Weixin" in display_name:
                                            # 找到微信，尝试获取安装路径
                                            try:
                                                install_location, _ = winreg.QueryValueEx(subkey, "InstallLocation")
                                                if install_location:
                                                    # 先尝试新版路径
                                                    exe_path = os.path.join(install_location, "Weixin.exe")
                                                    if os.path.exists(exe_path):
                                                        return exe_path
                                                    # 再尝试旧版路径
                                                    exe_path = os.path.join(install_location, "WeChat.exe")
                                                    if os.path.exists(exe_path):
                                                        return exe_path
                                            except:
                                                pass
                                            
                                            try:
                                                display_icon, _ = winreg.QueryValueEx(subkey, "DisplayIcon")
                                                if display_icon:
                                                    # 检查新版或旧版
                                                    if "Weixin.exe" in display_icon:
                                                        return display_icon.split(',')[0].strip('"')
                                                    elif "WeChat.exe" in display_icon:
                                                        return display_icon.split(',')[0].strip('"')
                                            except:
                                                pass
                                    except:
                                        continue
                            except:
                                continue
                except:
                    continue
            
            return None
        except Exception as e:
            print(f"从注册表查找微信失败: {e}")
            return None
    
    def find_wechat_from_shortcut(self):
        """从开始菜单快捷方式查找微信"""
        try:
            import glob
            
            # 开始菜单路径
            start_menu_paths = [
                os.path.expandvars(r"%ProgramData%\Microsoft\Windows\Start Menu\Programs"),
                os.path.expandvars(r"%AppData%\Microsoft\Windows\Start Menu\Programs"),
            ]
            
            for start_menu in start_menu_paths:
                if os.path.exists(start_menu):
                    # 查找微信快捷方式（新旧版本都检查）
                    for lnk_file in glob.glob(os.path.join(start_menu, "**", "*.lnk"), recursive=True):
                        if "微信" in lnk_file or "WeChat" in lnk_file or "Weixin" in lnk_file:
                            # 解析快捷方式获取目标路径
                            try:
                                import win32com.client
                                shell = win32com.client.Dispatch("WScript.Shell")
                                shortcut = shell.CreateShortCut(lnk_file)
                                target_path = shortcut.Targetpath
                                if target_path:
                                    # 检查新版或旧版
                                    if "Weixin.exe" in target_path and os.path.exists(target_path):
                                        return target_path
                                    elif "WeChat.exe" in target_path and os.path.exists(target_path):
                                        return target_path
                            except:
                                pass
            
            return None
        except Exception as e:
            print(f"从快捷方式查找微信失败: {e}")
            return None
    
    def browse_wechat_path(self):
        """手动浏览选择微信路径"""
        from tkinter import filedialog
        path = filedialog.askopenfilename(
            title="选择微信程序",
            filetypes=[("微信程序", "Weixin.exe;WeChat.exe"), ("可执行文件", "*.exe")]
        )
        if path:
            self.wechat_path_var.set(path)
            self.save_config()
    
    def get_selected_window_hwnd(self):
        """获取当前选择窗口的句柄"""
        selected_title = self.window_var.get()
        if not selected_title:
            return None
        
        try:
            # 使用pygetwindow根据标题查找
            windows = gw.getWindowsWithTitle(selected_title)
            if windows:
                return windows[0]._hWnd
            
            # 备用：遍历所有窗口查找
            def callback(hwnd, extra):
                if win32gui.IsWindowVisible(hwnd):
                    title = win32gui.GetWindowText(hwnd)
                    if title == selected_title:
                        extra.append(hwnd)
            
            handles = []
            win32gui.EnumWindows(callback, handles)
            if handles:
                return handles[0]
            
            return None
        except:
            return None
    
    def handle_login(self):
        """处理微信登录"""
        try:
            # 使用当前选择的窗口
            hwnd = self.get_selected_window_hwnd()
            
            if not hwnd:
                messagebox.showerror("错误", "未找到微信窗口，请先在窗口选择下拉框中选择微信窗口")
                return
            
            # 获取窗口大小判断状态
            rect = win32gui.GetWindowRect(hwnd)
            width = rect[2] - rect[0]
            height = rect[3] - rect[1]
            
            if width > 800 and height > 600:
                messagebox.showinfo("提示", "微信已登录")
                return
            
            # 根据选择的登录方式处理
            login_method = self.login_method_var.get()
            
            if login_method == "direct":
                self.click_login_button(hwnd)
            elif login_method == "qrcode":
                self.capture_and_show_qrcode(hwnd)
            else:
                # 自动检测
                self.auto_detect_login(hwnd)
                
        except Exception as e:
            messagebox.showerror("错误", f"登录处理失败: {str(e)}")
    
    def auto_detect_login(self, hwnd):
        """自动检测登录方式"""
        try:
            # 截取窗口图像进行OCR识别
            rect = win32gui.GetWindowRect(hwnd)
            width = rect[2] - rect[0]
            height = rect[3] - rect[1]
            
            # 使用PrintWindow截图
            hwndDC = win32gui.GetWindowDC(hwnd)
            mfcDC = win32ui.CreateDCFromHandle(hwndDC)
            saveDC = mfcDC.CreateCompatibleDC()
            
            saveBitMap = win32ui.CreateBitmap()
            saveBitMap.CreateCompatibleBitmap(mfcDC, width, height)
            saveDC.SelectObject(saveBitMap)
            
            ctypes.windll.user32.PrintWindow(hwnd, saveDC.GetSafeHdc(), 0)
            
            bmpinfo = saveBitMap.GetInfo()
            bmpstr = saveBitMap.GetBitmapBits(True)
            
            im = Image.frombuffer(
                'RGB',
                (bmpinfo['bmWidth'], bmpinfo['bmHeight']),
                bmpstr, 'raw', 'BGRX', 0, 1)
            
            win32gui.DeleteObject(saveBitMap.GetHandle())
            saveDC.DeleteDC()
            mfcDC.DeleteDC()
            win32gui.ReleaseDC(hwnd, hwndDC)
            
            # 保存临时图片用于OCR
            temp_path = "wechat_login_temp.png"
            im.save(temp_path)
            
            # OCR识别
            ocr_result = self.ocr_local_image(temp_path)
            
            if ocr_result and "words_result" in ocr_result:
                words_list = [item["words"] for item in ocr_result["words_result"]]
                text = " ".join(words_list)
                
                # 检测登录方式
                if "进入微信" in text or "登录" in text:
                    result = messagebox.askyesno("选择登录方式", "检测到可以直接登录\n是：直接登录\n否：使用二维码登录")
                    if result:
                        self.click_login_button(hwnd)
                    else:
                        self.click_switch_account(hwnd)
                elif "切换账户" in text:
                    self.click_switch_account(hwnd)
                else:
                    self.capture_and_show_qrcode(hwnd)
            else:
                self.capture_and_show_qrcode(hwnd)
                
            # 清理临时文件
            if os.path.exists(temp_path):
                os.remove(temp_path)
                
        except Exception as e:
            messagebox.showerror("错误", f"自动检测失败: {str(e)}")
    
    def check_wechat_status(self):
        """检测微信状态（闪烁模式：瞬间激活获取然后瞬间关闭）"""
        try:
            # 先刷新窗口列表
            self.refresh_windows_list()
            
            # 自动选择微信窗口（硬性匹配：标题完全等于"微信"或"WeChat"或"Weixin"）
            wechat_found = False
            for window_title in self.windows:
                if window_title in ["微信", "WeChat", "Weixin"]:
                    self.window_var.set(window_title)
                    self.wechat_status_var.set(f"已选择窗口: {window_title}")
                    wechat_found = True
                    break
            
            if not wechat_found:
                self.wechat_status_var.set("未找到微信窗口")
                messagebox.showinfo("状态", "未找到微信窗口，请确保微信已启动")
                return
            
            # 获取选择的窗口句柄
            hwnd = self.get_selected_window_hwnd()
            
            if not hwnd:
                self.wechat_status_var.set("无法获取窗口句柄")
                return
            
            # 检测窗口是否最小化
            is_minimized = user32.IsIconic(hwnd)
            
            if not is_minimized:
                # 窗口已经在前台，直接获取状态
                rect = win32gui.GetWindowRect(hwnd)
                width = rect[2] - rect[0]
                height = rect[3] - rect[1]
                
                # 根据窗口大小判断状态
                if width < 400 and height < 500:
                    status_text = "未登录-登录界面"
                    self.wechat_status_var.set(status_text)
                    msg = "微信未登录，请点击【启动并登录】按钮"
                elif width > 700 and height > 500:
                    status_text = "已登录"
                    self.wechat_status_var.set(status_text)
                    msg = "微信已登录"
                else:
                    status_text = f"未知状态 ({width}x{height})"
                    self.wechat_status_var.set(status_text)
                    msg = f"未知状态 ({width}x{height})"
                
                # 最后才弹出提示窗口
                messagebox.showinfo("状态", msg)
            else:
                # 窗口最小化，使用闪烁模式：禁用动画 → 恢复窗口 → 获取状态 → 最小化 → 恢复动画
                self.disable_window_animation()
                
                # 瞬间恢复窗口（不激活，避免闪烁）
                user32.ShowWindow(hwnd, SW_RESTORE)
                
                # 立即获取窗口大小
                rect = win32gui.GetWindowRect(hwnd)
                width = rect[2] - rect[0]
                height = rect[3] - rect[1]
                
                # 根据窗口大小判断状态
                if width < 400 and height < 500:
                    status_text = "未登录-登录界面 (已最小化)"
                    self.wechat_status_var.set(status_text)
                    msg = "微信未登录 (窗口已最小化)，请点击【启动并登录】按钮"
                elif width > 700 and height > 500:
                    status_text = "已登录 (已最小化)"
                    self.wechat_status_var.set(status_text)
                    msg = "微信已登录 (窗口已最小化)"
                else:
                    status_text = f"未知状态 ({width}x{height}) (已最小化)"
                    self.wechat_status_var.set(status_text)
                    msg = f"未知状态 ({width}x{height}) (窗口已最小化)"
                
                # 瞬间最小化窗口
                user32.ShowWindow(hwnd, SW_MINIMIZE)
                
                # 恢复动画
                self.enable_window_animation()
                
                # 最后才弹出提示窗口
                messagebox.showinfo("状态", msg)
                
        except Exception as e:
            self.enable_window_animation()
            messagebox.showerror("错误", f"检测状态失败: {str(e)}")
    
    def start_auto_detection(self):
        """启动自动检测微信登录状态（一直轮询直到登录成功）"""
        if hasattr(self, 'auto_detection_running') and self.auto_detection_running:
            return
        
        self.auto_detection_running = True
        
        def auto_detect_loop():
            while self.auto_detection_running:
                try:
                    # 获取当前选择的窗口句柄
                    hwnd = self.get_selected_window_hwnd()
                    
                    # 如果没有选择窗口，尝试自动查找（排除二维码窗口）
                    if not hwnd:
                        self.windows = self.get_active_windows()
                        for window_title in self.windows:
                            # 硬性匹配：标题完全等于"微信"或"WeChat"或"Weixin"
                            if window_title in ["微信", "WeChat", "Weixin"]:
                                print(f"自动检测到微信窗口: {window_title}")
                                self.root.after(0, lambda wt=window_title: self.window_var.set(wt))
                                hwnd = self.get_selected_window_hwnd()
                                break
                    
                    if hwnd:
                        # 检测窗口是否最小化
                        is_minimized = user32.IsIconic(hwnd)
                        
                        # 如果最小化，先恢复窗口才能获取正确尺寸
                        if is_minimized:
                            user32.ShowWindow(hwnd, SW_RESTORE)
                        
                        rect = win32gui.GetWindowRect(hwnd)
                        width = rect[2] - rect[0]
                        height = rect[3] - rect[1]
                        
                        # 判断状态
                        # 放宽登录检测条件：宽度大于700且高度大于500即认为已登录
                        if width > 700 and height > 500:
                            # 已登录主界面 - 登录成功，停止检测
                            print(f"检测到登录成功: {width}x{height}")
                            user32.ShowWindow(hwnd, SW_MINIMIZE)
                            # 关闭二维码窗口
                            if hasattr(self, 'qrcode_window') and self.qrcode_window.winfo_exists():
                                self.root.after(0, self.qrcode_window.destroy)
                            self.root.after(0, lambda: self.wechat_status_var.set("已登录 (已最小化)"))
                            self.root.after(0, lambda: messagebox.showinfo("成功", "微信登录成功"))
                            self.stop_auto_detection()
                            break
                        elif width < 400 and height < 500:
                            # 登录界面 - 等待扫码，继续轮询
                            print(f"检测到登录界面: {width}x{height}")
                            status_text = "未登录-等待扫码"
                            if is_minimized:
                                status_text += " (已最小化)"
                            self.root.after(0, lambda t=status_text: self.wechat_status_var.set(t))
                            # 恢复原来的最小化状态
                            if is_minimized:
                                user32.ShowWindow(hwnd, SW_MINIMIZE)
                        else:
                            # 其他状态，继续轮询
                            print(f"检测到其他状态: {width}x{height}")
                            status_text = f"检测中 ({width}x{height})"
                            if is_minimized:
                                status_text += " (已最小化)"
                            self.root.after(0, lambda t=status_text: self.wechat_status_var.set(t))
                            # 恢复原来的最小化状态
                            if is_minimized:
                                user32.ShowWindow(hwnd, SW_MINIMIZE)
                    else:
                        # 尝试自动查找微信窗口
                        self.windows = self.get_active_windows()
                        wechat_found = False
                        for window_title in self.windows:
                            if window_title in ["微信", "WeChat", "Weixin"]:
                                self.root.after(0, lambda wt=window_title: self.window_var.set(wt))
                                wechat_found = True
                                break
                        
                        if not wechat_found:
                            self.root.after(0, lambda: self.wechat_status_var.set("未找到微信窗口"))
                except Exception as e:
                    print(f"自动检测异常: {e}")
                
                # 每0.8秒检测一次（持续轮询直到登录成功）
                time.sleep(0.8)
        
        threading.Thread(target=auto_detect_loop, daemon=True).start()
        
        # 更新自动检测状态显示
        if hasattr(self, 'auto_detect_var'):
            self.auto_detect_var.set("自动检测: 运行中")
    
    def stop_auto_detection(self):
        """停止自动检测"""
        self.auto_detection_running = False
        if hasattr(self, 'auto_detect_var'):
            self.auto_detect_var.set("自动检测: 已停止")
    
    def load_history_orders(self):
        """从日志中加载历史订单，防止重复通知"""
        try:
            daily_dir = get_daily_log_directory()
            
            # 加载微信支付历史
            success_log_file = os.path.join(daily_dir, "success_log.txt")
            if os.path.exists(success_log_file):
                with open(success_log_file, "r", encoding="utf-8") as f:
                    content = f.read()
                    # 解析成功日志，提取支付信息
                    import re
                    # 匹配支付信息块
                    payment_blocks = re.split(r'={50}\n', content)
                    for block in payment_blocks:
                        if "支付信息" in block:
                            # 提取支付时间、付款人作为唯一标识（与is_duplicate_payment保持一致）
                            # 兼容新旧格式：payment_time 或 到账时间
                            time_match = re.search(r'(?:payment_time|到账时间): ([\d]+-[\d]+-[\d]+\s+[\d]+:[\d]+:[\d]+)', block)
                            payer_match = re.search(r'(?:payer|付款人): ([^\n]+)', block)
                            if time_match:
                                # 构建与is_duplicate_payment一致的标识符
                                # 注意：is_duplicate_payment使用 payment_time_payer_remark 的格式
                                # 而remark在日志中不记录，所以这里使用空字符串作为remark
                                # 同时需要去除payer中的空格，确保与实际处理时一致
                                payer = payer_match.group(1).strip() if payer_match else ''
                                identifier = f"{time_match.group(1)}_{payer}_"
                                if identifier not in self.payment_history:
                                    self.payment_history.append(identifier)
            
            # 加载支付宝订单历史
            alipay_log_file = os.path.join(daily_dir, "alipay_log.txt")
            if os.path.exists(alipay_log_file):
                with open(alipay_log_file, "r", encoding="utf-8") as f:
                    content = f.read()
                    # 解析支付宝日志，提取订单号
                    import re
                    # 匹配订单号
                    order_matches = re.findall(r'检测到新订单: tradeNo=([^,]+),', content, re.DOTALL)
                    for trade_no in order_matches:
                        if trade_no and trade_no not in self.alipay_order_history:
                            self.alipay_order_history.append(trade_no)
            
            print(f"已加载历史订单: 微信 {len(self.payment_history)} 笔, 支付宝 {len(self.alipay_order_history)} 笔")
            # 打印前几个历史订单的标识符，用于调试
            if self.payment_history:
                print(f"微信历史订单前5个标识符: {self.payment_history[:5]}")
        except Exception as e:
            print(f"加载历史订单失败: {e}")
    
    def reset_login_detection(self):
        """重置登录检测状态（用于重新检测）"""
        self.login_detected = False
        self.start_auto_detection()
    
    def handle_login_screen(self, hwnd):
        """处理登录界面"""
        login_method = self.login_method_var.get()
        
        if login_method == "direct":
            # 直接点击登录
            self.click_login_button(hwnd)
        elif login_method == "qrcode":
            # 显示二维码
            self.capture_and_show_qrcode(hwnd)
        else:
            # 自动检测
            self.auto_detect_login_method(hwnd)
    
    def auto_detect_login_method(self, hwnd):
        """自动检测登录方式"""
        try:
            # 截取窗口图像进行OCR识别
            rect = win32gui.GetWindowRect(hwnd)
            width = rect[2] - rect[0]
            height = rect[3] - rect[1]
            
            # 使用PrintWindow截图
            hwndDC = win32gui.GetWindowDC(hwnd)
            mfcDC = win32ui.CreateDCFromHandle(hwndDC)
            saveDC = mfcDC.CreateCompatibleDC()
            
            saveBitMap = win32ui.CreateBitmap()
            saveBitMap.CreateCompatibleBitmap(mfcDC, width, height)
            saveDC.SelectObject(saveBitMap)
            
            ctypes.windll.user32.PrintWindow(hwnd, saveDC.GetSafeHdc(), 0)
            
            bmpinfo = saveBitMap.GetInfo()
            bmpstr = saveBitMap.GetBitmapBits(True)
            
            im = Image.frombuffer(
                'RGB',
                (bmpinfo['bmWidth'], bmpinfo['bmHeight']),
                bmpstr, 'raw', 'BGRX', 0, 1)
            
            win32gui.DeleteObject(saveBitMap.GetHandle())
            saveDC.DeleteDC()
            mfcDC.DeleteDC()
            win32gui.ReleaseDC(hwnd, hwndDC)
            
            # 保存临时图片用于OCR
            temp_path = "wechat_login_temp.png"
            im.save(temp_path)
            
            # OCR识别
            ocr_result = self.ocr_local_image(temp_path)
            
            login_text_found = False
            
            if ocr_result and "words_result" in ocr_result:
                words_list = [item["words"] for item in ocr_result["words_result"]]
                text = " ".join(words_list)
                print(f"OCR识别结果: {text}")
                
                # 检测登录方式（优先级排序）
                # 1. 检测到"进入微信" - 之前登录过，可以直接进入
                if "进入微信" in text:
                    login_text_found = True
                    result = messagebox.askyesno("选择登录方式", "检测到可以直接登录（进入微信）\n是：直接登录\n否：使用二维码登录")
                    if result:
                        self.click_login_button(hwnd)
                    else:
                        self.click_switch_account(hwnd)
                    return
                
                # 2. 检测到"登录"文字（但不是"进入微信"）
                if "登录" in text and "退出" not in text:
                    login_text_found = True
                    result = messagebox.askyesno("选择登录方式", "检测到可以直接登录\n是：直接登录\n否：使用二维码登录")
                    if result:
                        self.click_login_button(hwnd)
                    else:
                        self.click_switch_account(hwnd)
                    return
                
                # 3. 检测到"切换账户" - 点击切换账户显示二维码
                if "切换账户" in text or "切换帐号" in text:
                    self.click_switch_account(hwnd)
                    return
            
            # 4. 没有识别到登录文字，直接显示二维码（首次登录等情况）
            if not login_text_found:
                print("未识别到登录文字，直接显示二维码")
                self.capture_and_show_qrcode(hwnd)
            
            # 清理临时文件
            if os.path.exists(temp_path):
                os.remove(temp_path)
                
        except Exception as e:
            messagebox.showerror("错误", f"自动检测失败: {str(e)}")
    
    def click_login_button(self, hwnd):
        """点击登录按钮"""
        try:
            # 模拟点击登录按钮（在窗口中心下方约0.5个鼠标宽度）
            rect = win32gui.GetWindowRect(hwnd)
            x = (rect[0] + rect[2]) // 2
            y = (rect[1] + rect[3]) // 2 + 18  # 向下偏移约18像素（0.5个鼠标宽度）
            
            pyautogui.click(x, y)
            messagebox.showinfo("提示", "已点击登录按钮")
        except Exception as e:
            messagebox.showerror("错误", f"点击登录失败: {str(e)}")
    
    def click_switch_account(self, hwnd):
        """点击切换账户（闪烁模式：点击后立即最小化，后台轮询）"""
        try:
            # 模拟点击"切换账户"文字位置（通常在底部偏左）
            rect = win32gui.GetWindowRect(hwnd)
            x = (rect[0] + rect[2]) // 2 - 45
            y = rect[3] - 50
            
            pyautogui.click(x, y)
            
            # 立即最小化窗口（闪烁模式）
            user32.ShowWindow(hwnd, SW_MINIMIZE)
            
            # 在后台线程中轮询等待二维码出现
            def wait_for_qrcode():
                for _ in range(30):  # 最多等待3秒
                    time.sleep(0.1)
                    try:
                        # 恢复窗口截图
                        user32.ShowWindow(hwnd, SW_RESTORE)
                        
                        rect = win32gui.GetWindowRect(hwnd)
                        width = rect[2] - rect[0]
                        height = rect[3] - rect[1]
                        
                        hwndDC = win32gui.GetWindowDC(hwnd)
                        mfcDC = win32ui.CreateDCFromHandle(hwndDC)
                        saveDC = mfcDC.CreateCompatibleDC()
                        
                        saveBitMap = win32ui.CreateBitmap()
                        saveBitMap.CreateCompatibleBitmap(mfcDC, width, height)
                        saveDC.SelectObject(saveBitMap)
                        
                        ctypes.windll.user32.PrintWindow(hwnd, saveDC.GetSafeHdc(), 0)
                        
                        bmpinfo = saveBitMap.GetInfo()
                        bmpstr = saveBitMap.GetBitmapBits(True)
                        
                        im = Image.frombuffer(
                            'RGB',
                            (bmpinfo['bmWidth'], bmpinfo['bmHeight']),
                            bmpstr, 'raw', 'BGRX', 0, 1)
                        
                        win32gui.DeleteObject(saveBitMap.GetHandle())
                        saveDC.DeleteDC()
                        mfcDC.DeleteDC()
                        win32gui.ReleaseDC(hwnd, hwndDC)
                        
                        qr_size = min(width, height) - 40
                        left = (width - qr_size) // 2
                        top = (height - qr_size) // 2 - 30
                        qr_im = im.crop((left, top, left + qr_size, top + qr_size))
                        
                        if decode_qr:
                            decoded_objects = decode_qr(qr_im, symbols=[ZBarSymbol.QRCODE])
                            if decoded_objects:
                                # 显示二维码窗口并立即最小化
                                print("切换账户后识别到二维码")
                                self.root.after(0, lambda: self.capture_and_show_qrcode(hwnd))
                                time.sleep(0.1)  # 稍微等待显示完成
                                user32.ShowWindow(hwnd, SW_MINIMIZE)
                                self.enable_window_animation()
                                # 确保自动检测正在运行
                                if not hasattr(self, 'auto_detection_running') or not self.auto_detection_running:
                                    print("重新启动自动检测")
                                    self.start_auto_detection()
                                return
                        
                        # 未识别到，继续最小化等待
                        user32.ShowWindow(hwnd, SW_MINIMIZE)
                    except Exception as e:
                        print(f"等待二维码异常: {e}")
                
                # 超时，恢复动画
                self.enable_window_animation()
                self.root.after(0, lambda: messagebox.showwarning("提示", "获取二维码超时，请重试"))
            
            threading.Thread(target=wait_for_qrcode, daemon=True).start()
            
        except Exception as e:
            self.enable_window_animation()
            messagebox.showerror("错误", f"点击切换账户失败: {str(e)}")
    
    def capture_and_show_qrcode(self, hwnd):
        """截取、解码并重新显示二维码到独立窗口"""
        try:
            if not decode_qr or not qrcode:
                messagebox.showerror("错误", "请先安装二维码处理库: pip install pyzbar qrcode[pil]")
                return
            
            rect = win32gui.GetWindowRect(hwnd)
            width = rect[2] - rect[0]
            height = rect[3] - rect[1]
            
            # 截图
            hwndDC = win32gui.GetWindowDC(hwnd)
            mfcDC = win32ui.CreateDCFromHandle(hwndDC)
            saveDC = mfcDC.CreateCompatibleDC()
            
            saveBitMap = win32ui.CreateBitmap()
            saveBitMap.CreateCompatibleBitmap(mfcDC, width, height)
            saveDC.SelectObject(saveBitMap)
            
            ctypes.windll.user32.PrintWindow(hwnd, saveDC.GetSafeHdc(), 0)
            
            bmpinfo = saveBitMap.GetInfo()
            bmpstr = saveBitMap.GetBitmapBits(True)
            
            im = Image.frombuffer(
                'RGB',
                (bmpinfo['bmWidth'], bmpinfo['bmHeight']),
                bmpstr, 'raw', 'BGRX', 0, 1)
            
            win32gui.DeleteObject(saveBitMap.GetHandle())
            saveDC.DeleteDC()
            mfcDC.DeleteDC()
            win32gui.ReleaseDC(hwnd, hwndDC)
            
            # 裁剪更大的区域确保包含完整二维码
            qr_size = min(width, height) - 40
            left = (width - qr_size) // 2
            top = (height - qr_size) // 2 - 30
            qr_im = im.crop((left, top, left + qr_size, top + qr_size))
            
            # 尝试解码二维码
            decoded_objects = decode_qr(qr_im, symbols=[ZBarSymbol.QRCODE])
            
            if decoded_objects:
                qr_data = decoded_objects[0].data.decode('utf-8')
                print(f"解码二维码成功: {qr_data[:50]}...")
                
                # 重新生成二维码
                qr = qrcode.QRCode(
                    version=1,
                    error_correction=qrcode.constants.ERROR_CORRECT_H,
                    box_size=10,
                    border=4,
                )
                qr.add_data(qr_data)
                qr.make(fit=True)
                
                qr_img = qr.make_image(fill_color="black", back_color="white")
                qr_img = qr_img.convert('RGB')
                qr_img.thumbnail((300, 300))
                
                # 创建独立窗口显示二维码
                self.show_qrcode_window(qr_img)
                
                # 最小化微信窗口
                user32.ShowWindow(hwnd, SW_MINIMIZE)
                
            else:
                # 解码失败，直接显示原图
                qr_im.thumbnail((300, 300))
                self.show_qrcode_window(qr_im, "请扫描二维码登录（解码失败，显示原图）")
                
                # 最小化微信窗口
                user32.ShowWindow(hwnd, SW_MINIMIZE)
            
        except Exception as e:
            messagebox.showerror("错误", f"处理二维码失败: {str(e)}")
    
    def show_qrcode_window(self, qr_img, title="扫码登录"):
        """创建独立窗口显示二维码（置顶显示）"""
        # 如果已有窗口，先关闭
        if hasattr(self, 'qrcode_window') and self.qrcode_window.winfo_exists():
            self.qrcode_window.destroy()
        
        # 创建新窗口
        self.qrcode_window = tk.Toplevel(self.root)
        self.qrcode_window.title(title)
        self.qrcode_window.geometry("400x450")
        self.qrcode_window.resizable(False, False)
        
        # 设置窗口置顶（在所有窗口之上）
        self.qrcode_window.attributes('-topmost', True)
        
        # 居中显示
        self.qrcode_window.update_idletasks()
        x = (self.qrcode_window.winfo_screenwidth() // 2) - (400 // 2)
        y = (self.qrcode_window.winfo_screenheight() // 2) - (450 // 2)
        self.qrcode_window.geometry(f"+{x}+{y}")
        
        # 强制聚焦到二维码窗口
        self.qrcode_window.lift()
        self.qrcode_window.focus_force()
        
        # 二维码图片
        photo = ImageTk.PhotoImage(qr_img)
        qr_label = ttk.Label(self.qrcode_window, image=photo)
        qr_label.image = photo
        qr_label.pack(pady=20)
        
        # 提示文字
        ttk.Label(self.qrcode_window, text="请使用微信扫描二维码登录", 
                 font=("Arial", 12)).pack(pady=10)
        
        # 按钮区域
        btn_frame = ttk.Frame(self.qrcode_window)
        btn_frame.pack(pady=20)
        
        ttk.Button(btn_frame, text="我已扫码登录", 
                  command=lambda: self.on_qrcode_window_confirmed()).pack(side=tk.LEFT, padx=10)
        
        ttk.Button(btn_frame, text="关闭", 
                  command=self.qrcode_window.destroy).pack(side=tk.LEFT, padx=10)
        
        self.wechat_status_var.set("请扫描二维码登录")
    
    def on_qrcode_window_confirmed(self):
        """二维码窗口中确认已登录（闪烁模式：后台轮询检测）"""
        # 关闭二维码窗口
        if hasattr(self, 'qrcode_window') and self.qrcode_window.winfo_exists():
            self.qrcode_window.destroy()
        
        # 在新线程中轮询检测登录状态（后台模式）
        def check_login_loop():
            max_attempts = 50  # 最多尝试50次（40秒）
            for attempt in range(max_attempts):
                hwnd = self.get_selected_window_hwnd()
                if hwnd:
                    # 检测窗口是否最小化
                    is_minimized = user32.IsIconic(hwnd)
                    
                    # 如果最小化，先恢复窗口
                    if is_minimized:
                        user32.ShowWindow(hwnd, SW_RESTORE)
                    
                    rect = win32gui.GetWindowRect(hwnd)
                    width = rect[2] - rect[0]
                    height = rect[3] - rect[1]
                    
                    if width > 800 and height > 600:
                        # 登录成功，立即最小化窗口并更新状态
                        user32.ShowWindow(hwnd, SW_MINIMIZE)
                        self.root.after(0, lambda: self.wechat_status_var.set("已登录"))
                        self.root.after(0, lambda: messagebox.showinfo("成功", "微信登录成功"))
                        return
                    else:
                        # 未登录，继续最小化
                        user32.ShowWindow(hwnd, SW_MINIMIZE)
                
                # 未登录成功，等待0.8秒后继续检测
                time.sleep(0.8)
            
            # 超过最大尝试次数，确保窗口最小化并提示用户
            hwnd = self.get_selected_window_hwnd()
            if hwnd:
                user32.ShowWindow(hwnd, SW_MINIMIZE)
            self.root.after(0, lambda: self.wechat_status_var.set("登录检测超时，请手动检查"))
            self.root.after(0, lambda: messagebox.showwarning("提示", "登录检测超时，请手动检查微信是否已登录"))
        
        threading.Thread(target=check_login_loop, daemon=True).start()
    
    def show_login_confirm_button(self):
        """显示登录确认按钮"""
        # 如果已存在则先销毁
        if hasattr(self, 'login_confirm_btn') and self.login_confirm_btn.winfo_exists():
            self.login_confirm_btn.destroy()
        
        # 创建确认登录按钮
        self.login_confirm_btn = ttk.Button(self.qrcode_label.master, text="我已扫码登录", 
                                           command=self.on_login_confirmed)
        self.login_confirm_btn.grid(row=1, column=0, padx=5, pady=5)
    
    def on_login_confirmed(self):
        """用户确认已登录"""
        # 销毁确认按钮
        if hasattr(self, 'login_confirm_btn') and self.login_confirm_btn.winfo_exists():
            self.login_confirm_btn.destroy()
        
        # 检测登录状态
        hwnd = self.get_selected_window_hwnd()
        if hwnd:
            rect = win32gui.GetWindowRect(hwnd)
            width = rect[2] - rect[0]
            height = rect[3] - rect[1]
            
            if width > 800 and height > 600:
                self.wechat_status_var.set("已登录")
                self.qrcode_label.config(text="登录成功", image="")
                messagebox.showinfo("成功", "微信登录成功")
            else:
                self.wechat_status_var.set("登录可能未完成，请检查")
        else:
            self.wechat_status_var.set("未找到微信窗口")
    
    def open_service_account(self):
        """自动打开服务号（闪烁模式：极快操作后立即最小化）"""
        try:
            hwnd = self.get_selected_window_hwnd()
            if not hwnd:
                messagebox.showerror("错误", "未选择微信窗口，请先在窗口选择下拉框中选择微信窗口")
                return
            
            # 检测窗口是否最小化
            was_minimized = user32.IsIconic(hwnd)
            
            # 禁用动画实现瞬间操作
            self.disable_window_animation()
            
            # 如果最小化，先恢复窗口
            if was_minimized:
                user32.ShowWindow(hwnd, SW_RESTORE)
            
            # 确保窗口在前台
            win32gui.SetForegroundWindow(hwnd)
            
            # 点击搜索暗纹词（搜索图标右侧约30像素）
            rect = win32gui.GetWindowRect(hwnd)
            search_x = rect[0] + 130
            search_y = rect[1] + 40
            
            pyautogui.click(search_x, search_y)
            
            # 根据当前支付类型选择服务号
            wechat_type = self.wechat_type_var.get()
            if wechat_type == "收款助手":
                service_name = "微信收款助手"
            else:  # 赞赏码
                service_name = "微信支付"
            
            # 使用复制粘贴方式输入中文
            import pyperclip
            pyperclip.copy(service_name)
            
            # 粘贴
            pyautogui.keyDown('ctrl')
            pyautogui.keyDown('v')
            pyautogui.keyUp('v')
            pyautogui.keyUp('ctrl')
            
            # 按Enter键直接打开第一个搜索结果
            pyautogui.keyDown('return')
            pyautogui.keyUp('return')
            
            # 等待服务号窗口打开（短暂延迟）
            time.sleep(0.5)
            
            # 立即最小化主窗口（闪烁模式，用户几乎看不到）
            user32.ShowWindow(hwnd, SW_MINIMIZE)
            
            # 查找并最小化服务号窗口
            self.minimize_service_account_window(service_name)
            
            # 恢复动画设置
            self.enable_window_animation()
            
            messagebox.showinfo("成功", f"已尝试打开服务号: {service_name}")
            
        except Exception as e:
            self.enable_window_animation()
            messagebox.showerror("错误", f"打开服务号失败: {str(e)}")
    
    def minimize_service_account_window(self, service_name):
        """查找并最小化服务号窗口"""
        try:
            # 刷新窗口列表
            self.windows = self.get_active_windows()
            
            # 查找服务号窗口
            for window_title in self.windows:
                if service_name in window_title:
                    # 找到服务号窗口，获取句柄并最小化
                    windows = gw.getWindowsWithTitle(window_title)
                    if windows:
                        service_hwnd = windows[0]._hWnd
                        user32.ShowWindow(service_hwnd, SW_MINIMIZE)
                        print(f"已最小化服务号窗口: {window_title}")
                        break
        except Exception as e:
            print(f"最小化服务号窗口失败: {e}")
    
    def find_search_popup_window(self, parent_hwnd):
        """查找搜索弹出框窗口"""
        try:
            popup_windows = []
            
            def enum_callback(hwnd, extra):
                if win32gui.IsWindowVisible(hwnd):
                    # 获取窗口位置和大小
                    try:
                        rect = win32gui.GetWindowRect(hwnd)
                        width = rect[2] - rect[0]
                        height = rect[3] - rect[1]
                        title = win32gui.GetWindowText(hwnd)
                        
                        # 搜索弹出框特征：
                        # 1. 宽度约250-350像素
                        # 2. 高度约300-500像素
                        # 3. 标题可能为空或包含搜索关键词
                        # 4. 位置在主窗口左侧附近
                        if 200 < width < 400 and 200 < height < 600:
                            # 检查是否是微信相关的窗口类
                            class_name = win32gui.GetClassName(hwnd)
                            if "WeChat" in class_name or "Weixin" in class_name:
                                popup_windows.append((hwnd, rect, title))
                    except:
                        pass
            
            # 枚举所有窗口
            win32gui.EnumWindows(enum_callback, None)
            
            if popup_windows:
                # 返回找到的第一个符合条件的窗口
                return popup_windows[0][0]
            
            return None
        except Exception as e:
            print(f"查找搜索弹出框失败: {e}")
            return None
    
    def find_and_click_service_account_from_popup(self, popup_hwnd, service_name, search_x):
        """从搜索弹出框中识别并点击服务号"""
        try:
            # 获取弹出框位置
            rect = win32gui.GetWindowRect(popup_hwnd)
            popup_x = rect[0]
            popup_y = rect[1]
            popup_width = rect[2] - rect[0]
            popup_height = rect[3] - rect[1]
            
            # 截图弹出框
            hwndDC = win32gui.GetWindowDC(popup_hwnd)
            mfcDC = win32ui.CreateDCFromHandle(hwndDC)
            saveDC = mfcDC.CreateCompatibleDC()
            
            saveBitMap = win32ui.CreateBitmap()
            saveBitMap.CreateCompatibleBitmap(mfcDC, popup_width, popup_height)
            saveDC.SelectObject(saveBitMap)
            
            ctypes.windll.user32.PrintWindow(popup_hwnd, saveDC.GetSafeHdc(), 0)
            
            bmpinfo = saveBitMap.GetInfo()
            bmpstr = saveBitMap.GetBitmapBits(True)
            
            im = Image.frombuffer(
                'RGB',
                (bmpinfo['bmWidth'], bmpinfo['bmHeight']),
                bmpstr, 'raw', 'BGRX', 0, 1)
            
            win32gui.DeleteObject(saveBitMap.GetHandle())
            saveDC.DeleteDC()
            mfcDC.DeleteDC()
            win32gui.ReleaseDC(popup_hwnd, hwndDC)
            
            # 保存截图用于调试
            temp_path = "search_popup_debug.png"
            im.save(temp_path)
            print(f"搜索弹出框截图已保存: {temp_path}")
            
            # 点击第一个搜索结果（在弹出框内）
            # 第一个结果通常在弹出框顶部下方约60像素处
            click_x = popup_x + popup_width // 2
            click_y = popup_y + 60
            
            # 确保窗口在前台
            win32gui.SetForegroundWindow(popup_hwnd)
            time.sleep(0.3)
            
            # 点击
            pyautogui.click(click_x, click_y)
            time.sleep(0.5)
            pyautogui.click(click_x, click_y)
            
            messagebox.showinfo("成功", f"已打开服务号: {service_name}")
            
        except Exception as e:
            print(f"从弹出框点击服务号失败: {e}")
            # 失败时使用原来的方式
            self.find_and_click_service_account(None, service_name, search_x)
    
    def find_and_click_service_account(self, hwnd, service_name, search_x):
        """识别并点击服务号"""
        try:
            # 获取窗口位置
            rect = win32gui.GetWindowRect(hwnd)
            
            # 使用相同的X坐标（搜索框的X坐标），只垂直向下移动Y坐标
            # 搜索框Y坐标约40像素，服务号结果在下方约120像素处
            click_x = search_x  # 保持相同的X坐标
            click_y = rect[1] + 120  # 搜索框下方约120像素
            
            # 先移动鼠标到目标位置，确保位置正确
            pyautogui.moveTo(click_x, click_y, duration=0.2)
            time.sleep(0.3)
            
            # 点击第一个搜索结果
            pyautogui.click(click_x, click_y)
            time.sleep(1)
            
            # 再次点击确认（确保点击成功）
            pyautogui.click(click_x, click_y)
            
            messagebox.showinfo("成功", f"已打开服务号: {service_name}")
                
        except Exception as e:
            messagebox.showerror("错误", f"查找服务号失败: {str(e)}")

if __name__ == "__main__":
    root = tk.Tk()
    app = OCRApp(root)
    root.mainloop()
