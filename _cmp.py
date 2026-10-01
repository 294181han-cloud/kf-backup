# -*- coding: utf-8 -*-
import io, sys, urllib.request
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

srv = urllib.request.urlopen(urllib.request.Request('https://kf.ylcz.cc/app.html')).read().decode()
local = open(r"D:\kfxt\kf\public\app.html", encoding="utf-8").read()
print("服务器:", len(srv), "字符")
print("本地:  ", len(local), "字符")
print()
print("=== 特征对比 ===")
for k in ['code-page-card', '码图像', '管理码 - 历史记录', '.code-page-card {', 'hero-avatar', 'geo-tag', 'sess-collapse-btn']:
    print(f"  {k}: 服务器={srv.count(k)}次 本地={local.count(k)}次")
print()
# 服务器版本特征（找头部注释/标题）
import re
ti = re.search(r'<title>([^<]*)</title>', srv)
print("服务器 title:", ti.group(1) if ti else '-')
# 服务器是否含本地没有的段落（取服务器前几个注释）
notes = re.findall(r'<!--\s*=+\s*([^=]+?)\s*=+\s*-->', srv)
print("服务器分区注释:", notes[:15])
