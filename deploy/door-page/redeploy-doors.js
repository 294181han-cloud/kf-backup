/* redeploy-doors.js —— 修 Content-Type：用标准 zip（UTF-8 文件名）+ _headers 强制 text/html，重新部署
   用法: node redeploy-doors.js <TOKEN> [--dry] [--only=xxx] */
const fs = require('fs'), path = require('path'), https = require('https');
const { execFileSync } = require('child_process');

const TOKEN = process.argv[2];
const DRY = process.argv.includes('--dry');
const ONLY = (process.argv.find(a => a.startsWith('--only=')) || '').replace('--only=', '');
if (!TOKEN) { console.error('用法: node redeploy-doors.js <TOKEN> [--dry] [--only=xxx]'); process.exit(1); }

const SRC = path.resolve(__dirname, '..', 'door-index.html');
const MARK = '_kfDoor';

function req(method, url, { headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const r = https.request({ method, hostname: u.hostname, path: u.pathname + u.search,
      headers: { Authorization: 'Bearer ' + TOKEN, ...headers } }, res => {
      const chunks = []; res.on('data', c => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) }));
    });
    r.on('error', reject);
    if (body) r.write(body);
    r.end();
  });
}
function get(url) {
  return new Promise((resolve, reject) => {
    https.get(url, { headers: { 'User-Agent': 'Mozilla/5.0 (iPhone) MicroMessenger/8.0.49' } }, r => {
      if (r.statusCode >= 300 && r.statusCode < 400 && r.headers.location) return resolve(get(r.headers.location));
      const chunks = []; r.on('data', c => chunks.push(c));
      r.on('end', () => resolve({ status: r.statusCode, headers: r.headers, body: Buffer.concat(chunks).toString() }));
    }).on('error', reject);
  });
}

(async () => {
  const html = fs.readFileSync(SRC, 'utf8');
  if (!html.includes(MARK)) { console.error('模板缺少 ' + MARK); process.exit(1); }

  const sites = JSON.parse((await req('GET', 'https://api.netlify.com/api/v1/sites?per_page=100')).body.toString());
  const targets = ONLY ? sites.filter(s => (s.name || '').includes(ONLY) || (s.url || '').includes(ONLY)) : sites;
  console.log(`共 ${sites.length} 个站点，本次处理 ${targets.length} 个\n`);

  const dir = fs.mkdtempSync(path.join(process.env.TEMP || '/tmp', 'kfdoor2-'));
  fs.writeFileSync(path.join(dir, 'index.html'), html, 'utf8');
  fs.writeFileSync(path.join(dir, '_headers'), '/*\n  Content-Type: text/html; charset=UTF-8\n', 'utf8');

  /* 用 PowerShell 的 .NET ZipFile 打标准 zip（UTF-8 文件名 + 正确目录项）
     注意：zip 必须输出到被压缩目录之外，否则会自己锁住自己 */
  const outDir = fs.mkdtempSync(path.join(process.env.TEMP || '/tmp', 'kfdoorz-'));
  const zipPath = path.join(outDir, 'site.zip');
  const ps = `Add-Type -AssemblyName System.IO.Compression.FileSystem; ` +
    `[System.IO.Compression.ZipFile]::CreateFromDirectory('${dir.replace(/\\/g, '\\\\')}', '${zipPath.replace(/\\/g, '\\\\')}', [System.IO.Compression.CompressionLevel]::Optimal, $false)`;
  execFileSync('powershell.exe', ['-NoProfile', '-Command', ps], { stdio: 'inherit' });
  console.log('zip: ' + zipPath + ' (' + fs.statSync(zipPath).size + ' 字节)\n');

  async function deploy(siteId) {
    const zipBuf = fs.readFileSync(zipPath);
    const boundary = '----kfdoor' + Date.now();
    const head = Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="zip"; filename="site.zip"\r\nContent-Type: application/zip\r\n\r\n`);
    const tail = Buffer.from(`\r\n--${boundary}--\r\n`);
    return req('POST', `https://api.netlify.com/api/v1/sites/${siteId}/deploys`, {
      headers: { 'Content-Type': 'multipart/form-data; boundary=' + boundary },
      body: Buffer.concat([head, zipBuf, tail]),
    });
  }

  let fixed = 0, fail = 0;
  for (const s of targets) {
    const live = await get(s.url || `https://${s.name}.netlify.app/`).catch(() => null);
    const ct = live ? (live.headers['content-type'] || '') : '(取不到)';
    const needFix = !/text\/html/.test(ct);
    console.log(`  ${(s.name || s.id).padEnd(34)} content-type=${ct}  ${needFix ? '→ 需要修' : '正常'}`);
    if (!needFix || DRY) continue;
    try {
      const r = await deploy(s.id);
      if (r.status >= 200 && r.status < 300) { console.log('     ✅ 已重新部署 ' + JSON.parse(r.body.toString()).id); fixed++; }
      else { console.log('     ❌ HTTP ' + r.status + ' ' + r.body.toString().slice(0, 160)); fail++; }
    } catch (e) { console.log('     ❌ ' + e.message); fail++; }
  }
  console.log(`\n汇总：已修 ${fixed}，失败 ${fail}`);

  if (!DRY && fixed) {
    console.log('\n等待 20 秒后复核 content-type：');
    await new Promise(r => setTimeout(r, 20000));
    for (const s of targets) {
      const live = await get(s.url || `https://${s.name}.netlify.app/`).catch(() => null);
      const ct = live ? (live.headers['content-type'] || '') : '(取不到)';
      const hasMark = live ? live.body.includes(MARK) : false;
      console.log(`  ${(s.name || s.id).padEnd(34)} ${/text\/html/.test(ct) ? '✔' : '✘'} ${ct.padEnd(32)} 新监听=${hasMark ? '✔' : '✘'}`);
    }
  }
  fs.rmSync(dir, { recursive: true, force: true });
})().catch(e => { console.error('失败: ' + e.message); process.exit(1); });
