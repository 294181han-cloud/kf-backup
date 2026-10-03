<?php
/**
 * install.php — 分控节点网页安装器（v2.34，1+2+n 枢纽模式）
 *
 * 用途：新节点服务器部署好代码/PHP/nginx 后，浏览器打开本页完成初始化：
 *   ① 环境自检（PHP 版本 / curl / git / 目录可写）
 *   ② 填写 主站地址 + 节点授权码 + 选择角色（访客站点 / 客服入口）
 *   ③ 向主站验证授权码（一次性，用后即焚）→ 自动登记节点 + 拉入边缘白名单
 *   ④ 生成节点专属 config.php（边缘节点不含数据库凭证）+ 首次黑名单同步 + 自锁
 *
 * 安全：config.php 存在时本页自动锁定；授权码由主控「节点管理」页生成，一次性使用。
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$locked = is_file(__DIR__ . '/config.php');
$done = false;
$error = '';
$nginxSnippet = '';
$cronLine = '';
$checks = null;
$mainUrl = trim((string)($_POST['main_url'] ?? ''));
$authCode = strtoupper(trim((string)($_POST['auth_code'] ?? '')));
$role = (($_POST['role'] ?? 'visitor') === 'agent') ? 'agent' : 'visitor';

function i_env_check(): array
{
    $dataWritable = is_dir(__DIR__ . '/data') ? is_writable(__DIR__ . '/data') : is_writable(__DIR__);
    return [
        ['PHP ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
        ['curl 扩展', function_exists('curl_init'), function_exists('curl_init') ? '可用' : '缺失'],
        ['git 命令', trim((string)@shell_exec('git --version 2>/dev/null')) !== '', trim((string)@shell_exec('git --version 2>/dev/null')) ?: '缺失（cron 自动更新不可用）'],
        ['data/ 目录可写', $dataWritable, $dataWritable ? '可写' : '不可写'],
    ];
}

function i_http(string $url, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $resp = '';
    $code = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 20, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        }
        if ($resp === false) $err = '网络请求失败';
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) throw new RuntimeException('主站无响应(' . $code . ') ' . $err);
    return $j;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    try {
        if ($mainUrl === '' || !preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#i', $mainUrl)) throw new RuntimeException('主站地址格式不正确');
        if ($authCode === '' || !preg_match('/^[A-Z0-9]{8,32}$/', $authCode)) throw new RuntimeException('授权码格式不正确');
        $origin = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        // ① 授权码换节点凭证（总控校验 + 登记 + 拉入白名单）
        $bundle = i_http($mainUrl . '/api/internal/node-register', [
            'auth_code' => $authCode, 'role' => $role, 'origin' => $origin,
        ]);
        if (empty($bundle['ok'])) throw new RuntimeException((string)($bundle['error'] ?? '授权验证失败'));
        $relaySecret = (string)($bundle['relay_secret'] ?? '');
        if ($relaySecret === '') throw new RuntimeException('主站未启用中转密钥（relay_secret），请在主站 config.php 配置后重试');
        // ② 生成节点专属 config.php（边缘节点：无数据库凭证，读写全部经总控）
        $dir = __DIR__;
        $cfg = "<?php\n"
            . "/** config.php — 分控节点配置（" . $role . "，由网页安装器生成于 " . date('Y-m-d H:i:s') . "） */\n"
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
        if (!is_dir("$dir/data")) @mkdir("$dir/data", 0755, true);
        if (@file_put_contents("$dir/config.php", $cfg) === false) throw new RuntimeException('config.php 写入失败（目录不可写）');
        // ③ 首次黑名单同步（落到 data/blcache.json，供边缘本地拦截）
        try {
            $sync = i_http($mainUrl . '/api/internal/sync', ['secret' => $relaySecret]);
            @file_put_contents("$dir/data/blcache.json", json_encode($sync, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) { /* 同步失败不阻塞安装，cron 会重试 */ }
        $nginxSnippet = "location /api/ {\n"
            . "    proxy_pass {$mainUrl}/api/;\n"
            . "    proxy_set_header Host " . parse_url($mainUrl, PHP_URL_HOST) . ";\n"
            . "    proxy_set_header X-Forwarded-For \$remote_addr;\n"
            . "    proxy_ssl_server_name on;\n"
            . "}";
        $cronLine = "*/5 * * * * curl -fsS -m 20 -X POST {$mainUrl}/api/internal/sync -H 'Content-Type: application/json' -d '{\"secret\":\"$relaySecret\"}' -o {$dir}/data/blcache.json";
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
if (!$locked) $checks = i_env_check();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>节点安装 - 客服系统</title>
<link rel="stylesheet" href="/assets/vendor/element-plus.css">
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
input[type=text], select {
  width: 100%; height: 44px; border: 1px solid #dcdfe6; border-radius: 10px;
  padding: 0 14px; font-size: 15px; outline: none; background: #fff;
}
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
textarea { width: 100%; height: 110px; font-family: Consolas, monospace; font-size: 12px; border: 1px solid #dcdfe6; border-radius: 10px; padding: 10px; color: #333; }
.hint { font-size: 12px; color: #8f959e; margin-top: 10px; line-height: 1.7; }
.mono { font-family: Consolas, monospace; word-break: break-all; }
</style>
</head>
<body>
<div class="card">
  <h1>分控节点安装</h1>
  <div class="sub">客服系统 · 1 主站 + 2 客服入口 + n 访客站点</div>
<?php if ($locked): ?>
  <div class="locked">
    <div class="big">🔒</div>
    <div style="font-size:16px;font-weight:600;color:#14181f;">本节点已完成安装</div>
    <div class="hint">检测到 config.php 已存在。如需重装，请先在服务器上删除 config.php 再刷新本页。</div>
  </div>
<?php elseif ($done): ?>
  <div class="okbox">✅ 安装完成！节点已登记到主控并进入边缘白名单（角色：<?= $role === 'agent' ? '客服入口' : '访客站点' ?>）。</div>
  <div class="f-label">最后两步（nginx 侧）</div>
  <div class="hint">把下面片段加进本站点 nginx 的 server 块（/api/ 反代到总控），然后 reload nginx：</div>
  <textarea readonly onclick="this.select()"><?= htmlspecialchars($nginxSnippet) ?></textarea>
  <div class="f-label">黑名单定时同步（crontab -e 添加）</div>
  <textarea readonly onclick="this.select()"><?= htmlspecialchars($cronLine) ?></textarea>
  <div class="hint">完成后访问 <span class="mono">/api/health</span> 验收（site_role 应为 <?= $role ?>）。主控「节点管理」页会看到本节点。</div>
<?php else: ?>
  <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="checks">
    <?php foreach ($checks as $c): ?>
      <div><span><?= htmlspecialchars($c[0]) ?></span><span class="<?= $c[1] ? '' : 'bad' ?>"><?= htmlspecialchars($c[1] ? '✓ ' . $c[2] : '✗ ' . $c[2]) ?></span></div>
    <?php endforeach; ?>
  </div>
  <form method="post" autocomplete="off">
    <div class="f-label">主站地址</div>
    <input type="text" name="main_url" placeholder="如 https://kf.ylcz.cc" value="<?= htmlspecialchars($mainUrl) ?>">
    <div class="f-label">节点授权码（主控「节点管理」页生成，一次性）</div>
    <input type="text" name="auth_code" placeholder="8~32 位字母或数字" value="<?= htmlspecialchars($authCode) ?>" style="letter-spacing:1px;">
    <div class="f-label">节点角色</div>
    <div class="roles">
      <label class="<?= $role === 'visitor' ? 'on' : '' ?>"><input type="radio" name="role" value="visitor" hidden <?= $role === 'visitor' ? 'checked' : '' ?> onchange="this.closest('form').querySelectorAll('.roles label').forEach(l=>l.classList.remove('on'));this.closest('label').classList.add('on');"><b>访客站点</b><small>对外提供访客聊天入口</small></label>
      <label class="<?= $role === 'agent' ? 'on' : '' ?>"><input type="radio" name="role" value="agent" hidden <?= $role === 'agent' ? 'checked' : '' ?> onchange="this.closest('form').querySelectorAll('.roles label').forEach(l=>l.classList.remove('on'));this.closest('label').classList.add('on');"><b>客服入口</b><small>给客服登录工作台用</small></label>
    </div>
    <button class="btn" type="submit">开始安装</button>
    <div class="hint">安装器会用授权码向总控验证并自动登记本节点（含 IP 白名单），随后生成 config.php 并自锁。</div>
  </form>
<?php endif; ?>
</div>
</body>
</html>
