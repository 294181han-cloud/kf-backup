/* qr-poster.js — 客服码美化海报渲染器
 * 依赖：全局 QRCode（vendor/qrcode.min.js）
 * 提供：CSQrPoster.draw(canvas, link, opts) 绘制圆角 3:4 二维码海报
 */
(function (global) {
  'use strict';

  // 纠错等级映射：低/中/高/最高
  var LV = { low: 1, medium: 0, high: 3, max: 2 }; // 对应 QRCode.CorrectLevel.L/M/Q/H

  function hexToRgba(hex, a) {
    var h = String(hex || '#000000').replace('#', '');
    if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
    var num = parseInt(h, 16);
    if (isNaN(num)) return 'rgba(0,0,0,' + a + ')';
    var r = (num >> 16) & 255, g = (num >> 8) & 255, b = num & 255;
    return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
  }

  function boxRadius(r) { return 'M' + r + ' 0 H' + (1) + ' V' + (0) + 'H 0 Z'; }
  function roundRect(ctx, x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
  }

  // 解析颜色：纯色直接返回，渐变返回线性渐变对象
  function fillColor(ctx, opts, x0, y0, x1, y1) {
    if (opts.colorMode === 'gradient') {
      var g = ctx.createLinearGradient(x0, y0, x1, y1);
      g.addColorStop(0, opts.colorGrad1 || '#1677ff');
      g.addColorStop(1, opts.colorGrad2 || '#3c8dff');
      return g;
    }
    return opts.colorMain || '#1677ff';
  }

  /**
   * 生成二维码模块矩阵 + 码点/码眼识别
   * @return { dark:(r,c)=>bool, count:int, isFinder:(r,c)=>bool, isData:(r,c)=>bool }
   */
  function buildMatrix(link, lvl) {
    var host = document.createElement('div');
    host.style.cssText = 'position:absolute;left:-9999px;';
    document.body.appendChild(host);
    var q;
    try {
      q = new QRCode(host, { text: link, correctLevel: lvl, width: 120, height: 120 });
    } finally {
      host.innerHTML = ''; document.body.removeChild(host);
    }
    var m = q && q._oQRCode;
    if (!m) throw new Error('二维码生成失败');
    var count = m.getModuleCount();
    return {
      count: count,
      dark: function (r, c) { return m.isDark(r, c); },
      isFinder: function (r, c) {
        return (r < 7 && c < 7) || (r < 7 && c >= count - 7) || (r >= count - 7 && c < 7);
      },
      isData: function (r, c) { return !(r < 9 && c < 9) && !(r < 9 && c >= count - 8) && !(r >= count - 8 && c < 9); }
    };
  }

  /**
   * 绘制海报
   * opts: {
   *   size: number(码边长) default 230,
   *   widthPct: 0..1(码占卡片宽) default 0.68,
   *   correctLevel: 'low'|'medium'|'high'|'max',
   *   dotStyle: 'square'|'round'|'rounded'|'stripe',
   *   finderStyle: 'square'|'rounded'|'circle',
   *   colorMode: 'solid'|'gradient',
   *   colorMain, colorGrad1, colorGrad2, colorBg,
   *   logoDataUrl, logoShape: 'raw'|'rounded'|'circle', logoShadow: 'on'|'off',
   *   title: 标题文案, footer: 底部文案
   * }
   */
  function draw(canvas, link, opts, done) {
    opts = opts || {};
    if (opts.style === 'wechat') return drawWechat(canvas, link, opts, done);
    if (opts.style === 'ticket') return drawTicket(canvas, link, opts, done);
    var qsize = Number(opts.size) || 230;
    var pad = 22;                 // 左右留白
    var codeArea = qsize;         // 二维码边长
    var W = codeArea + pad * 2;   // 卡片宽 = 码 + 左右边距
    var titleH = (opts.title && String(opts.title).trim()) ? 26 : 0; // 上方可选小标题
    var barH = 54;                // 底部绿色长条高度
    var cx = pad;                 // 二维码左偏移（居中）
    var H = pad + titleH + codeArea + barH; // 顶部留白 + 标题 + 码 + 底部条

    // 位置
    var titleTop = 12;
    var codeY = pad + titleH;             // 二维码起始 Y
    var barY = H - barH;                  // 底部长条 Y

    canvas.width = W; canvas.height = H;
    var ctx = canvas.getContext('2d');

    // 背景（白卡片）
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = '#ffffff';
    roundRect(ctx, 0, 0, W, H, 20); ctx.fill();

    // 上方标题（小字居中，类似收款方名称）
    if (titleH > 0 && (opts.title || '').trim()) {
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = '#1f2533';
      ctx.font = '15px system-ui, -apple-system, "PingFang SC", sans-serif';
      ctx.fillText(String(opts.title), W / 2, codeY - 14);
    }

    // ---- 绘制二维码模块 ----
    var m = buildMatrix(link, LV[opts.correctLevel] || 1);
    var count = m.count;
    var cell = codeArea / count;
    var x0 = cx, y0 = codeY;
    var color = fillColor(ctx, opts, x0, y0, x0 + codeArea, y0 + codeArea);

    function drawCell(r, c, style) {
      var x = x0 + c * cell, y = y0 + r * cell;
      var m2 = cell * 0.92, off = (cell - m2) / 2;
      ctx.fillStyle = color;
      if (style === 'round' || style === 'circle') {
        ctx.beginPath(); ctx.arc(x + cell / 2, y + cell / 2, m2 / 2, 0, Math.PI * 2); ctx.fill();
      } else if (style === 'rounded') {
        roundRect(ctx, x + off, y + off, m2, m2, cell * 0.3); ctx.fill();
      } else if (style === 'stripe') {
        ctx.fillRect(x, y + cell * 0.3, cell, cell * 0.4); // 横条纹
      } else {
        ctx.fillRect(x + off, y + off, m2, m2);
      }
    }

    // 定位图案（码眼）先画，再画普通码点
    function drawFinder(r, c) {
      if (!m.dark(r, c)) return;
      var x = x0 + c * cell, y = y0 + r * cell;
      // 7x7 定位图案：画外框 + 中心
      // 简化：把整个 finder 7x7 当方块按样式画关键环
      ctx.fillStyle = color;
      if (opts.finderStyle === 'circle') {
        roundRect(ctx, x, y, 7 * cell, 7 * cell, 7 * cell / 2); ctx.fill();
        // 中心点
        ctx.fillStyle = opts.colorMain || color; ctx.beginPath();
        ctx.arc(x + 3.5 * cell, y + 3.5 * cell, cell, 0, Math.PI * 2); ctx.fill();
        if (opts.colorMode === 'gradient' && opts.colorMain) ctx.fillStyle = color;
      } else if (opts.finderStyle === 'rounded') {
        roundRect(ctx, x, y, 7 * cell, 7 * cell, cell * 1.6); ctx.fill();
        roundRect(ctx, x + cell, y + cell, 5 * cell, 5 * cell, cell); ctx.fillStyle = opts.colorBg || '#fff'; ctx.fill();
        ctx.fillStyle = color; roundRect(ctx, x + 2 * cell, y + 2 * cell, 3 * cell, 3 * cell, cell); ctx.fill();
      } else {
        ctx.fillRect(x, y, 7 * cell, 7 * cell);
        ctx.fillStyle = opts.colorBg || '#fff'; ctx.fillRect(x + cell, y + cell, 5 * cell, 5 * cell);
        ctx.fillStyle = color; ctx.fillRect(x + 2 * cell, y + 2 * cell, 3 * cell, 3 * cell);
      }
    }

    // 先画三个码眼的背景环，使其优先
    for (var r = 0; r < count; r++) for (var c = 0; c < count; c++) {
      if (m.isFinder(r, c) && m.dark(r, c) && (r === 0 || c === 0 || r === count - 7 || c === count - 7)) {}
    }
    // 码眼整体处理（三个 7x7，防止逐点样式打破外框）
    var i;
    // 主码眼绘制（外框方形环，按 finderStyle 处理外环 + 内点）
    // 这里用统一方式：三个定位区域各画外环（方形/圆角/圆形）+中心点
    var finderCells = [
      [0, 0], [0, count - 7], [count - 7, 0]
    ];
    for (i = 0; i < finderCells.length; i++) {
      drawFinder(finderCells[i][0], finderCells[i][1]);
    }

    // 其余数据码点按码点样式画
    for (r = 0; r < count; r++) for (c = 0; c < count; c++) {
      if (!m.isFinder(r, c) && m.dark(r, c)) drawCell(r, c, opts.dotStyle);
    }

    // ---- Logo 遮罩 ----
    if (opts.logoDataUrl) {
      var lw = Math.round(codeArea * 0.22), lh = lw;
      var lx = cx + (codeArea - lw) / 2, ly = y0 + (codeArea - lh) / 2;
      // 白底衬
      ctx.fillStyle = opts.colorBg || '#fff';
      ctx.shadowColor = opts.logoShadow === 'on' || opts.logoShadow == null ? 'rgba(0,0,0,0.2)' : 'transparent';
      ctx.shadowBlur = opts.logoShadow === 'on' || opts.logoShadow == null ? 8 : 0;
      if (opts.logoShape === 'circle') { ctx.beginPath(); ctx.arc(lx + lw / 2, ly + lh / 2, lw / 2, 0, Math.PI * 2); ctx.fill(); }
      else if (opts.logoShape === 'rounded') { roundRect(ctx, lx, ly, lw, lh, lw * 0.22); ctx.fill(); }
      else { ctx.fillRect(lx, ly, lw, lh); }
      ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0;
      var img = new Image();
      img.onload = function () {
        if (opts.logoShape === 'circle') { ctx.save(); ctx.beginPath(); ctx.arc(lx + lw / 2, ly + lh / 2, lw / 2, 0, Math.PI * 2); ctx.clip(); ctx.drawImage(img, lx, ly, lw, lh); ctx.restore(); }
        else if (opts.logoShape === 'rounded') { ctx.save(); roundRect(ctx, lx, ly, lw, lh, lw * 0.22); ctx.clip(); ctx.drawImage(img, lx, ly, lw, lh); ctx.restore(); }
        else { ctx.drawImage(img, lx, ly, lw, lh); }
        drawFooter();
        if (done) done();
      };
      img.onerror = function () { drawFooter(); if (done) done(); };
      img.src = opts.logoDataUrl;
    } else {
      drawFooter();
      if (done) done();
    }

    function drawFooter() {
      // 底部绿色（主色）圆角长条，与微信付款码风格接近
      var mm = 8, bh = barH - 12, by = H - bh - (barH - bh) / 2;
      var barColor = opts.colorMode === 'gradient'
        ? fillColor(ctx, opts, 0, by, W, by + bh)
        : (opts.colorMain || '#07C160');
      ctx.fillStyle = barColor;
      roundRect(ctx, mm, by, W - mm * 2, bh, 12); ctx.fill();
      // 白色文字
      ctx.fillStyle = '#ffffff';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.font = '16px system-ui, -apple-system, "PingFang SC", sans-serif';
      ctx.fillText(String(opts.footer || '微信官方客服'), W / 2, by + bh / 2);
    }
  }

  global.CSQrPoster = { draw: draw, buildMatrix: buildMatrix };

  /* ================= 风格预设（v2.24） ================= */

  var FONT = 'system-ui, -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif';

  function loadImg(src, cb) {
    if (!src) return cb(null);
    var im = new Image();
    im.onload = function () { cb(im); };
    im.onerror = function () { cb(null); };
    im.src = src;
  }

  // 方形码点整幅绘制（定位图案含在矩阵内，连续方块，最接近参考图）
  function paintQRSquare(ctx, link, x0, y0, size, color, lvl) {
    var m = buildMatrix(link, LV[lvl] || 1);
    var count = m.count, cell = size / count, r, c;
    ctx.fillStyle = color;
    for (r = 0; r < count; r++) {
      for (c = 0; c < count; c++) {
        if (!m.dark(r, c)) continue;
        ctx.fillRect(Math.floor(x0 + c * cell), Math.floor(y0 + r * cell), Math.ceil(cell) + 0.4, Math.ceil(cell) + 0.4);
      }
    }
    return m;
  }

  function setFont(ctx, weight, size) {
    ctx.font = (weight ? weight + ' ' : '') + size + 'px ' + FONT;
  }

  /* ---- 微信经典：白底 + 大黑标题 + 方码 + 中央绿徽 + 红角标 + 底部绿条 ---- */
  function drawWechatBubble(ctx, cx, cy) {
    ctx.fillStyle = '#fff';
    roundRect(ctx, cx - 30, cy - 24, 60, 42, 16); ctx.fill();
    ctx.beginPath();
    ctx.moveTo(cx - 14, cy + 14); ctx.lineTo(cx - 24, cy + 32); ctx.lineTo(cx - 2, cy + 16);
    ctx.closePath(); ctx.fill();
    ctx.fillStyle = '#07C160';
    ctx.beginPath();
    ctx.arc(cx - 13, cy - 3, 4.5, 0, Math.PI * 2);
    ctx.arc(cx, cy - 3, 4.5, 0, Math.PI * 2);
    ctx.arc(cx + 13, cy - 3, 4.5, 0, Math.PI * 2);
    ctx.fill();
  }

  function drawWechat(canvas, link, opts, done) {
    var W = 560, H = 790;
    canvas.width = W; canvas.height = H;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);

    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = '#16181d';
    setFont(ctx, 700, 40);
    ctx.fillText(String(opts.title || '微信扫码咨询'), W / 2, 74);

    var qs = 430, qx = (W - qs) / 2, qy = 150;
    paintQRSquare(ctx, link, qx, qy, qs, '#181a20', opts.correctLevel);

    var cx = W / 2, cy = qy + qs / 2;
    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,.22)'; ctx.shadowBlur = 14;
    ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(cx, cy, 62, 0, Math.PI * 2); ctx.fill();
    ctx.restore();
    ctx.fillStyle = '#07C160'; ctx.beginPath(); ctx.arc(cx, cy, 52, 0, Math.PI * 2); ctx.fill();

    var finish = function () {
      var badge = String(opts.badgeText || '').trim();
      if (badge) {
        setFont(ctx, 700, 28);
        var tw = ctx.measureText(badge).width;
        var bw = Math.max(96, tw + 44), bx = (W - bw) / 2, by = qy + qs + 26;
        ctx.fillStyle = '#fa5151'; roundRect(ctx, bx, by, bw, 48, 12); ctx.fill();
        ctx.fillStyle = '#fff'; ctx.fillText(badge, W / 2, by + 25);
      }
      ctx.fillStyle = '#07C160'; ctx.fillRect(0, H - 104, W, 104);
      ctx.fillStyle = '#fff'; setFont(ctx, 700, 36);
      ctx.fillText(String(opts.footer || '微信官方客服'), W / 2, H - 52);
      if (done) done();
    };
    if (opts.logoDataUrl) {
      loadImg(opts.logoDataUrl, function (im) {
        if (im) {
          ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, 44, 0, Math.PI * 2); ctx.clip();
          ctx.drawImage(im, cx - 44, cy - 44, 88, 88); ctx.restore();
        } else drawWechatBubble(ctx, cx, cy);
        finish();
      });
    } else { drawWechatBubble(ctx, cx, cy); finish(); }
  }

  /* ---- 票券卡片：浅蓝底 + 卡片（头像/名称/标签 + 撕裂线 + 码 + 文案组 + 步骤） ---- */
  function drawTicket(canvas, link, opts, done) {
    var W = 620, H = 800;
    canvas.width = W; canvas.height = H;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#e9eff8'; ctx.fillRect(0, 0, W, H);

    ctx.save();
    ctx.shadowColor = 'rgba(31,58,110,.14)'; ctx.shadowBlur = 24; ctx.shadowOffsetY = 8;
    ctx.fillStyle = '#fff'; roundRect(ctx, 20, 20, W - 40, H - 40, 26); ctx.fill();
    ctx.restore();
    ctx.strokeStyle = '#bcd4f2'; ctx.lineWidth = 2;
    roundRect(ctx, 21, 21, W - 42, H - 42, 25); ctx.stroke();

    var avX = 92, avY = 88, avR = 36;
    var finishHeader = function (im) {
      ctx.fillStyle = '#e8f1fd'; ctx.beginPath(); ctx.arc(avX, avY, avR, 0, Math.PI * 2); ctx.fill();
      if (im) {
        ctx.save(); ctx.beginPath(); ctx.arc(avX, avY, avR, 0, Math.PI * 2); ctx.clip();
        ctx.drawImage(im, avX - avR, avY - avR, avR * 2, avR * 2); ctx.restore();
      } else {
        ctx.fillStyle = '#1677ff';
        ctx.beginPath(); ctx.arc(avX, avY - 9, 13, 0, Math.PI * 2); ctx.fill();
        ctx.beginPath(); ctx.arc(avX, avY + 24, 22, Math.PI, 0); ctx.fill();
      }
      ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
      ctx.fillStyle = '#14181f'; setFont(ctx, 700, 30);
      ctx.fillText(String(opts.name || '官方客服'), 148, 84);
      ctx.fillStyle = '#97a3b3'; setFont(ctx, 0, 19);
      ctx.fillText('在线咨询 · 无需注册', 148, 118);

      var tag = String(opts.tagText || '客服');
      setFont(ctx, 700, 26);
      var tgW = ctx.measureText(tag).width + 40, tgX = W - 20 - 24 - tgW;
      ctx.fillStyle = '#e8f1fd'; roundRect(ctx, tgX, 64, tgW, 46, 23); ctx.fill();
      ctx.fillStyle = '#1677ff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(tag, tgX + tgW / 2, 88);

      ctx.strokeStyle = '#bcd4f2'; ctx.lineWidth = 2; ctx.setLineDash([12, 10]);
      ctx.beginPath(); ctx.moveTo(20, 152); ctx.lineTo(W - 20, 152); ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = '#e9eff8';
      ctx.beginPath(); ctx.arc(20, 152, 15, 0, Math.PI * 2); ctx.fill();
      ctx.beginPath(); ctx.arc(W - 20, 152, 15, 0, Math.PI * 2); ctx.fill();

      var qs = 380, qx = (W - qs) / 2, qy = 196;
      paintQRSquare(ctx, link, qx, qy, qs, '#1677ff', opts.correctLevel);

      ctx.fillStyle = '#1677ff'; setFont(ctx, 700, 32);
      ctx.fillText(String(opts.caption || '扫码联系在线客服'), W / 2, qy + qs + 58);
      ctx.fillStyle = '#97a3b3'; setFont(ctx, 0, 20);
      ctx.fillText(String(opts.subLine || '微信 / 支付宝 / 浏览器 均可识别'), W / 2, qy + qs + 96);

      var steps = String(opts.steps || '① 扫码 ② 直接开聊');
      setFont(ctx, 700, 27);
      var sw = ctx.measureText(steps).width + 72;
      ctx.fillStyle = '#e8f1fd'; roundRect(ctx, (W - sw) / 2, qy + qs + 120, sw, 56, 28); ctx.fill();
      ctx.fillStyle = '#1677ff';
      ctx.fillText(steps, W / 2, qy + qs + 149);

      ctx.fillStyle = '#9aa6b6'; setFont(ctx, 0, 17);
      ctx.fillText(String(opts.note || '本码长期有效 · 请勿转发给无关人员'), W / 2, H - 40);
      if (done) done();
    };
    if (opts.avatarDataUrl) loadImg(opts.avatarDataUrl, function (im) { finishHeader(im); });
    else finishHeader(null);
  }
})(window);

