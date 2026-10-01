# -*- coding: utf-8 -*-
# 检查 code-page-card / crop-stage 规则的原始字符（找非法字符）
import io, sys, re, unicodedata
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()

for cls in ['code-page-card', 'crop-stage', 'quick-bar']:
    i = c.find('.' + cls + ' {')
    if i < 0:
        i = c.find('.' + cls + '{')
    if i < 0:
        print(cls, ': 选择器未找到'); continue
    end = c.find('}', i)
    seg = c[i:end+1]
    print('===', cls, '===')
    print(repr(seg[:300]))
    # 非常规字符检查
    weird = [(k, ch, hex(ord(ch))) for k, ch in enumerate(seg) if ord(ch) > 127]
    if weird:
        print('  非ASCII字符:', weird[:10])
    # 花括号
    print('  花括号:', seg.count('{'), '/', seg.count('}'))
    print()
