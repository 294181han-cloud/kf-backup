<?php
/** 分控节点更新器（cron/cron-node.sh 调用）：从总控拉取更新包并应用
 *  无需 git / shell_exec；config.php、data/、uploads/ 永不被覆盖 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require __DIR__ . '/../helpers.php';

$cfg = config();
$main = rtrim((string)($cfg['site_main_url'] ?? ''), '/');
$secret = (string)($cfg['relay_secret'] ?? '');
if ($main === '' || $secret === '') { echo "未配置 site_main_url / relay_secret\n"; exit(1); }

function http_post_json(string $url, array $body): array
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
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 120, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1]; }
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) throw new RuntimeException('总控无响应(' . $code . ')');
    return $j;
}

try {
    $j = http_post_json($main . '/api/internal/update', [
        'secret' => $secret, 'app_version' => app_version(), 'role' => (string)($cfg['site_role'] ?? ''),
    ]);
    if (!empty($j['up_to_date'])) { echo "已是最新版本 {$j['version']}\n"; exit(0); }
    $r = apply_node_bundle($j);
    echo "已更新到 {$r['app_version']}（变更 {$r['changed']} 个文件）\n";
    // 顺手心跳登记
    try { http_post_json($main . '/api/internal/heartbeat', ['secret' => $secret, 'role' => (string)($cfg['site_role'] ?? ''), 'app_version' => $r['app_version'], 'origin' => $main]); } catch (Throwable $e) {}
} catch (Throwable $e) {
    echo '更新失败: ' . $e->getMessage() . "\n";
    exit(1);
}
