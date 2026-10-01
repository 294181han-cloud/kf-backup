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
})(window);
