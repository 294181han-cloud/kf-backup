// jsdom 真实挂载工作台，捕获全部运行时错误
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const root = process.argv[2];
const html = fs.readFileSync(path.join(root, 'public/app.html'), 'utf-8');

const errors = [];
const vc = new (require('jsdom').VirtualConsole)();
vc.on('error', (...a) => errors.push('[console.error] ' + a.map(String).join(' ').slice(0, 300)));
vc.on('warn', (...a) => errors.push('[console.warn] ' + a.map(String).join(' ').slice(0, 200)));
vc.on('jsdomError', (e) => {
  const msg = String(e.detail && e.detail.message || e.message || e);
  if (!/not implemented/i.test(msg)) errors.push('[jsdomError] ' + msg.slice(0, 300));
});

const dom = new JSDOM(html, {
  url: 'https://kf.ylcz.cc/app.html',
  runScripts: 'outside-only',
  pretendToBeVisual: true,
  virtualConsole: vc,
});
const { window } = dom;

// mock 缺失的浏览器 API
window.fetch = () => Promise.resolve(new window.Response(JSON.stringify({ ok: true }), { headers: { 'Content-Type': 'application/json' } }));
window.matchMedia = window.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
window.scrollTo = () => {};
window.navigator.getMedia = undefined;
window.ClipboardItem = window.ClipboardItem || class {};
window.requestAnimationFrame = cb => setTimeout(cb, 0);
window.cancelAnimationFrame = id => clearTimeout(id);

// 转发 jsdom 的 window 错误
window.addEventListener('error', e => errors.push('[window.error] ' + (e.message || '') + ' @' + (e.filename || '') + ':' + (e.lineno || '')));
window.addEventListener('unhandledrejection', e => errors.push('[unhandledrejection] ' + ((e.reason && e.reason.message) || e.reason || '')));

// 执行页面 script（vue → element-plus → qrcode → qr-poster → api → 主逻辑）
const scripts = [];
const re = /<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g;
let m;
while ((m = re.exec(html))) {
  const content = m[1].trim();
  if (content) scripts.push({ src: /src="/.test(m[0]), content });
}
// 带 src 的需要真实加载：手动读本地文件按 src 顺序执行
const srcScripts = [];
let sm;
const re2 = /<script src="([^"]+)"[^>]*><\/script>/g;
while ((sm = re2.exec(html))) srcScripts.push(sm[1]);

for (const src of srcScripts) {
  const p = path.join(root, src.replace(/^\//, '').replace(/\?.*$/, ''));
  const code = fs.readFileSync(p, 'utf-8');
  try {
    window.eval(code);
    console.log('[loaded]', src.slice(0, 60));
  } catch (e) {
    console.log('[加载失败]', src, '->', e.message.slice(0, 200));
    errors.push('[script load] ' + src + ' -> ' + e.message.slice(0, 300));
  }
}
for (const s of scripts) {
  try { window.eval(s.content); console.log('[inline script 执行完成]'); }
  catch (e) {
    console.log('[inline script 失败]', e.message.slice(0, 300));
    errors.push('[inline script] ' + e.message.slice(0, 500) + '\n' + (e.stack || '').split('\n').slice(0, 4).join('\n'));
  }
}

setTimeout(() => {
  console.log('\n=== 运行时错误汇总 ===');
  if (errors.length) errors.slice(0, 10).forEach((e, i) => console.log(`  ${i+1}. ${e}`));
  else console.log('  无错误，页面正常挂载 ✓');
  const app = window.document.querySelector('#app');
  console.log('#app innerHTML 长度:', app ? app.innerHTML.length : 'N/A');
  console.log('是否仍为模板原文:', app && app.innerHTML.includes('{{'));
  process.exit(0);
}, 1500);
