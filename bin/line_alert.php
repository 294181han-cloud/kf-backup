<?php
/**
 * bin/line_alert.php — 线路探针告警（v2.92.2）
 *
 * 干什么：读 line_probe（访客页自带探针上报），某台落地主机在最近 $windowMin 分钟内
 *         「新增」失败次数达到阈值 → 私聊提醒管理员（发的还是发卡机器人）。
 *
 * 为什么用「新增」而不是累计：line_probe.fail_count 是累计值、不会重置。
 *   直接拿累计值判断 → 一次历史故障会永远反复触发。所以按 (主机,地区,运营商) 记录
 *   「上次看到的累计值」（kv line_alert:seen:<md5>），只对增量告警。
 *   第一次看到某行时把当前累计值当基线（不告警），避免把历史数据当新故障。
 *
 * cron：每 10 分钟一次
 * 可调（kv）：line_alert_threshold 默认 3（窗口内新增失败数阈值）
 *            line_alert_window_min 默认 30（只看最近多少分钟内有活动的行）
 */
chdir(dirname(__DIR__));
require __DIR__ . '/../helpers.php';
require __DIR__ . '/../routes/notify.php';

$threshold = max(1, (int)kv_get('line_alert_threshold', '3'));
$windowMin = max(5, (int)kv_get('line_alert_window_min', '30'));
$dedupeSec = 1800;   // 同一台主机 30 分钟内最多提醒一条

$pdo = db();
$rows = $pdo->query('SELECT host, region, isp, ok_count, fail_count, last_ok, last_reason, last_ip, last_at
                     FROM line_probe WHERE fail_count > 0')->fetchAll();

$freshCut = time() - $windowMin * 60;
$byHost = [];
foreach ($rows as $r) {
    $host = (string)$r['host'];
    $key  = 'line_alert:seen:' . md5($host . '|' . (string)$r['region'] . '|' . (string)$r['isp']);
    $seen = (int)kv_get($key, '0');
    $fail = (int)$r['fail_count'];
    if ($seen === 0) { kv_set($key, (string)$fail); continue; }      // 首次：只记基线
    $delta = $fail - $seen;
    if ($delta <= 0) continue;                                       // 没有新增
    kv_set($key, (string)$fail);                                     // 基线往前走（陈旧数据也不补报）
    $lastTs = strtotime((string)$r['last_at']);
    if ($lastTs !== false && $lastTs < $freshCut) continue;           // 不是最近的活动，忽略
    $byHost[$host]['delta'] = ($byHost[$host]['delta'] ?? 0) + $delta;
    $byHost[$host]['last']  = max((int)($byHost[$host]['last'] ?? 0), (int)($lastTs ?: 0));
    $byHost[$host]['regions'][] = [
        'region' => (string)$r['region'], 'isp' => (string)$r['isp'],
        'delta' => $delta, 'fail' => $fail,
        'reason' => (string)$r['last_reason'], 'at' => (string)$r['last_at'],
    ];
}

$fired = 0;
foreach ($byHost as $host => $info) {
    if ((int)$info['delta'] < $threshold) continue;
    if (!notify_once('line_alert:' . $host, $dedupeSec)) continue;

    $codes = 0;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM service_codes WHERE enabled = 1 AND link LIKE ?');
        $st->execute(['%' . $host . '%']);
        $codes = (int)$st->fetchColumn();
    } catch (\Throwable $e) {
    }

    $regs = $info['regions'];
    usort($regs, fn($a, $b) => $b['delta'] <=> $a['delta']);
    $html = "📡 <b>线路告警：访客大面积进不去</b>\n"
        . '主机：' . htmlspecialchars($host) . "\n"
        . "近 {$windowMin} 分钟新增失败 <b>{$info['delta']}</b> 次（阈值 {$threshold}）\n"
        . '这台主机上在用客服码：' . $codes . " 条\n";
    foreach (array_slice($regs, 0, 6) as $g) {
        $html .= '· ' . htmlspecialchars(trim($g['region'] . ' ' . $g['isp']))
            . " 失败 {$g['delta']} 次（累计 {$g['fail']}）\n";
    }
    $html .= '最近原因：' . htmlspecialchars($regs[0]['reason'] !== '' ? $regs[0]['reason'] : '—')
        . '（' . htmlspecialchars($regs[0]['at']) . "）\n"
        . '→ 去后台「线路体检」看明细；确认不行就把该主机的域名在「域名池」里停用。';

    $n = tg_notify_admins($html);
    $fired++;
    echo date('Y-m-d H:i:s'), " 告警 {$host} 新增 {$info['delta']} 次 → 送达 {$n} 条\n";
}

echo date('Y-m-d H:i:s'), ' 完成：共看 ' . count($rows) . ' 行 / ' . count($byHost) . " 台主机有新增失败；发告警 {$fired} 条\n";
