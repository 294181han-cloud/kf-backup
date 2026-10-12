/* rollback-all.js —— 把 12 个门牌站全部回滚到「10-10 那份能正常渲染的部署」
   回滚走 POST /sites/<id>/deploys/<deploy_id>/restore（不算新部署，实测不受额度封禁影响）。
   回滚后逐个复核：content-type 必须是 text/html。 */
const https = require('https');
const TOKEN = process.argv[2];
if (!TOKEN) { console.error('用法: node rollback-all.js <TOKEN>'); process.exit(1); }

function req(method, url) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    https.request({ method, hostname: u.hostname, path: u.pathname + u.search,
      headers: { Authorization: 'Bearer ' + TOKEN, 'User-Agent': 'kf-door' } }, res => {
      const chunks = []; res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, body: Buffer.concat(chunks).toString() }));
    }).on('error', reject).end();
  });
}
function get(url) {
  return new Promise((resolve, reject) => {
    https.get(url, { headers: { 'User-Agent': 'Mozilla/5.0 (iPhone) MicroMessenger/8.0.49' } }, r => {
      if (r.statusCode >= 300 && r.statusCode < 400 && r.headers.location) return resolve(get(r.headers.location));
      const chunks = []; r.on('data', c => chunks.push(c));
      r.on('end', () => resolve({ status: r.statusCode, ct: r.headers['content-type'] || '', body: Buffer.concat(chunks).toString() }));
    }).on('error', reject);
  });
}

(async () => {
  const sites = JSON.parse((await req('GET', 'https://api.netlify.com/api/v1/sites?per_page=100')).body);
  console.log('站点数:', sites.length, '\n');
  let done = 0, skip = 0, fail = 0;
  for (const s of sites) {
    const dels = JSON.parse((await req('GET', `https://api.netlify.com/api/v1/sites/${s.id}/deploys?per_page=6`)).body);
    const mine = dels.find(x => x.id.startsWith('6acca3'));           // 我今天部署的
    const prev = dels.find(x => !x.id.startsWith('6acca3'));          // 原来那份
    if (!mine) { console.log(`  ${s.name.padEnd(34)} 没有我的部署，跳过`); skip++; continue; }
    if (!prev) { console.log(`  ${s.name.padEnd(34)} ⚠ 找不到可回滚的旧部署`); fail++; continue; }
    const r = await req('POST', `https://api.netlify.com/api/v1/sites/${s.id}/deploys/${prev.id}/restore`);
    if (r.status === 200) { console.log(`  ${s.name.padEnd(34)} ✅ 已回滚到 ${prev.id} (${prev.created_at})`); done++; }
    else { console.log(`  ${s.name.padEnd(34)} ❌ HTTP ${r.status}: ${r.body.slice(0, 120)}`); fail++; }
  }
  console.log(`\n汇总：已回滚 ${done}，跳过 ${skip}，失败 ${fail}`);

  console.log('\n等 15 秒后复核 content-type：');
  await new Promise(r => setTimeout(r, 15000));
  let ok = 0;
  for (const s of sites) {
    try {
      const l = await get(s.url || `https://${s.name}.netlify.app/`);
      const good = /text\/html/.test(l.ct);
      if (good) ok++;
      console.log(`  ${s.name.padEnd(34)} ${good ? '✔' : '✘'} ${l.ct.padEnd(30)} 长度=${l.body.length}`);
    } catch (e) { console.log(`  ${s.name.padEnd(34)} ✘ 取不到: ${e.message}`); }
  }
  console.log(`\n正常渲染(text/html)的有 ${ok}/${sites.length} 个`);
})();
