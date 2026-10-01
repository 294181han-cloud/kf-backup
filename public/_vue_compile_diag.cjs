// 用 @vue/compiler-dom 正规编译 app.html 模板，收集全部错误
const fs = require('fs');
const path = require('path');
const { compile } = require('@vue/compiler-dom');
const root = process.argv[2];
const html = fs.readFileSync(path.join(root, 'public/app.html'), 'utf-8');

const appStart = html.indexOf('<div id="app"');
const scriptStart = html.indexOf('<script src="/assets/vendor/vue.global.js');
const tpl = html.slice(appStart, scriptStart);
console.log('模板长度:', tpl.length);

const errors = [];
const result = compile(tpl, {
  onError(e) { errors.push(e); },
});
console.log('编译错误数:', errors.length);
errors.slice(0, 10).forEach(e => {
  const loc = e.loc && e.loc.start ? `行${e.loc.start.line}` : '';
  console.log(`  [${e.code}] ${loc} ${e.message.slice(0, 250)}`);
  if (e.loc && e.loc.start) {
    const ln = e.loc.start.line;
    const lines = tpl.split('\n');
    for (let i = Math.max(0, ln - 3); i < Math.min(lines.length, ln + 2); i++) {
      console.log((i + 1 === ln ? '   >>> ' : '       ') + (i + 1) + ': ' + lines[i].slice(0, 150));
    }
  }
});
if (!errors.length) console.log('模板编译无错误 ✓');
