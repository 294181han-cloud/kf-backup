// jsdom 挂载终验（geoTag/setTheme 修复后）
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const root = process.argv[2];
const html = fs.readFileSync(path.join(root, 'public/app.html'), 'utf-8');
const errors = [];
const vc = new (require('jsdom').VirtualConsole)();
vc.on('error', (...a) => errors.push('[console.error] ' + a.map(String).join(' ').slice(0, 250)));
const dom = new JSDOM(html, { url: 'https://kf.ylcz.cc/app.html', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc });
const { window } = dom;
window.fetch = () => Promise.resolve(new window.Response(JSON.stringify({ ok: true }), { headers: { 'Content-Type': 'application/json' } }));
window.matchMedia = window.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
window.scrollTo = () => {};
window.requestAnimationFrame = cb => setTimeout(cb, 0);
window.cancelAnimationFrame = id => clearTimeout(id);
window.addEventListener('error', e => errors.push('[window.error] ' + (e.message || '') + ' @' + (e.lineno || '')));
window.addEventListener('unhandledrejection', e => errors.push('[unhandledrejection] ' + ((e.reason && e.reason.message) || e.reason || '')));

// 加载 vendor 与内联脚本
const re2 = /<script src="([^"]+)"[^>]*><\/script>/g;
let sm;
while ((sm = re2.exec(html))) {
  const p = path.join(root, sm[1].replace(/^\//, '').replace(/\?.*$/, ''));
  try { window.eval(fs.readFileSync(p, 'utf-8')); }
  catch (e) { errors.push('[load] ' + sm[1] + ' -> ' + e.message.slice(0, 200)); }
}
const re3 = /<script>([\s\S]*?)<\/script>/g;
while ((sm = re3.exec(html))) {
  const content = sm[1].trim();
  if (!content) continue;
  try { window.eval(content); console.log('[inline OK]'); }
  catch (e) {
    console.log('[inline 失败]', e.message.slice(0, 300));
    errors.push('[inline] ' + e.message + '\n' + (e.stack || '').split('\n').slice(0, 3).join('\n'));
  }
}

setTimeout(() => {
  console.log('\n=== 错误汇总 ===');
  if (errors.length) errors.slice(0, 8).forEach((e, i) => console.log(`  ${i+1}. ${e.slice(0, 300)}`));
  else console.log('  无错误 ✓');
  const app = window.document.querySelector('#app');
  console.log('#app 渲染长度:', app ? app.innerHTML.length : 'N/A');
  process.exit(errors.length ? 1 : 0);
}, 1500);
