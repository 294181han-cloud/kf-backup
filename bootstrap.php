<?php
/**
 * bootstrap.php — 分控节点单文件网页安装器（v2.38，1+2+n 枢纽模式）
 *
 * 用法：新节点服务器装好 nginx + PHP 后，把本文件放进站点根目录，
 *       浏览器打开即可：环境自检 → 填主站地址 + 节点授权码 + 选角色
 *       → 向总控验证（一次性授权码）→ 从总控拉取全部代码 → 生成配置 → 自删。
 * 也支持命令行：php bootstrap.php 主站地址 授权码 agent|visitor
 *
 * 安全：授权码一次性（主控生成，用后即焚）；安装完成本文件自删除；
 *       节点 config.php 不含数据库凭证（读写全部经总控）。
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(300);

const PROTECTED_PREFIX = ['config.php', 'data/', 'uploads/'];   // 永不写入的路径


$step = 'form';           // form | done | error | installed
$error = '';
$results = [];
$checks = null;
$mainUrl = trim((string)($_POST['main_url'] ?? ($_SERVER['argv'][1] ?? '')));
$authCode = strtoupper(trim((string)($_POST['auth_code'] ?? ($_SERVER['argv'][2] ?? ''))));
$role = (string)($_POST['role'] ?? ($_SERVER['argv'][3] ?? 'visitor'));
$role = $role === 'agent' ? 'agent' : 'visitor';
$origin = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$nginxSnippet = '';
        $cronLine = "*/5 * * * * php " . __DIR__ . "/node/update-node.php && curl -fsS -m 20 -X POST {$mainUrl}/api/internal/sync -H 'Content-Type: application/json' -d '{\"secret\":\"$relaySecret\"}' -o " . __DIR__ . "/data/blcache.json";

function http_post_json(string $url, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 180, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1]; }
        $err = $resp === false ? '网络请求失败' : '';
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) throw new RuntimeException('总控无响应(' . $code . ') ' . $err);
    return $j;
}

function env_checks(): array
{
    $dataOk = is_dir(__DIR__ . '/data') ? is_writable(__DIR__ . '/data') : is_writable(__DIR__);
    $gitOut = function_exists('shell_exec') ? trim((string)@shell_exec('git --version 2>/dev/null')) : '';
    return [
        ['PHP ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
        ['curl 扩展', function_exists('curl_init'), function_exists('curl_init') ? '可用' : '缺失（必需）'],
        ['data/ 目录可写', $dataOk, $dataOk ? '可写' : '不可写（必需）'],
        ['git（可选，cron 备用）', $gitOut !== '', $gitOut ?: '未安装（可后续补装）'],
    ];
}

$isCli = PHP_SAPI === 'cli';
$installed = is_file(__DIR__ . '/config.php');

if ($isCli) {
    // 命令行模式：php bootstrap.php <主站地址> <授权码> <agent|visitor>
    if (!$mainUrl || !$authCode) { echo "用法: php bootstrap.php <主站地址> <授权码> <agent|visitor>\n"; exit(1); }
} elseif ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
    $checks = env_checks();
}

