# -*- coding: utf-8 -*-
import io, sys, re
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()
ok = 0

# 1. 删除 pollOnce 内残缺重复的 unreadTotal 行
js = re.search(r'<script>\n([\s\S]*?)\n</script>\s*</body>', c).group(1)
bad_line = None
for m in re.finditer(r'const unreadTotal', js):
    ctx = js[max(0, m.start()-80):m.end()+80]
    if 'Vue.computed' not in ctx.split('\n')[-1]:
        # 找到该行的完整内容
        line_start = js.rfind('\n', 0, m.start()) + 1
        line_end = js.find('\n', m.end())
        bad_line = js[line_start:line_end]
        js_line_start_abs = len(re.search(r'<script>\n', c).group(0)) + line_start + 1
        break
if bad_line:
    print("删除残缺行:", bad_line.strip()[:100])
    c = c.replace('\n' + bad_line, '', 1)
    ok += 1
else:
    print("未找到残缺 unreadTotal 行")

# 2. 侧边栏宽度缩小（212 -> 188，折叠 60 -> 54）
before = c
c = c.replace('width: 212px;', 'width: 188px;')
c = c.replace('.side.collapsed { width: 60px; }', '.side.collapsed { width: 54px; }')
if c != before: ok += 1; print("[OK] 侧栏缩小")

# 3. 全局错误提示条（排障用：任何 JS 错误显示在页面顶部红条）
err_hook = '''<script>
window.addEventListener('error', function (ev) {
  try {
    var bar = document.getElementById('global-err-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'global-err-bar';
      bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#f56c6c;color:#fff;font-size:12px;padding:6px 12px;word-break:break-all;';
      (document.body || document.documentElement).appendChild(bar);
    }
    bar.textContent = 'JS错误: ' + (ev.message || '') + ' @ ' + (ev.filename || '') + ':' + (ev.lineno || '');
  } catch (e) {}
});
window.addEventListener('unhandledrejection', function (ev) {
  try {
    var bar = document.getElementById('global-err-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'global-err-bar';
      bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#f56c6c;color:#fff;font-size:12px;padding:6px 12px;word-break:break-all;';
      (document.body || document.documentElement).appendChild(bar);
    }
    bar.textContent = 'Promise错误: ' + ((ev.reason && ev.reason.message) || ev.reason || '');
  } catch (e) {}
});
</script>
</body>'''
if 'global-err-bar' not in c:
    c = c.replace('</body>', err_hook, 1)
    ok += 1; print("[OK] 全局错误条")

open(f, "w", encoding="utf-8").write(c)
print(f"\n完成: {ok}/3")
