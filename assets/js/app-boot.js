// app-boot.js - 全局 JS/Promise 错误提示条（由 public/app.html 内联脚本提取）
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
