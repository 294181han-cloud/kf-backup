// visitor.html 模板编译（@vue/compiler-dom）
const fs = require('fs');
const path = require('path');
const { compile } = require('@vue/compiler-dom');
const root = process.argv[2];
const html = fs.readFileSync(path.join(root, 'public/visitor.html'), 'utf-8');

const appStart = html.indexOf('<div id="app"');
const scriptStart = html.indexOf('<script>');
const tpl = html.slice(appStart, scriptStart);
console.log('模板长度:', tpl.length);

const errors = [];
compile(tpl, { onError(e) { errors.push(e); } });
console.log('编译错误数:', errors.length);
errors.slice(0, 8).forEach(e => {
  const loc = e.loc && e.loc.start ? `行${e.loc.start.line}` : '';
  console.log(`  [${e.code}] ${loc} ${e.message.slice(0, 250)}`);
  if (e.loc && e.loc.start) {
    const ln = e.loc.start.line; const lines = tpl.split('\n');
    for (let i = Math.max(0, ln - 3); i < Math.min(lines.length, ln + 2); i++)
      console.log((i + 1 === ln ? '   >>> ' : '       ') + (i + 1) + ': ' + lines[i].slice(0, 150));
  }
});
if (!errors.length) console.log('visitor 模板编译无错误 ✓');

// 主 JS 语法（new Function 只解析不执行顶层？会执行——用 vm compileScript 太重，用 Function 构造器仅构造）
const jsM = /<script>\n([\s\S]*?)\n<\/script>\s*<\/body>/.exec(html);
if (jsM) {
  try { new Function(jsM[1]); console.log('主 JS 语法 OK'); }
  catch (e) { console.log('主 JS 语法错误:', e.message.slice(0, 300)); }
}
