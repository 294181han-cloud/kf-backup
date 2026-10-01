# -*- coding: utf-8 -*-
# 对比服务器文件与本地版本
import io, sys, urllib.request
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# FTP 下载服务器 public/app.html
import base64
ftp_req = urllib.request.Request('ftp://ttb:hYSat8CXjPCXxdx7@23.224.141.164/public/app.html')
try:
    srv_file = urllib.request.urlopen(ftp_req).read().decode('utf-8', errors='ignore')
    print("服务器 public/app.html:", len(srv_file), "字符")
    print("  CSS 规则: .code-page-card { =", "'.code-page-card {'" in srv_file or '.code-page-card {' in srv_file)
    print("  含 CSS 规则(搜 code-page-card {):", "code-page-card {" in srv_file)
except Exception as e:
    print("FTP 下载失败:", e)

# 本地 HEAD 版本
local = open(r"D:\kfxt\kf\public\app.html", encoding="utf-8").read()
print("本地 app.html:", len(local), "字符")
print("  CSS 规则:", "code-page-card {" in local)

# HTTP 响应对比
http = urllib.request.urlopen('https://kf.ylcz.cc/app.html').read().decode('utf-8', errors='ignore')
print("HTTP 响应:", len(http), "字符")
print("  CSS 规则:", "code-page-card {" in http)