/* ===== 客服码卡片渲染（v2.30）：扫码咨询 + 码 + 官方底部通栏；品牌图标为官方图形 ===== */
/* card_core.js — 客服码卡片渲染核心（本地预览用；与将来线上 canvas 同源逻辑）
 * 参数依据 skill qr-code-asset-design：码点留缝/圆角、码眼圆角、中央 logo 直径按模块密度自适应
 */
(function (g) {
  var CARD_W = 680, CARD_H = 960;

  function svg(s) { return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(s); }

  var ICONS = {
    none: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><rect x=\"1\" y=\"1\" width=\"62\" height=\"62\" rx=\"14\" fill=\"#fff\" stroke=\"#e3e6ea\" stroke-width=\"2\"/><circle cx=\"32\" cy=\"32\" r=\"14\" fill=\"none\" stroke=\"#b9bfc7\" stroke-width=\"4\"/><path d=\"M22 42 42 22\" stroke=\"#b9bfc7\" stroke-width=\"4\" stroke-linecap=\"round\"/></svg>"),
    avatar: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><defs><linearGradient id=\"a\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0\" stop-color=\"#5B8DEF\"/><stop offset=\"1\" stop-color=\"#8A63F0\"/></linearGradient></defs><rect width=\"64\" height=\"64\" rx=\"32\" fill=\"url(#a)\"/><circle cx=\"32\" cy=\"25\" r=\"9.5\" fill=\"#fff\"/><path d=\"M13 54c3.2-10 10.5-14 19-14s15.8 4 19 14z\" fill=\"#fff\"/></svg>"),
    all: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><rect x=\"1\" y=\"1\" width=\"62\" height=\"62\" rx=\"13\" fill=\"#fff\" stroke=\"#e9ecef\" stroke-width=\"2\"/><g transform=\"translate(6,6) scale(0.3)\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#07C160\"/><path d=\"M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 0 1 .213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 0 0 .167-.054l1.903-1.114a.864.864 0 0 1 .717-.098 10.16 10.16 0 0 0 2.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348zM5.785 5.991c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178A1.17 1.17 0 0 1 4.623 7.17c0-.651.52-1.18 1.162-1.18zm5.813 0c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178 1.17 1.17 0 0 1-1.162-1.178c0-.651.52-1.18 1.162-1.18zm5.34 2.867c-1.797-.052-3.746.512-5.28 1.786-1.72 1.428-2.687 3.72-1.78 6.22.942 2.453 3.666 4.229 6.884 4.229.826 0 1.622-.12 2.361-.336a.722.722 0 0 1 .598.082l1.584.926a.272.272 0 0 0 .14.047c.134 0 .24-.111.24-.247 0-.06-.023-.12-.038-.177l-.327-1.233a.582.582 0 0 1-.023-.156.49.49 0 0 1 .201-.398C23.024 18.48 24 16.82 24 14.98c0-3.21-2.931-5.837-6.656-6.088V8.89c-.135-.01-.27-.027-.407-.03zm-2.53 3.274c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.97-.982zm4.844 0c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.969-.982z\" fill=\"#fff\" transform=\"translate(6.0,6.0) scale(2.1667)\"/></g><g transform=\"translate(34,6) scale(0.3)\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#12B7F5\"/><path d=\"M21.395 15.035a40 40 0 0 0-.803-2.264l-1.079-2.695c.001-.032.014-.562.014-.836C19.526 4.632 17.351 0 12 0S4.474 4.632 4.474 9.241c0 .274.013.804.014.836l-1.08 2.695a39 39 0 0 0-.802 2.264c-1.021 3.283-.69 4.643-.438 4.673.54.065 2.103-2.472 2.103-2.472 0 1.469.756 3.387 2.394 4.771-.612.188-1.363.479-1.845.835-.434.32-.379.646-.301.778.343.578 5.883.369 7.482.189 1.6.18 7.14.389 7.483-.189.078-.132.132-.458-.301-.778-.483-.356-1.233-.646-1.846-.836 1.637-1.384 2.393-3.302 2.393-4.771 0 0 1.563 2.537 2.103 2.472.251-.03.581-1.39-.438-4.673\" fill=\"#fff\" transform=\"translate(6.0,6.0) scale(2.1667)\"/></g><g transform=\"translate(6,34) scale(0.3)\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#1677FF\"/><path d=\"M19.695 15.07c3.426 1.158 4.203 1.22 4.203 1.22V3.846c0-2.124-1.705-3.845-3.81-3.845H3.914C1.808.001.102 1.722.102 3.846v16.31c0 2.123 1.706 3.845 3.813 3.845h16.173c2.105 0 3.81-1.722 3.81-3.845v-.157s-6.19-2.602-9.315-4.119c-2.096 2.602-4.8 4.181-7.607 4.181-4.75 0-6.361-4.19-4.112-6.949.49-.602 1.324-1.175 2.617-1.497 2.025-.502 5.247.313 8.266 1.317a16.796 16.796 0 0 0 1.341-3.302H5.781v-.952h4.799V6.975H4.77v-.953h5.81V3.591s0-.409.411-.409h2.347v2.84h5.744v.951h-5.744v1.704h4.69a19.453 19.453 0 0 1-1.986 5.06c1.424.52 2.702 1.011 3.654 1.333m-13.81-2.032c-.596.06-1.71.325-2.321.869-1.83 1.608-.735 4.55 2.968 4.55 2.151 0 4.301-1.388 5.99-3.61-2.403-1.182-4.438-2.028-6.637-1.809\" fill=\"#fff\" transform=\"translate(6.0,6.0) scale(2.1667)\"/></g><g transform=\"translate(34,34) scale(0.3)\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#010101\"/><path d=\"M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z\" fill=\"#fff\" transform=\"translate(6.0,6.0) scale(2.1667)\"/></g></svg>"),
    wechat: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#07C160\"/><path d=\"M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 0 1 .213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 0 0 .167-.054l1.903-1.114a.864.864 0 0 1 .717-.098 10.16 10.16 0 0 0 2.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348zM5.785 5.991c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178A1.17 1.17 0 0 1 4.623 7.17c0-.651.52-1.18 1.162-1.18zm5.813 0c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178 1.17 1.17 0 0 1-1.162-1.178c0-.651.52-1.18 1.162-1.18zm5.34 2.867c-1.797-.052-3.746.512-5.28 1.786-1.72 1.428-2.687 3.72-1.78 6.22.942 2.453 3.666 4.229 6.884 4.229.826 0 1.622-.12 2.361-.336a.722.722 0 0 1 .598.082l1.584.926a.272.272 0 0 0 .14.047c.134 0 .24-.111.24-.247 0-.06-.023-.12-.038-.177l-.327-1.233a.582.582 0 0 1-.023-.156.49.49 0 0 1 .201-.398C23.024 18.48 24 16.82 24 14.98c0-3.21-2.931-5.837-6.656-6.088V8.89c-.135-.01-.27-.027-.407-.03zm-2.53 3.274c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.97-.982zm4.844 0c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.969-.982z\" fill=\"#fff\" transform=\"translate(9.68,9.68) scale(1.86)\"/></svg>"),
    alipay: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#1677FF\"/><path d=\"M19.695 15.07c3.426 1.158 4.203 1.22 4.203 1.22V3.846c0-2.124-1.705-3.845-3.81-3.845H3.914C1.808.001.102 1.722.102 3.846v16.31c0 2.123 1.706 3.845 3.813 3.845h16.173c2.105 0 3.81-1.722 3.81-3.845v-.157s-6.19-2.602-9.315-4.119c-2.096 2.602-4.8 4.181-7.607 4.181-4.75 0-6.361-4.19-4.112-6.949.49-.602 1.324-1.175 2.617-1.497 2.025-.502 5.247.313 8.266 1.317a16.796 16.796 0 0 0 1.341-3.302H5.781v-.952h4.799V6.975H4.77v-.953h5.81V3.591s0-.409.411-.409h2.347v2.84h5.744v.951h-5.744v1.704h4.69a19.453 19.453 0 0 1-1.986 5.06c1.424.52 2.702 1.011 3.654 1.333m-13.81-2.032c-.596.06-1.71.325-2.321.869-1.83 1.608-.735 4.55 2.968 4.55 2.151 0 4.301-1.388 5.99-3.61-2.403-1.182-4.438-2.028-6.637-1.809\" fill=\"#fff\" transform=\"translate(9.68,9.68) scale(1.86)\"/></svg>"),
    qq: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#12B7F5\"/><path d=\"M21.395 15.035a40 40 0 0 0-.803-2.264l-1.079-2.695c.001-.032.014-.562.014-.836C19.526 4.632 17.351 0 12 0S4.474 4.632 4.474 9.241c0 .274.013.804.014.836l-1.08 2.695a39 39 0 0 0-.802 2.264c-1.021 3.283-.69 4.643-.438 4.673.54.065 2.103-2.472 2.103-2.472 0 1.469.756 3.387 2.394 4.771-.612.188-1.363.479-1.845.835-.434.32-.379.646-.301.778.343.578 5.883.369 7.482.189 1.6.18 7.14.389 7.483-.189.078-.132.132-.458-.301-.778-.483-.356-1.233-.646-1.846-.836 1.637-1.384 2.393-3.302 2.393-4.771 0 0 1.563 2.537 2.103 2.472.251-.03.581-1.39-.438-4.673\" fill=\"#fff\" transform=\"translate(9.68,9.68) scale(1.86)\"/></svg>"),
    douyin: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><rect width=\"64\" height=\"64\" rx=\"14\" fill=\"#010101\"/><path d=\"M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z\" fill=\"#25F4EE\" transform=\"translate(8.08,8.48) scale(1.86)\"/><path d=\"M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z\" fill=\"#FE2C55\" transform=\"translate(11.08,10.879999999999999) scale(1.86)\"/><path d=\"M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z\" fill=\"#fff\" transform=\"translate(9.68,9.68) scale(1.86)\"/></svg>"),
    upload: svg("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 64 64\"><rect x=\"2\" y=\"2\" width=\"60\" height=\"60\" rx=\"13\" fill=\"#fbfcfd\" stroke=\"#cfd6de\" stroke-width=\"2\" stroke-dasharray=\"7 5\"/><path d=\"M32 42V22m0 0-8 8m8-8 8 8\" stroke=\"#8b95a1\" stroke-width=\"4\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/></svg>")
  };

  function qrModel(text) {
    var host = document.createElement('div');
    var q = new QRCode(host, { text: text, width: 256, height: 256 });
    var m = q._oQRCode;
    var n = m.getModuleCount();
    return { n: n, dark: function (r, c) { return m.isDark(r, c); } };
  }

  function rr(ctx, x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    if (ctx.roundRect) { ctx.roundRect(x, y, w, h, r); return; }
    ctx.moveTo(x + r, y); ctx.lineTo(x + w - r, y); ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r); ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h); ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r); ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
  }

  function inEye(n, r, c) {
    return (r < 7 && c < 7) || (r < 7 && c >= n - 7) || (r >= n - 7 && c < 7);
  }

  function drawModule(ctx, x, y, box, color, style) {
    ctx.fillStyle = color;
    if (style === 'square') { ctx.fillRect(x, y, box, box); return; }
    if (style === 'dot') {
      ctx.beginPath(); ctx.arc(x + box / 2, y + box / 2, box * 0.46, 0, Math.PI * 2); ctx.fill(); return;
    }
    var inset = box * 0.04, s = box - inset * 2;
    rr(ctx, x + inset, y + inset, s, s, box * 0.20); ctx.fill();
  }

  function drawEye(ctx, x, y, box, fg, style, R) {
    if (!R) R = style === 'circle' ? [3.5, 2.5, 1.5] : (style === 'rounded' ? [0.6, 0.4, 0.5] : [0, 0, 0]);
    ctx.fillStyle = fg; rr(ctx, x, y, box * 7, box * 7, R[0] * box); ctx.fill();
    ctx.fillStyle = '#fff'; rr(ctx, x + box, y + box, box * 5, box * 5, R[1] * box); ctx.fill();
    ctx.fillStyle = fg; rr(ctx, x + box * 2, y + box * 2, box * 3, box * 3, R[2] * box); ctx.fill();
  }

  /* opts: link, topText, bottomText, brand, dot, eye, logo(dataURL|null), colorDots(bool), scale, bg */
  function drawCodeCard(canvas, opt) {
    var o = Object.assign({
      link: 'https://demo.moupz.cn/', topText: '\u626b\u7801\u54a8\u8be2', bottomText: '\u5b98\u65b9\u5ba2\u670d',
      brand: '#2E6BE6', dot: 'rounded', eye: 'rounded', logo: null, colorDots: false, scale: 2, font: ''
    }, opt || {});

    var W = CARD_W, H = CARD_H, S = o.scale, f = o.font || 'bold %dpx "Microsoft YaHei","PingFang SC",Arial,sans-serif';
    canvas.width = W * S; canvas.height = H * S;
    var ctx = canvas.getContext('2d');
    ctx.setTransform(S, 0, 0, S, 0, 0);
    ctx.clearRect(0, 0, W, H);

    // 卡面（不画投影：投影会被画布边缘裁掉，留下四个黑角；需要投影由页面 CSS 负责）
    rr(ctx, 0, 0, W, H, 28); ctx.fillStyle = '#fff'; ctx.fill();

    var report = {};

    // 顶部文案
    ctx.fillStyle = '#17181c';
    ctx.font = f.replace('%d', '44');
    ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    report.topTextWidth = Math.round(ctx.measureText(o.topText).width);
    report.topTextMax = W - 2 * 56;
    ctx.fillText(o.topText, W / 2, 120);

    // 二维码（比例照参考图：码宽 = 卡宽 69%，码区 472×472）
    var m = qrModel(o.link), qa = 472, qx = (W - qa) / 2, qy = 262;
    var box = Math.floor(qa / m.n);
    if (box % 2) box -= 1;                 // 偶数模块（不缩放，直接按 box 画）
    if (box < 2) box = 2;
    var size = box * m.n;
    var ox = Math.round(qx + (qa - size) / 2), oy = Math.round(qy + (qa - size) / 2);
    var fg = o.colorDots ? o.brand : '#16181d';
    var dot = o.dot;
    if (dot === 'dot' && box < 10) dot = 'rounded';   // 长链接（模块多）时圆点会变临界：自动回退圆角，保可扫
    report.n = m.n; report.box = box; report.qrSize = size; report.dot = dot;
    for (var r = 0; r < m.n; r++)
      for (var c = 0; c < m.n; c++)
        if (!inEye(m.n, r, c) && m.dark(r, c)) drawModule(ctx, ox + c * box, oy + r * box, box, fg, dot);
    drawEye(ctx, ox, oy, box, fg, o.eye, o.eyeR);
    drawEye(ctx, ox + (m.n - 7) * box, oy, box, fg, o.eye, o.eyeR);
    drawEye(ctx, ox, oy + (m.n - 7) * box, box, fg, o.eye, o.eyeR);

    // 中央 logo：白底衬（默认圆角方形，比圆形同宽更"大"）+ 图标内缩，图标不越出白衬（越出会盖住码点、解码器直接拒码）
    if (o.logo) {
      var L = o.logoRatio || (box >= 10 ? 0.18 : 0.14);
      var side = size * L, ccx = W / 2, ccy = oy + size / 2;
      ctx.fillStyle = '#fff';
      if (o.knock === 'square') { rr(ctx, ccx - side / 2, ccy - side / 2, side, side, side * 0.26); ctx.fill(); }
      else { ctx.beginPath(); ctx.arc(ccx, ccy, side / 2, 0, Math.PI * 2); ctx.fill(); }
      var p = side * (o.iconRatio || 0.86), pr = p / 2;
      ctx.save(); ctx.beginPath();
      var round = (o.knock === 'square' && o.logoShape !== 'circle');
      if (round) rr(ctx, ccx - pr, ccy - pr, p, p, p * 0.26);
      else ctx.arc(ccx, ccy, pr, 0, Math.PI * 2);
      ctx.clip();
      if (o._logoImg && o._logoImg.complete) ctx.drawImage(o._logoImg, ccx - pr, ccy - pr, p, p);
      ctx.restore();
      report.logoDia = Math.round(side);
      report.logoPct = Math.round((o.knock === 'square' ? L * (o.iconRatio || 0.86) : L * (o.iconRatio || 0.86) * 0.707) * 100);
    }

    // 底部通栏
    var barH = 131, by = H - barH;
    ctx.save();
    rr(ctx, 0, 0, W, H, 28); ctx.clip();
    ctx.fillStyle = o.brand; ctx.fillRect(0, by, W, barH);
    ctx.restore();
    ctx.fillStyle = '#fff';
    ctx.font = f.replace('%d', '40');
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    report.bottomTextWidth = Math.round(ctx.measureText(o.bottomText).width);
    report.bottomTextMax = W - 2 * 56;
    ctx.fillText(o.bottomText, W / 2, by + barH / 2 + 2);

    report.overflow = (report.topTextWidth > report.topTextMax) || (report.bottomTextWidth > report.bottomTextMax);
    report.scale = S;
    canvas._report = report;
    return report;
  }

  g.KFQCard = { drawCodeCard: drawCodeCard, ICONS: ICONS, CARD_W: CARD_W, CARD_H: CARD_H };
})(window);
