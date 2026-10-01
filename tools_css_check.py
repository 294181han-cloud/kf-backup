# -*- coding: utf-8 -*-
import io, sys
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
print("=== 获客码页模板状态 ===")
print("  tab==='links':", "tab==='links'" in c)
print("  码图像:", "码图像" in c)
print("  码设置:", "码设置" in c)
print("  更换码:", "更换码" in c)
print("  管理码:", "管理码" in c)
print("  获客码注释:", "获客码（统一码）" in c)
print("=== CSS 状态 ===")
for k in ["code-page-card", "code-tabs", "code-body", "cip-title", "qr-stage", "cs-row", "crop-stage", "crop-box", "quick-bar", "quick-btn"]:
    print(f"  {k}:", k in c)
# 获客码注释位置定位
i = c.find("获客码")
if i >= 0:
    print("\n获客码 首次出现 行:", c[:i].count('\n') + 1)
    print(c[max(0,i-100):i+200])
