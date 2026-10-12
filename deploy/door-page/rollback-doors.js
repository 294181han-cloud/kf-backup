/* rollback-doors.js —— 看各站部署历史，并尝试回滚到「我改之前的那个部署」
   用法: node rollback-doors.js <TOKEN> [--list] [--rollback=站点名或id]
   说明：回滚也是一次"部署"，若账号额度被封可能同样 403 —— 本脚本会如实报告。 */
const https = require('https');
const TOKEN = process.argv[2];
const LIST = process.argv.includes('--list');
const RB = (process.argv.find(a => a.startsWith('--rollback=')) || '').replace('--rollback=', '');
if (!TOKEN) { console.error('用法: node rollback-doors.js <TOKEN> [--list] [--rollback=名字]'); process.exit(1); }

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

(async () => {
  const sites = JSON.parse((await req('GET', 'https://api.netlify.com/api/v1/sites?per_page=100')).body);
  const pick = RB ? sites.filter(s => (s.name || '').includes(RB) || s.id === RB) : sites;
  for (const s of pick) {
    const d = JSON.parse((await req('GET', `https://api.netlify.com/api/v1/sites/${s.id}/deploys?per_page=6`)).body);
    console.log(`\n${s.name}  (${s.id})`);
    d.forEach((x, i) => {
      const mine = x.id.startsWith('6acca3');   /* 我这次批量部署的 id 前缀 */
      console.log(`  [${i}] ${x.id}  ${String(x.state).padEnd(10)} ${x.created_at}  published=${x.published_at || '-'}  ${mine ? '← 我这次部署的' : ''}`);
    });
    if (RB) {
      const prev = d.find(x => !x.id.startsWith('6acca3'));
      if (!prev) { console.log('  没有可回滚的历史部署'); continue; }
      console.log(`  → 尝试回滚到 ${prev.id} (${prev.created_at})`);
      const r = await req('POST', `https://api.netlify.com/api/v1/sites/${s.id}/deploys/${prev.id}/restore`);
      console.log(`     结果 HTTP ${r.status}: ${r.body.slice(0, 220)}`);
    }
  }
  if (!LIST && !RB) console.log('\n（加 --rollback=站点名 才会真的回滚；--list 只看历史）');
})();
