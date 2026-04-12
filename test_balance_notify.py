import hashlib
from datetime import datetime
from urllib.parse import urlsplit, urlunsplit

import requests


def prompt(prompt_text: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    value = input(f"{prompt_text}{suffix}: ").strip()
    return value or default


def normalize_notify_url(raw_value: str) -> str:
    notify_url = raw_value.strip()
    if not notify_url:
        raise ValueError("通知接口地址不能为空")

    if notify_url.endswith("/"):
        notify_url = notify_url.rstrip("/")

    if not notify_url.lower().endswith("/api/balance_notify.php"):
        notify_url = notify_url + "/api/balance_notify.php"

    parsed = urlsplit(notify_url)
    if not parsed.scheme or not parsed.netloc:
        raise ValueError("请输入完整的 http:// 或 https:// 地址")

    return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))


def build_sign(params: dict[str, str]) -> str:
    items = []
    for key in sorted(params.keys()):
        if key == "sign":
            continue
        value = params[key]
        if value is not None and value != "":
            items.append(f"{key}={value}")
    sign_string = "&".join(items) + "qwer"
    return hashlib.md5(sign_string.encode("utf-8")).hexdigest()


def main():
    notify_url = normalize_notify_url(
        prompt(
            "支付成功通知接口地址(可填 base URL 或完整 balance_notify.php 地址)",
            "http://127.0.0.1/bpay/api/balance_notify.php",
        )
    )
    amount = prompt("到账金额", "0.01")
    payment_type = prompt("支付方式(alipay/wxpay，可留空)", "alipay").strip().lower()
    change_time = prompt("到账时间", datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
    current_balance = prompt("当前余额(可留空)", "")

    payload = {
        "change_amount": f"{float(amount):.2f}",
        "change_time": change_time,
    }
    if payment_type:
        payload["payment_type"] = payment_type
    if current_balance:
        payload["current_balance"] = current_balance

    payload["sign"] = build_sign(payload)

    print("\n即将提交到:")
    print(notify_url)
    print("\nJSON 参数:")
    print(payload)

    response = requests.post(notify_url, json=payload, timeout=15)

    print(f"\n响应状态: {response.status_code}")
    print("响应内容:")
    print(response.text)


if __name__ == "__main__":
    main()
