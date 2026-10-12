/* update-doors.js —— 用 Netlify Personal Access Token 批量把门牌站更新成"会听 ready 的新版 index.html"
   用法:  node update-doors.js <TOKEN> [--dry] [--only=域名1,域名2]

   做的事：
   1) GET /api/v1/sites           → 列出账号下所有站点
   2) 逐个 GET 站点线上 index.html → 判断是否缺 message 监听（含 '_kfDoor' 标记 = 已新版）
   3) 缺的 → 用 zip 部署一次（PATCH 不需要，POST /api/v1/sites/<id>/deploys 带 zip）
   4) 复核线上内容是否已带新监听；打印前后对比

   安全：脚本只读站点列表 + 往你自己的站点部署文件，不删除任何站点。 */
const fs = require('fs');
const path = require('path');
const https = require('https');
const { execFileSync } = require('child_process');

const TOKEN = process.argv[2];
const DRY = process.argv.includes('--dry');
const ONLY = (process.argv.find(a => a.startsWith('--only=')) || '').replace('--only=', '');
if (!TOKEN) { console.error('用法: node update-doors.js <TOKEN> [--dry]'); process.exit(1); }

const DOOR_HTML = path.resolve(__dirname, '..', 'door-index.html');
const MARK = '_kfDoor';   // 新版标记（模板里带的注释/变量）

function req(method, url, { headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const r = https.request({
      method, hostname: u.hostname, path: u.pathname + u.search,
      headers: { Authorization: 'Bearer ' + TOKEN, ...headers },
    }, res => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) }));
    });
    r.on('error', reject);
    if (body) r.write(body);
    r.end();
  });
}

async function listSites() {
  const out = [];
  let page = 1;
  for (;;) {
    const r = await req('GET', `https://api.netlify.com/api/v1/sites?per_page=100&page=${page}`);
    if (r.status !== 200) throw new Error('列站点失败 HTTP ' + r.status + ': ' + r.body.toString().slice(0, 200));
    const arr = JSON.parse(r.body.toString());
    out.push(...arr);
    if (arr.length < 100) break;
    page++;
  }
  return out;
}

async function fetchLive(url) {
  try {
    const r = await req('GET', url, { headers: { Authorization: '' } });   // 公开页面，不需要 token
    return r.status === 200 ? r.body.toString() : '';
  } catch (e) { return ''; }
}

function makeZip(html) {
  /* 用系统 tar 造 zip（Windows 10+ 自带 tar 支持 -a 自动识别格式） */
  const dir = fs.mkdtempSync(path.join(process.env.TEMP || '/tmp', 'kfdoor-'));
  fs.writeFileSync(path.join(dir, 'index.html'), html, 'utf8');
  const zip = path.join(dir, 'site.zip');
  execFileSync('tar', ['-a', '-c', '-f', zip, 'index.html'], { cwd: dir });
  return { zip, dir };
}

async function deployZip(siteId, zipPath) {
  const zipBuf = fs.readFileSync(zipPath);
  const boundary = '----kfdoor' + Date.now();
  const head = Buffer.from(
    `--${boundary}\r\n` +
    `Content-Disposition: form-data; name="zip"; filename="site.zip"\r\n` +
    `Content-Type: application/zip\r\n\r\n`);
  const tail = Buffer.from(`\r\n--${boundary}--\r\n`);
  const body = Buffer.concat([head, zipBuf, tail]);
  return req('POST', `https://api.netlify.com/api/v1/sites/${siteId}/deploys`, {
    headers: { 'Content-Type': 'multipart/form-data; boundary=' + boundary, 'Content-Length': body.length },
    body,
  });
}

(async () => {
  const html = fs.readFileSync(DOOR_HTML, 'utf8');
  if (!html.includes(MARK)) { console.error('❌ 模板里找不到标记 ' + MARK + '，确认 door-index.html 是新版'); process.exit(1); }

  const sites = await listSites();
  const targets = ONLY ? sites.filter(s => ONLY.split(',').some(d => (s.name || '').includes(d) || (s.custom_domain || '').includes(d) || (s.url || '').includes(d))) : sites;
  console.log(`账号下共 ${sites.length} 个站点，本次处理 ${targets.length} 个${ONLY ? '（按 --only 过滤）' : ''}\n`);

  let fixed = 0, ok = 0, fail = 0;
  for (const s of targets) {
    const live = await fetchLive(s.url || ('https://' + s.name + '.netlify.app/'));
    const isDoor = /缺少跳转地址|getParam\('xx'\)|b64decode/.test(live);
    const isNew = live.includes(MARK);
    let state = isNew ? '已是新版' : (isDoor ? '旧版门牌 → 需要更新' : (live ? '不是门牌页？跳过' : '取不到内容'));
    console.log(`  ${(s.name || s.id).padEnd(34)} ${state}   ${s.url || ''}`);
    if (isNew || !isDoor) { isNew ? ok++ : 0; continue; }
    if (DRY) { console.log('     (--dry 模式，不实际部署)'); continue; }
    try {
      const { zip, dir } = makeZip(html);
      const r = await deployZip(s.id, zip);
      if (r.status >= 200 && r.status < 300) {
        const j = JSON.parse(r.body.toString());
        console.log(`     ✅ 已部署 deploy=${j.id} state=${j.state} url=${j.ssl_url || j.url}`);
        fixed++;
      } else {
        console.log(`     ❌ 部署失败 HTTP ${r.status}: ${r.body.toString().slice(0, 200)}`);
        fail++;
      }
      fs.rmSync(dir, { recursive: true, force: true });
    } catch (e) {
      console.log('     ❌ 异常: ' + e.message);
      fail++;
    }
  }
  console.log(`\n汇总：已更新 ${fixed}，本来就是新版 ${ok}，失败 ${fail}`);
  if (!DRY && fixed) {
    console.log('\n复核（等 5 秒后重新拉取）：');
    await new Promise(r => setTimeout(r, 5000));
    for (const s of targets) {
      const live = await fetchLive(s.url || ('https://' + s.name + '.netlify.app/'));
      console.log(`  ${(s.name || s.id).padEnd(34)} 含新监听=${live.includes(MARK) ? '✔' : '✘'}`);
    }
  }
})().catch(e => { console.error('失败: ' + e.message); process.exit(1); });
