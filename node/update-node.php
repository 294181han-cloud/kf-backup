<?php
/**
 * update-node.php — 分控节点自更新器（自包含，无外部依赖）
 *
 * 用法（节点服务器上执行一次，之后加入 cron 每 5 分钟自动运行）：
 *   php node/update-node.php
 *
 * 行为：向总控检查版本 → 拉取更新包 → 应用（覆盖代码文件）
 *       config.php / data/ / uploads/ 永不覆盖；缺失的文件自动补齐
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
chdir(dirname(__DIR__));

if (!is_file(__DIR__ . '/../config.php')) { echo "config.php 不存在，请先运行网页安装器\n"; exit(1); }
$cfg = require __DIR__ . '/../config.php';
$main = rtrim((string)($cfg['site_main_url'] ?? ''), '/');
$secret = (string)($cfg['relay_secret'] ?? '');
if ($main === '' || $secret === '') { echo "config 缺少 site_main_url / relay_secret\n"; exit(1); }

function un_http_post(string $url, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 120, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1]; }
        $err = $resp === false ? '网络请求失败' : '';
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) throw new RuntimeException('总控无响应(' . $code . ') ' . $err);
    return $j;
}

$protected = static function (string $p): bool {
    return $p === 'config.php' || str_starts_with($p, 'data/') || str_starts_with($p, 'uploads/');
};

try {
    // 1) 检查版本并拉取更新包
    $j = un_http_post($main . '/api/internal/update', [
        'secret' => $secret, 'app_version' => (string)app_version(), 'role' => (string)($cfg['site_role'] ?? ''),
    ]);
    if (empty($j['files'])) {
        echo '已是最新版本 ' . ($j['version'] ?? app_version()) . "\n";
    } else {
        // 2) 应用更新包（受保护路径跳过）
        $files = (array)$j['files'];
        $changed = 0;
        $manifestFile = __DIR__ . '/data/node-manifest.json';
        $applied = is_file($manifestFile) ? (array)json_decode((string)file_get_contents($manifestFile), true) : [];
        foreach ($files as $path => $b64) {
            $path = (string)$path;
            if ($protected($path) || str_contains($path, '..')) continue;
            $full = __DIR__ . '/' . $path;
            $d = dirname($full);
            if (!is_dir($d)) @mkdir($d, 0755, true);
            $bin = base64_decode((string)$b64, true);
            if ($bin === false || @file_put_contents($full, $bin) === false) throw new RuntimeException('写入失败: ' . $path);
            $applied[$path] = 1;
            $changed++;
        }
        // 3) 清理总控已删除的文件
        foreach (array_keys($applied) as $old) {
            if ($protected($old)) continue;
            if (!isset($files[$old]) && is_file(__DIR__ . '/' . $old)) { @unlink(__DIR__ . '/' . $old); unset($applied[$old]); }
        }
        @file_put_contents($manifestFile, json_encode(array_keys($applied), JSON_UNESCAPED_UNICODE));
        echo "已更新到 {$j['version']}（变更 {$changed} 个文件）\n";
    }
    // 4) 心跳登记（IP+角色）
    $ip = get_client_ip();
    un_http_post($main . '/api/internal/heartbeat', [
        'secret' => $secret, 'role' => (string)($cfg['site_role'] ?? ''),
        'app_version' => app_version(), 'origin' => $main, 'ip' => $ip,
    ]);
    echo '心跳完成 ' . $ip . "\n";
} catch (Throwable $e) {
    echo '更新失败: ' . $e->getMessage() . "\n";
    exit(1);
}
