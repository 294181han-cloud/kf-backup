# -*- coding: utf-8 -*-
# app.html <style> 块花括号平衡检查（逐行深度追踪，找失衡点）
import io, sys, re
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
m = re.search(r'<style>\n([\s\S]*?)\n</style>', c)
css = m.group(1)
print("CSS 长度:", len(css), " 花括号:", css.count('{'), "/", css.count('}'))

lines = css.split('\n')
depth = 0
issues = []
for i, line in enumerate(lines):
    # 去掉字符串与注释干扰（CSS 无字符串大括号场景，直接计数）
    o = line.count('{'); cl = line.count('}')
    if o != cl:
        depth += o - cl
        print(f"  行{i+1} depth={depth}: {line.strip()[:100]}")
    if depth < 0:
        issues.append(i+1)
        depth = 0
print("最终 depth:", depth)
