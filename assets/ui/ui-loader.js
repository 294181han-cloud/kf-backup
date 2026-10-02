// ui-loader.js - UI 加载器（同步脚本，放页面 <head> 末尾）
//
// 用法：<script src="/assets/ui/ui-registry.js?v=…"></script>
//      <script src="/assets/ui/ui-loader.js?v=…" data-ui-cat="pc|mobile"></script>
//
// 结构：分类 → 模板（结构性外观）→ 配色（颜色方案）。
// 解析优先级：URL ?ui=<配色id>（预览并记住）> localStorage > 注册表默认；
//             URL ?uit=<模板id> 同理（?ui= 优先生效时模板仍按 ?uit=/存储/默认解析）。
// 行为：html 上设置 data-ui-cat / data-ui-template / data-ui(配色id)；按需注入
//       分类 base、模板 css、配色 theme.css；暴露 window.UI。
// 容错：注册表缺失或 id 不存在时静默回退默认，绝不让页面白屏。
// 旧版兼容：kfxt_ui_<cat> 里存的旧款式 id 直接当作配色 id 解析；kfxt_theme 同理。
(function () {
  var REG = window.UI_STYLES;
  if (!REG) { console.warn('[ui] ui-registry.js 未加载，使用页面内置默认样式'); return; }

  var script = document.currentScript;
  var cat = (script && script.getAttribute('data-ui-cat')) || 'pc';
  var conf = REG.categories[cat] || REG.categories.pc;

  // 旧版工作台主题键迁移（kfxt_theme: default/purple/sky/green）
  var LEGACY = { pc: 'kfxt_theme' };
  var LS_COLOR = 'kfxt_ui_' + cat;
  var LS_TPL = LS_COLOR + '_tpl';
  function stored(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  function storedColor() {
    var v = stored(LS_COLOR);
    if (!v && LEGACY[cat]) v = stored(LEGACY[cat]);
    return v;
  }

  function findTemplate(id) {
    var ts = conf.templates || [];
    for (var i = 0; i < ts.length; i++) if (ts[i].id === id) return ts[i];
    return null;
  }
  function findColor(tpl, id) {
    if (!tpl) return null;
    var cs = tpl.colors || [];
    for (var i = 0; i < cs.length; i++) if (cs[i].id === id) return cs[i];
    return null;
  }

  var params = new URLSearchParams(location.search);
  var wantTpl = params.get('uit');
  var wantColor = params.get('ui');

  var template = findTemplate(wantTpl) || findTemplate(stored(LS_TPL)) || findTemplate(conf.defaultTemplate);
  if (!template) { console.warn('[ui] 分类 ' + cat + ' 无任何模板'); return; }
  if (!template.colors || !template.colors.length) { console.warn('[ui] 模板 ' + template.id + ' 无配色'); return; }

  var style = findColor(template, wantColor) || findColor(template, storedColor()) || template.colors[0];
  if (wantColor && findColor(template, wantColor)) {
    try { localStorage.setItem(LS_COLOR, wantColor); } catch (e) {}
    try {
      var u = new URL(location.href); u.searchParams.delete('ui');
      history.replaceState(null, '', u.href);
    } catch (e) {}
  }
  if (wantTpl && findTemplate(wantTpl)) {
    try { localStorage.setItem(LS_TPL, wantTpl); } catch (e) {}
    try {
      var u2 = new URL(location.href); u2.searchParams.delete('uit');
      history.replaceState(null, '', u2.href);
    } catch (e) {}
  }

  var html = document.documentElement;
  html.setAttribute('data-ui-cat', cat);

  function link(href, marker) {
    var l = document.createElement('link');
    l.rel = 'stylesheet';
    l.setAttribute(marker, '');
    l.href = href + '?v=' + REG.version;
    return l;
  }
  function apply(tpl, s) {
    html.setAttribute('data-ui-template', tpl.id);
    html.setAttribute('data-ui', s.id);
    var head = document.head || document.getElementsByTagName('head')[0];
    var needBase = !!conf.base && !head.querySelector('link[data-ui-base]');
    // 模板 css（结构性外观，可选）
    var tplHref = tpl.css ? tpl.css + '?v=' + REG.version : null;
    var oldTpl = head.querySelector('link[data-ui-tpl]');
    if (tplHref) {
      if (!oldTpl || oldTpl.getAttribute('href') !== tplHref) {
        var t = link(tplHref, 'data-ui-tpl');
        if (oldTpl) head.replaceChild(t, oldTpl); else head.insertBefore(t, head.querySelector('link[data-ui-theme]'));
      }
    } else if (oldTpl) {
      oldTpl.parentNode.removeChild(oldTpl);
    }
    // 配色 css
    var themeHref = s.css + '?v=' + REG.version;
    var oldTheme = head.querySelector('link[data-ui-theme]');
    if (!oldTheme || oldTheme.getAttribute('href') !== themeHref) {
      var n = link(s.css, 'data-ui-theme');
      if (oldTheme) head.replaceChild(n, oldTheme); else head.appendChild(n);
    }
    if (needBase) head.insertBefore(link(conf.base, 'data-ui-base'), head.querySelector('link[data-ui-tpl]') || head.querySelector('link[data-ui-theme]'));
  }
  apply(template, style);

  window.UI = {
    cat: cat,
    template: function () { return template; },
    templates: function (c) {
      var cc = REG.categories[c || cat];
      return cc ? (cc.templates || []).slice() : [];
    },
    colors: function (tplId, c) {
      var cc = REG.categories[c || cat];
      var t = null;
      if (cc) {
        var ts = cc.templates || [];
        for (var i = 0; i < ts.length; i++) if (ts[i].id === (tplId || template.id)) t = ts[i];
      }
      return t ? (t.colors || []).slice() : [];
    },
    current: function () { return style; },
    list: function (c) { return window.UI.colors(null, c); },  // 旧 API 兼容：返回当前模板的配色列表
    setTemplate: function (id, opts) {
      var t = findTemplate(id);
      if (!t || !t.colors || !t.colors.length) { console.warn('[ui] 未知模板: ' + id); return false; }
      template = t;
      if (!opts || opts.persist !== false) { try { localStorage.setItem(LS_TPL, t.id); } catch (e) {} }
      // 模板切换后当前配色若不存在于新模板，回退其首个配色
      style = findColor(t, style && style.id) || t.colors[0];
      try { localStorage.setItem(LS_COLOR, style.id); } catch (e) {}
      apply(template, style);
      return true;
    },
    set: function (id, opts) {
      var s = findColor(template, id);
      if (!s) { console.warn('[ui] 未知配色: ' + id); return false; }
      style = s;
      if (!opts || opts.persist !== false) { try { localStorage.setItem(LS_COLOR, s.id); } catch (e) {} }
      apply(template, style);
      return true;
    },
  };
})();
