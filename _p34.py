# -*- coding: utf-8 -*-
# 补齐 CSS（正确判定：用 CSS 注释做存在性判定）+ git diff 验证
import io, sys
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

f = r"D:\kfxt\kf\public\app.html"
c = open(f, encoding="utf-8").read()

# 存在性判定：CSS 专属注释（模板中不存在）
marker = "/* ===== 获客码页（对标截图） ===== */"
if marker in c:
    print("[SKIP] CSS 已存在")
    sys.exit(0)

missing_css = '''
    /* ===== 获客码页（对标截图） ===== */
    .code-page-card { background: #fff; border-radius: 14px; padding: 24px; border: 1px solid #eeeaea; }
    .code-tabs { display: flex; justify-content: center; border-bottom: 1px solid #eeeaea; margin-bottom: 24px; }
    .code-tab { padding: 12px 28px; font-size: 15px; cursor: pointer; color: #666; position: relative; }
    .code-tab.active { color: #1890ff; font-weight: 600; }
    .code-tab.active::after { content: ''; position: absolute; left: 20%; right: 20%; bottom: -1px; height: 2px; background: #1890ff; }
    .code-body { display: flex; gap: 24px; flex-wrap: wrap; }
    .code-img-panel, .code-set-panel { flex: 1; min-width: 300px; background: #f8fafcd2; border-radius: 12px; padding: 16px; }
    .cip-title { font-size: 15px; font-weight: 600; margin-bottom: 14px; }
    .qr-stage { background: #fff; border: 1px solid #eeeaea; border-radius: 10px; padding: 14px; text-align: center; }
    .qr-headline { color: #1890ff; font-weight: 600; margin-bottom: 8px; }
    .qr-wrap { position: relative; display: inline-block; }
    .qr-wrap img { width: 210px; height: 210px; display: block; }
    .qr-logo {
      position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
      width: 46px; height: 46px; border-radius: 10px; background: #fff;
      display: flex; align-items: center; justify-content: center; font-size: 26px;
      box-shadow: 0 0 0 4px #fff;
    }
    .qr-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
    .qr-footnote { font-size: 11px; color: #999; margin-top: 8px; }
    .cip-btns { text-align: center; margin-top: 12px; }
    .cip-note { color: #f53f3f; font-size: 12px; text-align: center; margin-top: 10px; }
    .cs-row { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .cs-label { width: 66px; color: #666; font-size: 13px; flex-shrink: 0; }
    .cs-btns { display: flex; justify-content: space-between; margin-top: 20px; }
    /* ===== 头像裁剪器 ===== */
    .crop-stage {
      width: 320px; height: 320px; background: #26292e; position: relative;
      overflow: hidden; border-radius: 10px; cursor: grab; touch-action: none;
      user-select: none;
    }
    .crop-stage:active { cursor: grabbing; }
    .crop-img { position: absolute; transform-origin: 0 0; pointer-events: none; }
    .crop-box {
      position: absolute; left: 50%; top: 50%; width: 200px; height: 200px;
      transform: translate(-50%, -50%);
      border: 2px solid #1890ff; border-radius: 8px;
      box-shadow: 0 0 0 9999px rgba(0,0,0,.55);
      pointer-events: none;
    }
    /* ===== 快捷条：布局内占位，位于工具栏上方 ===== */'''

anchor = "    /* ===== 快捷条：布局内占位（不遮挡），位于工具栏上方，向右展开 ===== */"
if anchor in c:
    c = c.replace(anchor, missing_css, 1)
    print("[OK] CSS 已插入（锚点替换）")
else:
    # 兜底：插在 </style> 前
    c = c.replace('</style>', missing_css + '\n</style>', 1)
    print("[OK] CSS 已插入（</style> 前）")

open(f, "w", encoding="utf-8").write(c)
