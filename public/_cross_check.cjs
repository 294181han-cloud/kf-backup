// 模板引用 vs setup 导出 交叉比对（return 锚点修正）
const fs = require('fs');
const path = require('path');
const root = process.argv[2];
const html = fs.readFileSync(path.join(root, 'public/app.html'), 'utf-8');

const appStart = html.indexOf('<div id="app"');
const scriptStart = html.indexOf('<script src="/assets/vendor/vue.global.js');
const tpl = html.slice(appStart, scriptStart);
const jsStart = html.indexOf('<script>\n', scriptStart);
const js = html.slice(jsStart, html.indexOf('</script>', html.indexOf('mount(\'#app\')')));

// return 块：从 "return {" 到 "};\n  }" （setup 结束）
const rStart = js.lastIndexOf('return {');
const rEnd = js.indexOf('};', rStart);
const rBlock = js.slice(rStart, rEnd);
const exported = new Set();
for (const part of rBlock.slice(7).split(',\n')) {
  for (const t of part.split(',')) {
    const tt = t.trim().replace(/,$/, '');
    if (!tt) continue;
    const m = tt.match(/^([\w$]+)$/);
    if (m) exported.add(tt);
  }
}
console.log('setup 导出数:', exported.size);

// 模板中所有标识符
const used = new Set();
let m;
const callRe = /([A-Za-z_$][\w$]*)\s*\(/g;
while ((m = callRe.exec(tpl))) used.add(m[1]);
const idRe = /(?<![\w$.])([A-Za-z_$][\w$]{1,30})(?![\w$'"])/g;
let cnt = 0;
while ((m = idRe.exec(tpl))) { cnt++; if (cnt > 30000) break; used.add(m[1]); }

const builtin = new Set(['true','false','null','undefined','Math','JSON','Date','Number','String','Boolean','window','document','console','in','of','typeof','if','else','return','NaN','Object','Array','parseFloat','parseInt','row','item','scope','slot','form','row1','btn']);
const missing = [...used].filter(u => !exported.has(u) && !builtin.has(u) && !u.startsWith('el') && !u.startsWith('El') && u !== 'v' && u !== 'tpl');
console.log('\n=== 模板引用但未导出（渲染崩溃候选）===');
console.log(missing.sort().join(', ') || '无');
