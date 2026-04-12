import hashlib
import time
import webbrowser
from urllib.parse import urlencode, urlsplit, urlunsplit

import requests


def build_sign(params, merchant_key: str) -> str:
    items = []
    for key in sorted(params.keys()):
        if key in {"sign", "sign_type"}:
            continue
        value = params[key]
        if value is not None and value != "":
            items.append(f"{key}={value}")
    sign_string = "&".join(items) + merchant_key
    return hashlib.md5(sign_string.encode("utf-8")).hexdigest()


def prompt(prompt_text: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    value = input(f"{prompt_text}{suffix}: ").strip()
    return value or default


def normalize_submit_url(raw_value: str) -> tuple[str, str]:
    submit_url = raw_value.strip()
    if not submit_url:
        raise ValueError("提交订单接口地址不能为空")

    if submit_url.endswith("/"):
        submit_url = submit_url.rstrip("/")

    if not submit_url.lower().endswith("/submit.php"):
        submit_url = submit_url + "/submit.php"

    parsed = urlsplit(submit_url)
    if not parsed.scheme or not parsed.netloc:
        raise ValueError("请输入完整的 http:// 或 https:// 地址")

    base_path = parsed.path[: -len("/submit.php")]
    base_url = urlunsplit((parsed.scheme, parsed.netloc, base_path, "", ""))
    return submit_url, base_url.rstrip("/")


def build_order_params(
    merchant_id: str,
    merchant_key: str,
    base_url: str,
    pay_type: str,
    money: str,
) -> dict[str, str]:
    timestamp = time.strftime("%Y%m%d%H%M%S")
    out_trade_no = f"PYTEST_OUT_{timestamp}{int(time.time() * 1000) % 10000:04d}"

    params = {
        "pid": merchant_id,
        "type": pay_type,
        "out_trade_no": out_trade_no,
        "notify_url": f"{base_url}/test_notify.php",
        "return_url": f"{base_url}/test_success.php?out_trade_no={out_trade_no}",
        "name": "Python测试支付商品",
        "money": f"{float(money):.2f}",
        "sign_type": "MD5",
    }
    params["sign"] = build_sign(params, merchant_key)
    return params


def main():
    submit_url, base_url = normalize_submit_url(
        prompt(
            "提交订单接口地址(可填 base URL 或完整 submit.php 地址)",
            "http://127.0.0.1/bpay/submit.php",
        )
    )
    merchant_id = prompt("商户ID", "1000")
    merchant_key = prompt("商户密钥")
    if not merchant_key:
        raise ValueError("商户密钥不能为空")

    money = prompt("支付金额", "0.01")
    pay_type = prompt("支付方式(alipay/wxpay)", "alipay").strip().lower()
    if pay_type not in {"alipay", "wxpay"}:
        raise ValueError("支付方式只能是 alipay 或 wxpay")

    params = build_order_params(merchant_id, merchant_key, base_url, pay_type, money)

    print("\n即将提交到:")
    print(submit_url)
    print("\n表单参数:")
    for key, value in params.items():
        print(f"{key}={value}")
    print("\n表单编码体:")
    print(urlencode(params))

    response = requests.post(
        submit_url,
        data=params,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        allow_redirects=False,
        timeout=15,
    )

    print(f"\nsubmit.php 响应状态: {response.status_code}")

    if response.is_redirect:
        location = response.headers.get("Location", "")
        if location.startswith(("http://", "https://")):
            pay_url = location
        else:
            pay_url = f"{base_url}/{location.lstrip('/')}"

        print(f"商户订单号: {params['out_trade_no']}")
        print(f"支付页面: {pay_url}")

        should_open = prompt("是否自动打开支付页面(y/n)", "n").lower()
        if should_open == "y":
            webbrowser.open(pay_url)
        return

    print("响应内容:")
    print(response.text)


if __name__ == "__main__":
    main()
