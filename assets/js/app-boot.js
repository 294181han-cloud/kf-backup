// app-boot.js - 全局 JS/Promise 错误提示条（由 public/app.html 内联脚本提取）

// 已知良性警告不显示（Element Plus 表格/滚动容器在布局变化帧内的二次测量，
// 浏览器标准行为，不影响功能；显示出来只会吓到用户）
var IGNORED = [
  'ResizeObserver loop completed with undelivered notifications',
  'ResizeObserver loop limit exceeded',
  'Script error.',
];

function showBar(text) {
  try {
    var bar = document.getElementById('global-err-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'global-err-bar';
      bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#f56c6c;color:#fff;font-size:12px;padding:6px 12px;word-break:break-all;';
      (document.body || document.documentElement).appendChild(bar);
    }
    bar.textContent = text;
  } catch (e) {}
}

window.addEventListener('error', function (ev) {
  var msg = ev.message || '';
  for (var i = 0; i < IGNORED.length; i++) {
    if (msg.indexOf(IGNORED[i]) !== -1) return;
  }
  showBar('JS错误: ' + msg + ' @ ' + (ev.filename || '') + ':' + (ev.lineno || ''));
});
window.addEventListener('unhandledrejection', function (ev) {
  var msg = (ev.reason && ev.reason.message) || ev.reason || '';
  for (var i = 0; i < IGNORED.length; i++) {
    if (String(msg).indexOf(IGNORED[i]) !== -1) return;
  }
  showBar('Promise错误: ' + msg);
});