if (($isCli || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && !$installed && $mainUrl !== '' && $authCode !== '') {
    try {
        if (!preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#i', $mainUrl)) throw new RuntimeException('主站地址格式不正确');
        if (!preg_match('/^[A-Z0-9]{8,32}$/', $authCode)) throw new RuntimeException('授权码格式不正确（8~32 位字母数字）');
        if (!is_writable(__DIR__)) throw new RuntimeException('站点目录不可写');

        $results[] = '向总控验证授权码并登记节点（IP ' . ($_SERVER['REMOTE_ADDR'] ?? $origin) . '）…';
        $bundle = http_post_json($mainUrl . '/api/internal/node-register', [
            'auth_code' => $authCode, 'role' => $role, 'origin' => $origin,
        ]);
        if (empty($bundle['ok'])) throw new RuntimeException((string)($bundle['error'] ?? '授权验证失败'));
        $relaySecret = (string)($bundle['relay_secret'] ?? '');
        if ($relaySecret === '') throw new RuntimeException('总控未返回中转密钥（relay_secret 未配置）');
        $results[] = '✓ 已登记并拉入边缘白名单（角色：' . $role . '）';

        $results[] = '从总控拉取代码更新包…';
        $codeBundle = http_post_json($mainUrl . '/api/internal/update', [
            'secret' => $relaySecret, 'app_version' => '0', 'role' => $role,
        ]);
        $files = (array)($codeBundle['files'] ?? []);
        if (!$files) throw new RuntimeException('总控未返回代码包');
        $results[] = '✓ 收到 ' . count($files) . ' 个文件（v' . ($codeBundle['version'] ?? '?') . '），写入中…';

        $written = 0;
        foreach ($files as $path => $b64) {
            $path = (string)$path;
            foreach (PROTECTED_PREFIX as $bad) { if (str_starts_with($path, $bad)) continue 2; }
            if (str_contains($path, '..')) continue;
            $full = __DIR__ . '/' . $path;
            $d = dirname($full);
            if (!is_dir($d)) @mkdir($d, 0755, true);
            $bin = base64_decode((string)$b64, true);
            if ($bin === false || @file_put_contents($full, $bin) === false) throw new RuntimeException('写入失败: ' . $path);
            $written++;
        }
        $results[] = "✓ 代码写入完成（{$written} 个文件）";

        if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0755, true);
        $cfg = "<?php\n/** config.php — 分控节点配置（" . $role . "，bootstrap 安装于 " . date('Y-m-d H:i:s') . "） */\n"
            . "declare(strict_types=1);\n\nreturn [\n"
            . "    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'kfxt', 'user' => '', 'pass' => '', 'charset' => 'utf8mb4'],\n"
            . "    'jwt_ttl' => 60 * 60 * 24 * 7,\n"
            . "    'upload_dir' => __DIR__ . '/uploads',\n"
            . "    'upload_url' => '/uploads',\n"
            . "    'upload_max_image' => 5 * 1024 * 1024,\n"
            . "    'upload_max_audio' => 10 * 1024 * 1024,\n"
            . "    'rate_limit_per_min' => 30,\n"
            . "    'login_max_fails' => 10,\n"
            . "    'login_lock_secs' => 900,\n"
            . "    'session_online_secs' => 25,\n"
            . "    'data_dir' => __DIR__ . '/data',\n"
            . "    'site_role' => '" . $role . "',\n"
            . "    'site_main_url' => '" . addslashes($mainUrl) . "',\n"
            . "    'relay_secret' => '" . addslashes($relaySecret) . "',\n"
            . "];\n";
        if (@file_put_contents(__DIR__ . '/config.php', $cfg) === false) throw new RuntimeException('config.php 写入失败');
        $results[] = '✓ 节点配置已生成（无数据库凭证，读写经总控）';

        try {
            $sync = http_post_json($mainUrl . '/api/internal/sync', ['secret' => $relaySecret]);
            @file_put_contents(__DIR__ . '/data/blcache.json', json_encode($sync, JSON_UNESCAPED_UNICODE));
            $results[] = '✓ 黑名单首次同步完成';
        } catch (Throwable $e) { $results[] = '⚠ 黑名单同步暂失败（cron 会自动重试）'; }

        $host = parse_url($mainUrl, PHP_URL_HOST) ?: '主站域名';
        $edgeSecret = $relaySecret;
        $nginxSnippet = "location / {\n"
            . "    if (!-e $request_filename) { rewrite ^(.*)$ /index.php?s=$1 last; break; }\n"
            . "}\n"
            . "location /api/ {\n"
            . "    proxy_pass {$mainUrl}/api/;\n"
            . "    proxy_set_header Host {$host};\n"
            . "    proxy_set_header X-Forwarded-For $remote_addr;\n"
            . "    proxy_set_header X-Edge-Secret {$edgeSecret};\n"
            . "    proxy_set_header X-Edge-Role {$role};\n"
            . "    proxy_set_header X-Edge-Origin {$origin};\n"
            . "    proxy_ssl_server_name on;\n"
            . "}";
        // 直接写成文件：粘贴伪静态时打开 nginx-edge.conf 复制整段即可
        @file_put_contents(__DIR__ . '/nginx-edge.conf', "# /api/ 反代到总控 + 伪静态（整段加入本站点 server 块，替换原 location /）\n" . $nginxSnippet . "\n");
        // PHP 环境加固（open_basedir 指向本站点）
        @file_put_contents(__DIR__ . '/.user.ini', "open_basedir=" . __DIR__ . ":/tmp/\n");
        $cronLine = "*/5 * * * * php " . __DIR__ . "/node/update-node.php && curl -fsS -m 20 -X POST {$mainUrl}/api/internal/sync -H 'Content-Type: application/json' -d '{\"secret\":\"$relaySecret\"}' -o " . __DIR__ . "/data/blcache.json";
        $step = 'done';
        $results[] = '✓ 安装器自删除';
        if (!$isCli) { @unlink(__FILE__); }
        else { @unlink(__FILE__); }
    } catch (Throwable $e) {
        $step = 'error';
        $error = $e->getMessage();
    }
}
if ($isCli) {
    foreach ($results as $r) echo $r . "\n";
    if ($step === 'done') { echo "安装完成。访问 {$origin}/api/health 验收。\n"; exit(0); }
    if ($step === 'error') { echo "安装失败：{$error}\n"; exit(1); }
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>节点安装 - 客服系统</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
  background: linear-gradient(165deg, #dfeafb 0%, #c9dbf3 45%, #b3cdec 100%);
  padding: 24px;
}
.card { width: 100%; max-width: 560px; background: #fff; border-radius: 20px; padding: 38px 42px 32px; box-shadow: 0 24px 70px rgba(43,90,160,.18); }
h1 { font-size: 22px; color: #14181f; margin-bottom: 4px; }
.sub { font-size: 13px; color: #98a2b3; margin-bottom: 22px; }
.f-label { font-size: 14px; color: #333; margin: 16px 0 6px; font-weight: 500; }
input[type=text], select { width: 100%; height: 44px; border: 1px solid #dcdfe6; border-radius: 10px; padding: 0 14px; font-size: 15px; outline: none; background: #fff; }
input[type=text]:focus, select:focus { border-color: #2b6fff; }
.roles { display: flex; gap: 10px; }
.roles label { flex: 1; border: 1px solid #dcdfe6; border-radius: 10px; padding: 12px 14px; cursor: pointer; font-size: 14px; }
.roles label.on { border-color: #2b6fff; background: #eef4ff; }
.roles b { display: block; font-size: 15px; margin-bottom: 2px; }
.roles small { color: #98a2b3; }
.btn { width: 100%; height: 48px; margin-top: 22px; border: none; border-radius: 12px; background: #2b6fff; color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; }
.btn:disabled { opacity: .6; }
.err { background: #fef0f0; border: 1px solid #fbc4c4; color: #c45656; border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 14px; }
.okbox { background: #f0f9eb; border: 1px solid #b3e19d; color: #529b2e; border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 14px; }
.locked { text-align: center; padding: 20px 0; }
.locked .big { font-size: 40px; margin-bottom: 10px; }
.checks { font-size: 13px; color: #4e5969; }
.checks div { padding: 4px 0; display: flex; justify-content: space-between; border-bottom: 1px dashed #eef0f3; }
.bad { color: #c45656; }
.log { background: #f7f9fc; border: 1px solid #e5e9f0; border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #333; margin-bottom: 14px; line-height: 1.9; }
textarea { width: 100%; height: 110px; font-family: Consolas, monospace; font-size: 12px; border: 1px solid #dcdfe6; border-radius: 10px; padding: 10px; color: #333; }
.hint { font-size: 12px; color: #8f959e; margin-top: 10px; line-height: 1.7; }
.mono { font-family: Consolas, monospace; word-break: break-all; }
</style>
</head>
<body>
<div class="card">
  <h1>分控节点安装</h1>
  <div class="sub">客服系统 · 从总控一键拉取代码完成部署（无需 git / SSH）</div>
<?php if ($installed): ?>
  <div class="locked">
    <div class="big">🔒</div>
    <div style="font-size:16px;font-weight:600;color:#14181f;">本节点已完成安装</div>
    <div class="hint">检测到 config.php 已存在。后续更新由节点 cron 自动从总控拉取；如需重装请先删除 config.php。</div>
  </div>
<?php elseif ($step === 'done'): ?>
  <div class="okbox">✅ 安装完成！节点已登记到主控并进入边缘白名单（角色：<?= $role === 'agent' ? '客服入口' : '访客站点' ?>，代码 v<?= htmlspecialchars($codeBundle['version'] ?? '') ?>）。</div>
  <div class="log"><?php foreach ($results as $r) echo htmlspecialchars($r) . '<br>'; ?></div>
  <div class="f-label">nginx 配置（/api/ 反代到总控）</div>
  <textarea readonly onclick="this.select()"><?= htmlspecialchars($nginxSnippet) ?></textarea>
  <div class="f-label">黑名单同步 cron（crontab -e 添加）</div>
  <textarea readonly onclick="this.select()"><?= htmlspecialchars($cronLine) ?></textarea>
  <div class="hint">完成后访问 <span class="mono">/api/health</span> 验收（site_role 应为 <?= $role ?>）。主控「节点管理」页已能看到本节点。</div>
<?php elseif ($step === 'error'): ?>
  <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($results): ?><div class="log"><?php foreach ($results as $r) echo htmlspecialchars($r) . '<br>'; ?></div><?php endif; ?>
  <?php $checks = $checks ?: env_checks(); ?>
  <div class="checks"><?php foreach ($checks as $c): ?><div><span><?= htmlspecialchars($c[0]) ?></span><span class="<?= $c[1] ? '' : 'bad' ?>"><?= htmlspecialchars(($c[1] ? '✓ ' : '✗ ') . $c[2]) ?></span></div><?php endforeach; ?></div>
  <form method="post" autocomplete="off">
    <div class="f-label">主站地址</div>
    <input type="text" name="main_url" placeholder="如 https://ad.yluu.top" value="<?= htmlspecialchars($mainUrl) ?>">
    <div class="f-label">节点授权码（主控「节点管理」页生成，一次性）</div>
    <input type="text" name="auth_code" placeholder="8~32 位字母或数字" value="<?= htmlspecialchars($authCode) ?>" style="letter-spacing:1px;">
    <div class="f-label">节点角色</div>
    <div class="roles">
      <label class="<?= $role === 'visitor' ? 'on' : '' ?>"><input type="radio" name="role" value="visitor" hidden <?= $role === 'visitor' ? 'checked' : '' ?> onchange="this.closest('form').querySelectorAll('.roles label').forEach(l=>l.classList.remove('on'));this.closest('label').classList.add('on');"><b>访客站点</b><small>对外提供访客聊天入口</small></label>
      <label class="<?= $role === 'agent' ? 'on' : '' ?>"><input type="radio" name="role" value="agent" hidden <?= $role === 'agent' ? 'checked' : '' ?> onchange="this.closest('form').querySelectorAll('.roles label').forEach(l=>l.classList.remove('on'));this.closest('label').classList.add('on');"><b>客服入口</b><small>给客服登录工作台用</small></label>
    </div>
    <button class="btn" type="submit">开始安装</button>
    <div class="hint">安装器会用授权码向总控验证，随后自动拉取全部代码并生成配置，完成后本文件自删除。</div>
  </form>
<?php else: ?>
  <div class="checks"><?php foreach (($checks ?: env_checks()) as $c): ?><div><span><?= htmlspecialchars($c[0]) ?></span><span class="<?= $c[1] ? '' : 'bad' ?>"><?= htmlspecialchars(($c[1] ? '✓ ' : '✗ ') . $c[2]) ?></span></div><?php endforeach; ?></div>
  <form method="post" autocomplete="off">
    <div class="f-label">主站地址</div>
    <input type="text" name="main_url" placeholder="如 https://ad.yluu.top" value="<?= htmlspecialchars($mainUrl) ?>">
    <div class="f-label">节点授权码（主控「节点管理」页生成，一次性）</div>
    <input type="text" name="auth_code" placeholder="8~32 位字母或数字" value="<?= htmlspecialchars($authCode) ?>" style="letter-spacing:1px;">
    <div class="f-label">节点角色</div>
    <div class="roles">
      <label class="<?= $role === 'visitor' ? 'on' : '' ?>"><input type="radio" name="role" value="visitor" hidden <?= $role === 'visitor' ? 'checked' : '' ?> onchange="this.closest('form').querySelectorAll('.roles label').forEach(l=>l.classList.remove('on'));this.closest('label').classList.add('on');"><b>访客站点</b><small>对外提供访客聊天入口</small></label>
      <label class="<?= $role === 'agent' ? 'on' : '' ?>"><input type="radio" name="role" value="agent" hidden <?= $role === 'agent' ? 'checked' : '' ?> onchange="this.closest('form').querySelectorAll('.roles label').forEach(l=>l.classList.remove('on'));this.closest('label').classList.add('on');"><b>客服入口</b><small>给客服登录工作台用</small></label>
    </div>
    <button class="btn" type="submit">开始安装</button>
    <div class="hint">安装器会用授权码向总控验证，随后自动拉取全部代码并生成配置，完成后本文件自删除。</div>
  </form>
<?php endif; ?>
</div>
</body>
</html>
