// 检查 app.html 样式表解析后，获客码相关规则是否真实存在
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const root = process.argv[2];
const html = fs.readFileSync(path.join(root, 'public/app.html'), 'utf-8');
const vc = new (require('jsdom').VirtualConsole)();
const dom = new JSDOM(html, { virtualConsole: vc, runScripts: undefined });

const needed = ['code-page-card','code-tabs','code-body','code-img-panel','code-set-panel','cip-title','qr-stage','qr-wrap','qr-logo','qr-footnote','cip-btns','cip-note','cs-row','cs-label','cs-btns','quick-bar','quick-btn','quick-toggle-btn','geo-tag','crop-stage','side-hero','tag-agent'];
const found = new Set();
let totalRules = 0;
const sheets = dom.window.document.styleSheets;
for (let s = 0; s < sheets.length; s++) {
  let rules;
  try { rules = sheets[s].cssRules; } catch (e) { console.log(`styleSheet[${s}] 读取失败:`, e.message); continue; }
  totalRules += rules ? rules.length : 0;
  if (!rules) continue;
  for (const rule of rules) {
    if (rule.selectorText) {
      for (const need of needed) {
        if (rule.selectorText.includes(need)) found.add(need);
      }
    }
  }
}
console.log('样式表数:', sheets.length, ' 规则总数:', totalRules);
console.log('\n=== 获客码/新功能规则检查 ===');
let miss = 0;
for (const need of needed) {
  const has = found.has(need);
  if (!has) miss++;
  console.log(`  ${need}: ${has ? 'OK' : '缺失!'}`);
}
console.log(miss === 0 ? '\n全部规则存在 ✓' : `\n${miss} 条规则缺失`);
