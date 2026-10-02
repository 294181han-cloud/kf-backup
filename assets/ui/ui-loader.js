// ui-loader.js - UI 款式加载器（同步脚本，放页面 <head> 末尾）
//
// 用法：<script src="/assets/ui/ui-registry.js?v=…"></script>
//      <script src="/assets/ui/ui-loader.js?v=…" data-ui-cat="pc|mobile"></script>
//
// 解析优先级：URL ?ui=<id>（预览并记住）> localStorage(kfxt_ui_<cat>) > 注册表默认值
// 行为：html 上设置 data-ui-cat / data-ui；按需注入 base css 与款式 theme.css；
//      暴露 window.UI = { cat, current(), list(cat?), set(id, {persist}) }
// 容错：注册表缺失或款式 id 不存在时静默回退默认款式，绝不让页面白屏。
(function () {
  var REG = window.UI_STYLES;
  if (!REG) { console.warn('[ui] ui-registry.js 未加载，使用页面内置默认样式'); return; }

  var script = document.currentScript;
  var cat = (script && script.getAttribute('data-ui-cat')) || 'pc';
  var conf = REG.categories[cat] || REG.categories.pc;

  // 旧版工作台主题键迁移（kfxt_theme: default/purple/sky/green）
  var LEGACY = { pc: 'kfxt_theme' };
  var LS_KEY = 'kfxt_ui_' + cat;
  function stored() {
    try {
      var v = localStorage.getItem(LS_KEY);
      if (v) return v;
      if (LEGACY[cat]) return localStorage.getItem(LEGACY[cat]);
    } catch (e) {}
    return null;
  }

  function find(id) {
    for (var i = 0; i < conf.styles.length; i++) if (conf.styles[i].id === id) return conf.styles[i];
    return null;
  }

  var wanted = new URLSearchParams(location.search).get('ui');
  var style = find(wanted) || find(stored()) || find(conf.default);
  if (!style) { console.warn('[ui] 分类 ' + cat + ' 无任何款式'); return; }
  if (wanted && find(wanted)) {
    try { localStorage.setItem(LS_KEY, wanted); } catch (e) {}
    // 去掉 ?ui= 参数避免刷新重复写 localStorage（保留其余参数）
    try {
      var u = new URL(location.href); u.searchParams.delete('ui');
      history.replaceState(null, '', u.href);
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
  function apply(s) {
    html.setAttribute('data-ui', s.id);
    var head = document.head || document.getElementsByTagName('head')[0];
    var oldBase = head.querySelector('link[data-ui-base]');
    var oldTheme = head.querySelector('link[data-ui-theme]');
    var needBase = !!conf.base && !oldBase;
    var themeHref = s.css + '?v=' + REG.version;
    if (!oldTheme || oldTheme.getAttribute('href') !== themeHref) {
      var t = link(s.css, 'data-ui-theme');
      if (oldTheme) head.replaceChild(t, oldTheme); else head.appendChild(t);
    }
    if (needBase) head.insertBefore(link(conf.base, 'data-ui-base'), head.querySelector('link[data-ui-theme]'));
  }
  apply(style);

  window.UI = {
    cat: cat,
    current: function () { return style; },
    list: function (c) {
      var cc = REG.categories[c || cat];
      return cc ? cc.styles.slice() : [];
    },
    categories: function () { return Object.keys(REG.categories); },
    set: function (id, opts) {
      var s = find(id);
      if (!s) { console.warn('[ui] 未知款式: ' + id); return false; }
      style = s;
      if (!opts || opts.persist !== false) { try { localStorage.setItem(LS_KEY, s.id); } catch (e) {} }
      apply(s);
      return true;
    },
  };
})();
