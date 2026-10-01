# -*- coding: utf-8 -*-
import io, sys
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
print("'.code-page-card {' in c:", '.code-page-card {' in c)
print("'.code-page-card' in c:", '.code-page-card' in c)
print("'.crop-stage {' in c:", '.crop-stage {' in c)
print("'.crop-stage' in c:", '.crop-stage' in c)
# 找出所有出现位置
for kw in ['.code-page-card', '.crop-stage']:
    i = 0; n = 0
    while True:
        i = c.find(kw, i)
        if i < 0: break
        n += 1
        line = c[:i].count('\n') + 1
        print(f"  {kw} 出现#{n} 行{line}: {c[i:i+60].split(chr(10))[0]}")
        i += len(kw)
